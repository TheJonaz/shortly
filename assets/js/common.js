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
  if (!btn || btn.dataset.bound) return;
  // _theme_toggle.php ships its own inline click handler and marks the
  // button as bound; this guard prevents a second listener firing on
  // every click (two toggles cancel out and the theme looks stuck).
  btn.dataset.bound = '1';
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

// ─── post-auth landing ──────────────────────────────────────────────
// After /login or /verify succeeds, optionally chain straight into
// Stripe Checkout if the URL still carries the `next=upgrade&plan=…`
// hints set by a "Choose Pro" CTA on the landing page. Falls back to
// /app if no upgrade is wanted or the checkout call fails — never
// strands the user mid-flow.
export async function chainUpgradeOrApp() {
  const params = new URLSearchParams(location.search);
  if (params.get('next') === 'upgrade') {
    const plan = params.get('plan');
    if (plan === 'monthly' || plan === 'yearly') {
      try {
        const currency = window.CURRENCY || 'sek';
        const r = await api('/api/billing/checkout', {
          method: 'POST',
          body: JSON.stringify({ plan, currency }),
        });
        location.href = r.url;
        return;
      } catch {
        // Fall through to /app — user can retry the upgrade from there.
      }
    }
  }
  location.href = url('/app');
}

// ─── boot ───────────────────────────────────────────────────────────
initTheme();
