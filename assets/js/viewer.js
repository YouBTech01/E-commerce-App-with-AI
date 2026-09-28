/* ExamLegacy — secure in-app PDF viewer.
   - Requests a short-lived viewer session from the server (ownership verified).
   - Streams bytes via /api/viewer/stream using the session token (X-Viewer-Token).
   - Renders with PDF.js from an in-memory blob — NO public URL is ever exposed.
   - Overlays a dynamic purchaser watermark and deters copy/print/download. */
window.EL = window.EL || {};

EL.viewer = (function () {
  var PDFJS_SRC = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
  var WORKER_SRC = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
  var pdfjsPromise = null;

  function ensurePdfJs() {
    if (pdfjsPromise) return pdfjsPromise;
    pdfjsPromise = new Promise(function (resolve, reject) {
      if (window.pdfjsLib) { window.pdfjsLib.GlobalWorkerOptions.workerSrc = WORKER_SRC; return resolve(window.pdfjsLib); }
      var s = document.createElement('script');
      s.src = PDFJS_SRC;
      s.onload = function () {
        if (!window.pdfjsLib) return reject(new Error('PDF.js failed to load'));
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = WORKER_SRC;
        resolve(window.pdfjsLib);
      };
      s.onerror = function () { reject(new Error('Could not load the PDF engine')); };
      document.head.appendChild(s);
    });
    return pdfjsPromise;
  }

  function overlay() {
    var el = document.createElement('div');
    el.className = 'viewer';
    el.innerHTML =
      '<div class="vbar">' +
        '<button class="icon-btn" data-close aria-label="Close">' + EL.ui.icon('arrow-left') + '</button>' +
        '<div class="title">Loading…</div>' +
        '<span class="badge blue tiny hide" data-dl>' + EL.ui.icon('shield-halved') + ' Protected</span>' +
      '</div>' +
      '<div class="stage">' +
        '<div class="pages"></div>' +
        '<div class="vloading"><div class="spinner"></div><div class="small">Opening secure document…</div></div>' +
      '</div>';
    document.body.appendChild(el);
    EL.qs('[data-close]', el).addEventListener('click', close);
    // Deterrence: block context menu and common save/print shortcuts inside viewer.
    el.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    el.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && ['p', 's'].indexOf(e.key.toLowerCase()) !== -1) { e.preventDefault(); EL.ui.toast('Download/print is restricted for this document', 'err'); }
    });
    return el;
  }

  function close() { var v = EL.qs('.viewer'); if (v) v.remove(); }

  async function open(productId, title) {
    var el = overlay();
    try {
      var session = await EL.api.post('/viewer/session', { product_id: productId });
      var s = session.session;
      EL.qs('.title', el).textContent = title || s.product.title;

      var res = await fetch(EL.API_BASE + '/viewer/stream', { headers: { 'X-Viewer-Token': s.viewer_token } });
      if (!res.ok) {
        var j = await res.json().catch(function () { return {}; });
        throw new Error((j.error && j.error.message) || 'Could not open document');
      }
      var buf = await res.arrayBuffer();
      var blob = new Blob([buf], { type: 'application/pdf' });
      var url = URL.createObjectURL(blob); // in-memory only, not shareable

      var pdfjs = await ensurePdfJs();
      var pdf = await pdfjs.getDocument({ data: new Uint8Array(buf) }).promise;

      // Dynamic purchaser watermark.
      var wm = document.createElement('div');
      wm.className = 'watermark';
      wm.textContent = 'Licensed to ' + (s.watermark.name || 'user') + ' · ' + (s.watermark.email || '') +
        ' · ID ' + s.watermark.userId + '\nIssued ' + new Date(s.watermark.issuedAt).toLocaleString() +
        '\nExamLegacy — do not redistribute';
      var stage = EL.qs('.stage', el);
      stage.style.position = 'relative';
      stage.appendChild(wm);

      var pagesHost = EL.qs('.pages', el);
      EL.qs('.vloading', el).remove();

      var scale = Math.min(1.6, Math.max(1, (window.innerWidth - 48) / 612));
      for (var i = 1; i <= pdf.numPages; i++) {
        var page = await pdf.getPage(i);
        var viewport = page.getViewport({ scale: scale });
        var canvas = document.createElement('canvas');
        canvas.width = viewport.width; canvas.height = viewport.height;
        pagesHost.appendChild(canvas);
        await page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise;
      }

      // Controlled download policy.
      if (s.product.download_allowed) {
        var dl = EL.qs('[data-dl]', el);
        dl.classList.remove('hide');
        dl.style.cursor = 'pointer';
        dl.addEventListener('click', function () {
          var a = document.createElement('a');
          a.href = url; a.download = (title || 'document') + '.pdf'; a.click();
        });
      }
    } catch (e) {
      EL.qs('.vloading', el).innerHTML = '<div class="empty"><div class="ic">' + EL.ui.icon('lock') + '</div>' +
        '<h3>Cannot open document</h3><p>' + EL.esc(e.message) + '</p>' +
        '<button class="btn soft" data-close2>Go back</button></div>';
      var b = EL.qs('[data-close2]', el); if (b) b.addEventListener('click', close);
    }
  }

  return { open: open, close: close };
})();
