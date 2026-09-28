<?php
declare(strict_types=1);

namespace ExamLegacy;

/**
 * Study AI — Gemini-powered, scoped to AUTHORIZED content only.
 *
 * Authorized sources:
 *   1. A PDF the user has PURCHASED (ownership re-verified server-side).
 *   2. A file the user UPLOADED themselves.
 *   3. Free text the user pastes (their own input).
 *
 * It can NEVER reach another user's files, unpublished PDFs, arbitrary URLs or
 * the raw server directory. Credits are deducted server-side after success.
 */
final class StudyAi
{
    private const MAX_INLINE_BYTES = 15 * 1024 * 1024; // keep within model inline limits

    /** Supported study tasks -> concise system guidance. */
    private static function systemFor(string $task): string
    {
        $base = 'You are ExamLegacy Study AI, a brilliant all-in-one study companion for Indian competitive-exam students (NEET, JEE, Boards, UPSC and more). '
              . 'You can answer ANY study-related question — any subject, any topic — even without attached material, drawing on your own knowledge accurately and thoroughly. '
              . 'When study material IS supplied, answer strictly from it; if the answer is not in the material, say so and then give the correct general answer. '
              . 'Use clear structure, headings, and step-by-step reasoning. Prefer the language of the question.';
        $extra = [
            'concept'    => 'Explain the concept simply with an example and a one-line summary.',
            'solve'      => 'Solve the question step by step and state the final answer clearly.',
            'mcq'        => 'Create exam-style MCQs from the material with the correct option and a short explanation.',
            'short'      => 'Write a concise short answer.',
            'long'       => 'Write a well-structured long answer with headings.',
            'exam'       => 'Write a model exam answer worth full marks, with points and a conclusion.',
            'notes'      => 'Produce clean, revision-ready notes with bullet points.',
            'revision'   => 'Give a quick-revision summary of the key points only.',
            'flashcards' => 'Produce flashcards as Q/A pairs, one per line, prefixed with Q: and A:.',
            'mock'       => 'Generate a short mock test with questions and an answer key at the end.',
            'weak'       => 'Identify likely weak topics from the material and suggest a focused revision plan.',
            'analyze'    => 'Analyze performance patterns and give actionable improvement steps.',
        ];
        return $base . ' ' . ($extra[$task] ?? $extra['concept']);
    }

    /**
     * @param array $source ['type'=>'purchased','product_id'=>int]
     *                     | ['type'=>'upload','document_id'=>int]
     *                     | ['type'=>'text','text'=>string]
     *                     | [] / general types -> all-in-one chat (no material)
     * @param array $history prior chat turns [{role:'user'|'model', text:string}] oldest first
     * @return array{answer:string,credits_spent:int,balance:int,usage_id:int,task:string}
     */
    public static function ask(int $userId, array $source, string $question, string $task = 'concept', array $history = []): array
    {
        $question = trim($question);
        if ($question === '') {
            throw new ApiError('invalid_input', 'Please enter a question', 400);
        }
        if (mb_strlen($question) > 4000) {
            throw new ApiError('invalid_input', 'Question is too long', 400);
        }
        $allowedTasks = ['concept','solve','mcq','short','long','exam','notes','revision','flashcards','mock','weak','analyze'];
        if (!in_array($task, $allowedTasks, true)) {
            $task = 'concept';
        }

        // Resolve the authorized source into model parts. No source (or an
        // explicit "general" request) means all-in-one mode: the tutor answers
        // from its own knowledge — a purchase is NEVER required to ask.
        $type = strtolower(trim((string) ($source['type'] ?? '')));
        $general = in_array($type, ['', 'none', 'general', 'chat', 'ask', 'any'], true);
        if ($general) {
            [$parts, $meta] = [[], ['label' => 'general']];
        } else {
            [$parts, $meta] = self::resolveSource($userId, $source);
        }
        $parts[] = Gemini::textPart($question);

        $cost = max(0, (int) Settings::get('ai_credit_cost_study', '2'));
        // Pre-check balance to give a clean, immediate message (authoritative spend happens after).
        if (AiCredits::balance($userId) < $cost) {
            throw new ApiError('insufficient_credits', 'Not enough AI credits. Recharge or upgrade to VIP.', 402);
        }

        // Record the usage attempt first (so we can link the credit deduction).
        Db::run(
            'INSERT INTO ai_usage (user_id, kind, document_id, product_id, credits_spent, status, meta)
             VALUES (?,?,?,?,?,?,?)',
            [
                $userId, 'study',
                $meta['document_id'] ?? null,
                $meta['product_id'] ?? null,
                $cost, 'ok',
                json_encode(['task' => $task] + $meta, JSON_UNESCAPED_UNICODE),
            ]
        );
        $usageId = Db::insertId();

        try {
            $result = Gemini::generateContent($parts, self::systemFor($task), null, $history);
        } catch (ApiError $e) {
            Db::run('UPDATE ai_usage SET status = \'error\' WHERE id = ?', [$usageId]);
            throw $e;
        }
        if ($result['text'] === '') {
            Db::run('UPDATE ai_usage SET status = \'error\' WHERE id = ?', [$usageId]);
            throw new ApiError('ai_empty', 'The AI returned no content. Please try rephrasing.', 502);
        }

        Db::run(
            'UPDATE ai_usage SET input_tokens = ?, output_tokens = ? WHERE id = ?',
            [$result['input_tokens'], $result['output_tokens'], $usageId]
        );

        // Deduct credits server-side (atomic + idempotent by usage id).
        $balance = AiCredits::spend($userId, $cost, $usageId, 'Study AI: ' . $task);

        return [
            'answer'        => $result['text'],
            'task'          => $task,
            'credits_spent' => $cost,
            'balance'       => $balance,
            'usage_id'      => $usageId,
            'source'        => $meta['label'] ?? 'text',
        ];
    }

    /**
     * Resolve a source into Gemini parts, enforcing authorization.
     * @return array{0:array,1:array}
     */
    private static function resolveSource(int $userId, array $source): array
    {
        $type = strtolower(trim((string) ($source['type'] ?? 'text')));
        // Synonym resolution: clients (old and new) use different names for
        // the same authorized source. Map them transparently — never 400 on
        // a vocabulary mismatch when the underlying authorization is sound.
        $type = [
            'library'            => 'purchased',
            'purchased'          => 'purchased',
            'product'            => 'purchased',
            'purchased_pdf'      => 'purchased',
            'my_pdfs'            => 'purchased',
            'pdf'                => 'purchased',
            'upload'             => 'upload',
            'uploads'            => 'upload',
            'document'           => 'upload',
            'file'               => 'upload',
            'text'               => 'text',
            'paste'              => 'text',
            'free'               => 'text',
        ][$type] ?? $type;

        if ($type === 'purchased') {
            $productId = (int) ($source['product_id'] ?? 0);
            if ($productId <= 0 || !PdfVault::hasAccess($userId, $productId)) {
                throw new ApiError('no_access', 'You do not own this document', 403);
            }
            $product = Db::one('SELECT id, title, pdf_path FROM products WHERE id = ?', [$productId]);
            if ($product === null) {
                throw new ApiError('not_found', 'Document not found', 404);
            }
            $file = PdfVault::resolveFilePath((string) $product['pdf_path']);
            if ($file === null) {
                throw new ApiError('not_found', 'Document file unavailable', 404);
            }
            $parts = self::documentParts($file, 'application/pdf', (string) $product['title']);
            return [$parts, ['product_id' => $productId, 'label' => 'purchased:' . $product['title']]];
        }

        if ($type === 'upload') {
            $docId = (int) ($source['document_id'] ?? 0);
            $doc = Db::one('SELECT * FROM ai_documents WHERE id = ? AND user_id = ? AND kind = \'upload\'', [$docId, $userId]);
            if ($doc === null) {
                throw new ApiError('not_found', 'Uploaded document not found', 404);
            }
            $path = UPLOADS_DIR . '/' . ltrim((string) $doc['storage_path'], '/');
            $real = realpath($path);
            $base = realpath(UPLOADS_DIR);
            if ($real === false || $base === false || strncmp($real, $base, strlen($base)) !== 0 || !is_file($real)) {
                throw new ApiError('not_found', 'Uploaded file unavailable', 404);
            }
            $parts = self::documentParts($real, (string) $doc['mime'], (string) $doc['filename']);
            return [$parts, ['document_id' => $docId, 'label' => 'upload:' . $doc['filename']]];
        }

        // Free text (user's own input).
        $text = (string) ($source['text'] ?? '');
        if (trim($text) === '') {
            throw new ApiError('invalid_input', 'No study content provided', 400);
        }
        return [[Gemini::textPart(mb_substr($text, 0, 20000))], ['label' => 'text']];
    }

    /** Build model parts for a document: inline if small, else extracted text. */
    private static function documentParts(string $file, string $mime, string $title): array
    {
        $size = (int) filesize($file);
        $parts = [Gemini::textPart('Study material: ' . $title)];
        if ($size <= self::MAX_INLINE_BYTES) {
            $bytes = file_get_contents($file);
            $parts[] = Gemini::inlinePart($mime, base64_encode((string) $bytes));
        } else {
            $text = str_ends_with(strtolower($mime), 'pdf') ? PdfText::extract($file) : '';
            if ($text === '') {
                throw new ApiError('doc_too_large', 'This document is too large to process. Please upload a smaller file.', 413);
            }
            $parts[] = Gemini::textPart('Extracted study material (truncated): ' . $text);
        }
        return $parts;
    }
}
