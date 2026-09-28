#!/usr/bin/env python3
"""
Generate ExamLegacy PWA icons (pure Python, no PIL/GD).

Rasterizes a faithful version of assets/img/mark.svg — dark badge, glowing
blue→cyan ring, geometric E, document + growth chart — at arbitrary sizes with
3x3 supersampling for clean anti-aliasing.

Outputs (written next to the SVG assets):
  assets/img/icon-192.png
  assets/img/icon-512.png
  assets/img/icon-maskable-512.png   (full-bleed, content scaled into 80% safe zone)
  assets/img/apple-touch-icon.png    (180x180, full-bleed, no transparency)
"""
import math
import os
import struct
import zlib

# ---------------------------------------------------------------- colors ----
def lerp(a, b, t):
    t = max(0.0, min(1.0, t))
    return tuple(a[i] + (b[i] - a[i]) * t for i in range(3))

def lerp3(a, b, c, t):
    return lerp(a, b, t * 2) if t < 0.5 else lerp(b, c, (t - 0.5) * 2)

def hx(s):
    s = s.lstrip("#")
    return tuple(int(s[i:i + 2], 16) for i in (0, 2, 4))

RING_A, RING_B, RING_C = hx("1d4ed8"), hx("0ea5e9"), hx("22d3ee")
E_A, E_B, E_C = hx("2563eb"), hx("38bdf8"), hx("67e8f9")
GROW_A, GROW_B = hx("0284c7"), hx("67e8f9")
DOC_A, DOC_B = hx("0a1130"), hx("132352")
BG_A, BG_B = hx("15151b"), hx("05050a")
FOLD = hx("0ea5e9")
CYAN = hx("67e8f9")

# ------------------------------------------------------- design geometry ----
# All in [0,1] design units (the 512px mark.svg coordinate system / 512).
CX = CY_ = 0.5
BADGE_R = 234 / 512
RING_HW = 7 / 512

E_RECTS = [  # (x0, y0, x1, y1) — simplified geometric E (chamfers dropped)
    (118 / 512, 140 / 512, 172 / 512, 344 / 512),   # spine
    (172 / 512, 140 / 512, 318 / 512, 196 / 512),   # top arm
    (172 / 512, 216 / 512, 288 / 512, 266 / 512),   # middle arm
    (172 / 512, 290 / 512, 328 / 512, 344 / 512),   # bottom arm
]

DOC = (316 / 512, 158 / 512, 422 / 512, 340 / 512)
DOC_R = 16 / 512
DOC_STROKE = 4 / 512

FOLD_TRI = ((388 / 512, 158 / 512), (422 / 512, 192 / 512), (390 / 512, 192 / 512))

BARS = [  # (x0, y0, x1, y1) in design units
    (332 / 512, 268 / 512, 349 / 512, 314 / 512),
    (357 / 512, 246 / 512, 374 / 512, 314 / 512),
    (382 / 512, 222 / 512, 399 / 512, 314 / 512),
]

ARROW_PTS = [(330 / 512, 262 / 512), (356 / 512, 236 / 512),
             (378 / 512, 244 / 512), (404 / 512, 198 / 512)]
ARROW_HW = 5.5 / 512
ARROW_HEAD = ((414 / 512, 180 / 512), (413 / 512, 208 / 512), (391 / 512, 194 / 512))

# ------------------------------------------------------------- primitives ---
def in_rect(x, y, r):
    return r[0] <= x <= r[2] and r[1] <= y <= r[3]

def in_rounded_rect(x, y, r, rad):
    x0, y0, x1, y1 = r
    if not (x0 <= x <= x1 and y0 <= y <= y1):
        return False
    # corner circles
    for cx in (x0 + rad, x1 - rad):
        for cy in (y0 + rad, y1 - rad):
            if (x < x0 + rad or x > x1 - rad) and (y < y0 + rad or y > y1 - rad):
                if math.hypot(x - cx, y - cy) > rad:
                    return False
    return True

def dist_segment(px, py, ax, ay, bx, by):
    vx, vy = bx - ax, by - ay
    wx, wy = px - ax, py - ay
    vv = vx * vx + vy * vy
    t = 0.0 if vv == 0 else max(0.0, min(1.0, (wx * vx + wy * vy) / vv))
    return math.hypot(px - (ax + t * vx), py - (ay + t * vy))

def in_triangle(px, py, a, b, c):
    def sign(p1, p2, p3):
        return (p1[0] - p3[0]) * (p2[1] - p3[1]) - (p2[0] - p3[0]) * (p1[1] - p3[1])
    d1, d2, d3 = sign((px, py), a, b), sign((px, py), b, c), sign((px, py), c, a)
    neg = (d1 < 0) or (d2 < 0) or (d3 < 0)
    pos = (d1 > 0) or (d2 > 0) or (d3 > 0)
    return not (neg and pos)

# ------------------------------------------------------------ composition ---
def sample_ordered(ux, uy, mode, scale):
    """Correct layer order: badge bg → ring → E → doc → fold → bars → arrow."""
    dx = ux / scale + 0.5
    dy = uy / scale + 0.5
    dbx, dby = dx - 0.5, dy - 0.5
    dist = math.hypot(dbx, dby)

    if mode in ("maskable", "apple"):
        color = lerp(BG_A, BG_B, min(1.0, dist / 0.7))
        has = True
    elif mode == "badge":
        if dist > BADGE_R + RING_HW:
            return (0, 0, 0, 0)
        color, has = (0, 0, 0), False
        if dist <= BADGE_R:
            color = lerp(BG_A, BG_B, min(1.0, dist / BADGE_R))
            has = True
        if abs(dist - BADGE_R) <= RING_HW:
            t = (dbx - dby + 2 * BADGE_R) / (4 * BADGE_R)
            color, has = lerp3(RING_A, RING_B, RING_C, t), True
    else:
        color, has = (0, 0, 0), False

    if not (0.0 <= dx <= 1.0 and 0.0 <= dy <= 1.0):
        return (*[int(round(c)) for c in color], 255) if has else (0, 0, 0, 0)

    # 1) Geometric E
    for r in E_RECTS:
        if in_rect(dx, dy, r):
            t = ((dx - 0.5) + (0.5 - dy)) / 0.9
            color, has = lerp3(E_A, E_B, E_C, max(0.0, min(1.0, t))), True
            break

    # 2) Document stroke
    stroke_rect = (DOC[0] - DOC_STROKE, DOC[1] - DOC_STROKE,
                   DOC[2] + DOC_STROKE, DOC[3] + DOC_STROKE)
    if in_rounded_rect(dx, dy, stroke_rect, DOC_R + DOC_STROKE):
        t = (dbx - dby + 2 * BADGE_R) / (4 * BADGE_R)
        color, has = lerp3(RING_A, RING_B, RING_C, t), True

    # 3) Document fill
    if in_rounded_rect(dx, dy, DOC, DOC_R):
        color = lerp(DOC_A, DOC_B, ((dx - DOC[0]) + (dy - DOC[1])) /
                     ((DOC[2] - DOC[0]) + (DOC[3] - DOC[1])))
        has = True

    # 4) Fold
    if in_triangle(dx, dy, *FOLD_TRI):
        color, has = FOLD, True

    # 5) Bars
    for b in BARS:
        if in_rect(dx, dy, b):
            t = 1.0 - (dy - b[1]) / (b[3] - b[1])
            color = lerp(GROW_A, GROW_B, max(0.0, min(1.0, t)))
            has = True

    # 6) Arrow
    for i in range(len(ARROW_PTS) - 1):
        a, b = ARROW_PTS[i], ARROW_PTS[i + 1]
        if dist_segment(dx, dy, a[0], a[1], b[0], b[1]) <= ARROW_HW:
            color, has = CYAN, True
    if in_triangle(dx, dy, *ARROW_HEAD):
        color, has = CYAN, True

    return (*[int(round(c)) for c in color], 255) if has else (0, 0, 0, 0)

# ------------------------------------------------------------------ PNG IO --
def write_png(path, size, rows):
    def chunk(tag, data):
        return (struct.pack(">I", len(data)) + tag + data +
                struct.pack(">I", zlib.crc32(tag + data) & 0xFFFFFFFF))
    raw = b"".join(b"\x00" + bytes(r) for r in rows)
    png = (b"\x89PNG\r\n\x1a\n" +
           chunk(b"IHDR", struct.pack(">IIBBBBB", size, size, 8, 6, 0, 0, 0)) +
           chunk(b"IDAT", zlib.compress(raw, 9)) +
           chunk(b"IEND", b""))
    with open(path, "wb") as f:
        f.write(png)

def render(size, mode, scale, out):
    ss = 3  # 3x3 supersampling
    inv = 1.0 / (size * ss)
    rows = []
    for py in range(size):
        row = bytearray(size * 4)
        for px in range(size):
            r = g = b = a = 0
            for sy in range(ss):
                for sx in range(ss):
                    ux = ((px * ss + sx) + 0.5) * inv - 0.5
                    uy = ((py * ss + sy) + 0.5) * inv - 0.5
                    sr, sg, sb, sa = sample_ordered(ux, uy, mode, scale)
                    r += sr * sa; g += sg * sa; b += sb * sa; a += sa
            n = ss * ss
            if a > 0:
                row[px * 4:px * 4 + 4] = bytes((min(255, r // a), min(255, g // a),
                                                 min(255, b // a), a // n))
            # else stays (0,0,0,0)
        rows.append(row)
    write_png(out, size, rows)
    print(f"wrote {out} ({size}x{size}, {mode})")

def main():
    here = os.path.dirname(os.path.abspath(__file__))
    img = os.path.join(here, "..", "assets", "img")
    render(192, "badge", 1.0, os.path.join(img, "icon-192.png"))
    render(512, "badge", 1.0, os.path.join(img, "icon-512.png"))
    render(512, "maskable", 0.78, os.path.join(img, "icon-maskable-512.png"))
    render(180, "apple", 0.82, os.path.join(img, "apple-touch-icon.png"))

if __name__ == "__main__":
    main()
