import qrcode from './vendor/qrcode.mjs';

const STYLE_KEY = 'shortly.qr';
const DEFAULT_STYLE = { fg: '#181613', bg: '#ffffff', dot: 'square' };

export function getQRStyle() {
  try {
    const raw = localStorage.getItem(STYLE_KEY);
    if (!raw) return { ...DEFAULT_STYLE };
    const parsed = JSON.parse(raw);
    return {
      fg:  /^#[0-9a-fA-F]{6}$/.test(parsed.fg)  ? parsed.fg  : DEFAULT_STYLE.fg,
      bg:  /^#[0-9a-fA-F]{6}$/.test(parsed.bg)  ? parsed.bg  : DEFAULT_STYLE.bg,
      dot: ['square', 'round'].includes(parsed.dot) ? parsed.dot : DEFAULT_STYLE.dot,
    };
  } catch { return { ...DEFAULT_STYLE }; }
}

export function setQRStyle(style) {
  const merged = { ...getQRStyle(), ...style };
  try { localStorage.setItem(STYLE_KEY, JSON.stringify(merged)); } catch {}
  return merged;
}

export function renderQR(canvas, text, size = 220, style) {
  const s = style || getQRStyle();
  const qr = qrcode(0, 'M');
  qr.addData(text);
  qr.make();
  const count = qr.getModuleCount();
  const ctx = canvas.getContext('2d');
  const dpr = Math.max(1, window.devicePixelRatio || 1);

  canvas.width = size * dpr;
  canvas.height = size * dpr;
  canvas.style.width = size + 'px';
  canvas.style.height = size + 'px';

  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  ctx.fillStyle = s.bg;
  ctx.fillRect(0, 0, size, size);

  const padding = 8;
  const inner = size - padding * 2;
  const cell = inner / count;

  ctx.fillStyle = s.fg;
  for (let r = 0; r < count; r++) {
    for (let c = 0; c < count; c++) {
      if (!qr.isDark(r, c)) continue;
      const x = padding + c * cell;
      const y = padding + r * cell;
      if (s.dot === 'round') {
        const radius = cell / 2;
        ctx.beginPath();
        ctx.arc(x + radius, y + radius, radius, 0, Math.PI * 2);
        ctx.fill();
      } else {
        ctx.fillRect(x, y, cell + 0.5, cell + 0.5);
      }
    }
  }
}
