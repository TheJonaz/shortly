// Shared utilities: theme, toast, API client, base path resolution.
//
// BASE is inferred from this module's URL by stripping /assets/js/<file>.
// At /assets/js/common.js → BASE = "". At /sub/assets/js/common.js → BASE = "/sub".

const here = new URL(import.meta.url);
export const BASE = here.pathname.replace(/\/assets\/js\/[^/]+$/, '') || '';

export const url = (p = '') => BASE + (p.startsWith('/') ? p : '/' + p);

export async function api(path, opts = {}) {
  const res = await fetch(url(path), {
    credentials: 'same-origin',
    headers: { 'content-type': 'application/json', ...(opts.headers || {}) },
    ...opts,
  });
  let body = null;
  try { body = await res.json(); } catch {}
  if (!res.ok) {
    const err = new Error((body && body.error) || res.statusText || 'request_failed');
    err.status = res.status;
    err.body = body;
    throw err;
  }
  return body;
}

// ─── theme ──────────────────────────────────────────────────────────
const THEME_KEY = 'shortly.theme';
export function applyTheme(t) {
  document.documentElement.dataset.theme = t;
  localStorage.setItem(THEME_KEY, t);
}
export function initTheme() {
  // Light by default; only honour an explicit saved preference.
  const saved = localStorage.getItem(THEME_KEY);
  applyTheme(saved || 'light');
}
export function bindThemeToggle(btn) {
  if (!btn) return;
  btn.addEventListener('click', () => {
    applyTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');
  });
}

// ─── toast ──────────────────────────────────────────────────────────
let toastEl;
export function toast(msg, kind = 'info') {
  if (!toastEl) {
    toastEl = document.createElement('div');
    toastEl.className = 'toast';
    document.body.appendChild(toastEl);
  }
  toastEl.textContent = msg;
  toastEl.classList.toggle('error', kind === 'error');
  toastEl.classList.add('show');
  clearTimeout(toast._t);
  toast._t = setTimeout(() => toastEl.classList.remove('show'), 2400);
}

// ─── time helpers ───────────────────────────────────────────────────
export function timeAgo(ts) {
  if (!ts) return '—';
  const d = (Date.now() - ts) / 1000;
  if (d < 60) return 'just now';
  if (d < 3600) return Math.floor(d / 60) + 'm ago';
  if (d < 86400) return Math.floor(d / 3600) + 'h ago';
  if (d < 86400 * 30) return Math.floor(d / 86400) + 'd ago';
  return new Date(ts).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

export function formatDateTime(ts) {
  if (!ts) return '—';
  return new Date(ts).toLocaleString(undefined, {
    year: 'numeric', month: 'short', day: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });
}

// ─── copy ───────────────────────────────────────────────────────────
export async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text);
    toast('Copied');
    return true;
  } catch {
    toast('Copy failed', 'error');
    return false;
  }
}

// ─── boot ───────────────────────────────────────────────────────────
initTheme();
