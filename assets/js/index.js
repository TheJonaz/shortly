import { api, BASE, url, bindThemeToggle, copyText, toast } from './common.js';
import { renderQR, getQRStyle, setQRStyle } from './qr.js';

// Rewrite any anchors marked with data-base-link to honor the BASE prefix.
for (const a of document.querySelectorAll('[data-base-link]')) {
  const target = a.dataset.baseLink;
  a.href = url(target);
}

bindThemeToggle(document.getElementById('theme-toggle'));

// Show "Dashboard" instead of "Sign in" when authenticated.
api('/api/me').then(({ user }) => {
  const navLogin = document.getElementById('nav-login');
  if (user && navLogin) {
    navLogin.textContent = 'Dashboard';
    navLogin.href = url('/app');
  }
}).catch(() => {});

const form = document.getElementById('shorten-form');
const submitBtn = document.getElementById('submit-btn');
const resultEl = document.getElementById('result');
const resultShort = document.getElementById('result-short');
const resultTarget = document.getElementById('result-target');
const targetInput = document.getElementById('target');
const slugInput = document.getElementById('slug');
const expiryInput = document.getElementById('expires_at');
const pwInput = document.getElementById('password');
const pasteBtn = document.getElementById('paste-btn');
const copyBtn = document.getElementById('copy-btn');
const qrBtn = document.getElementById('qr-btn');
const newBtn = document.getElementById('new-btn');
const qrWrap = document.getElementById('qr-wrap');

let lastShort = null;
let adPushed = false;

// Reveal and request fill on the AdSense slot after the user has actually
// shortened a link. Only triggers once per page load — AdSense rejects a
// repeated push() against the same <ins> element.
function revealResultAd() {
  const ad = document.getElementById('result-ad');
  if (!ad || adPushed) return;
  ad.hidden = false;
  try { (window.adsbygoogle = window.adsbygoogle || []).push({}); } catch {}
  adPushed = true;
}

const errorMessages = {
  invalid_url: 'That doesn\'t look like a valid URL.',
  invalid_protocol: 'Only http:// and https:// links are accepted.',
  invalid_slug: 'Slugs must be 2–32 characters: letters, numbers, dash, underscore.',
  slug_taken: 'That slug is already in use — try another.',
  slug_reserved: 'That slug is reserved. Try a different one.',
  invalid_expiry: 'Couldn\'t read that expiry date.',
  expiry_in_past: 'Expiry must be in the future.',
  expiry_too_far: 'Expiry can\'t be more than 10 years out.',
  target_required: 'Enter a URL first.',
  url_too_long: 'That URL is too long (max 2048 characters).',
  password_too_long: 'Passwords are limited to 72 characters.',
  payload_too_large: 'The request was too large.',
  rate_limited: 'You\'ve hit the rate limit — wait a bit and try again.',
  // Anonymous tier — fields require signing in.
  slug_requires_account:     'Custom slugs need a free account. Sign up to use them.',
  password_requires_account: 'Password protection needs a free account. Sign up to use it.',
  expiry_requires_account:   'Custom expiry dates need a free account. Sign up to use them.',
  // Pro-only on registered accounts.
  password_requires_pro:     'Password protection requires Pro.',
  expiry_requires_pro:       'Custom expiry dates require Pro.',
  // Abuse / hardening.
  url_blocked:                    'That domain is blocked because it\'s known for phishing or malware.',
  url_blocked_threat:              'That URL is flagged as a threat by Google Safe Browsing.',
  url_ip_host_not_allowed:        'Raw IP addresses aren\'t allowed — use a domain name.',
  url_internal_host_not_allowed:  'That host looks internal — use a public domain.',
  target_is_self:                 'You can\'t shorten a link that points back at this service.',
  target_too_frequent:            'That URL has been shortened too many times recently — try again soon.',
  captcha_required:               'Bot check failed. Reload the page and try again.',
};

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  if (submitBtn.disabled) return;
  submitBtn.disabled = true;
  submitBtn.querySelector('span').textContent = 'Working…';

  const payload = {
    target: targetInput.value.trim(),
    slug: slugInput.value.trim() || undefined,
    password: pwInput.value || undefined,
    expires_at: expiryInput.value ? new Date(expiryInput.value).getTime() : undefined,
  };
  // Turnstile token (set by landing.php's onTurnstileToken callback when
  // the widget is configured). Server only requires it for anon callers
  // and only when turnstile is configured — extra key is harmless otherwise.
  if (window.__turnstileToken) {
    payload.turnstile_token = window.__turnstileToken;
  }

  try {
    const res = await api('/api/links', { method: 'POST', body: JSON.stringify(payload) });
    lastShort = res.short_url;
    resultShort.innerHTML = `<a href="${res.short_url}" target="_blank" rel="noopener">${res.short_url}</a>`;
    resultTarget.textContent = '→ ' + payload.target;
    resultEl.hidden = false;
    qrWrap.classList.remove('show');
    resultEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    revealResultAd();
  } catch (err) {
    const msg = errorMessages[err.message] || 'Something went wrong: ' + err.message;
    toast(msg, 'error');
  } finally {
    submitBtn.disabled = false;
    submitBtn.querySelector('span').textContent = 'Shorten';
  }
});

if (pasteBtn && navigator.clipboard && typeof navigator.clipboard.readText === 'function') {
  pasteBtn.hidden = false;
  pasteBtn.addEventListener('click', async () => {
    try {
      const text = (await navigator.clipboard.readText()).trim();
      if (!text) { targetInput.focus(); return; }
      targetInput.value = text;
      form.requestSubmit(submitBtn);
    } catch {
      targetInput.focus();
    }
  });
}

copyBtn.addEventListener('click', () => lastShort && copyText(lastShort));

function refreshQR() {
  const canvas = document.getElementById('qr');
  if (lastShort) renderQR(canvas, lastShort, 220);
}

function ensureQRControls() {
  if (document.getElementById('qr-controls')) return;
  const style = getQRStyle();
  const wrap = document.createElement('div');
  wrap.id = 'qr-controls';
  wrap.style.cssText =
    'display:flex;gap:12px;align-items:center;justify-content:center;' +
    'margin-top:10px;font-size:12px;opacity:.85;flex-wrap:wrap;';
  wrap.innerHTML = `
    <label style="display:flex;align-items:center;gap:6px;">
      <span>FG</span>
      <input type="color" id="qr-fg" value="${style.fg}" style="width:30px;height:24px;padding:0;border:0;background:transparent;cursor:pointer;">
    </label>
    <label style="display:flex;align-items:center;gap:6px;">
      <span>BG</span>
      <input type="color" id="qr-bg" value="${style.bg}" style="width:30px;height:24px;padding:0;border:0;background:transparent;cursor:pointer;">
    </label>
    <label style="display:flex;align-items:center;gap:6px;">
      <span>Dot</span>
      <select id="qr-dot" style="font:inherit;font-size:12px;padding:2px 6px;border:1px solid var(--rule,#ddd);border-radius:6px;background:transparent;color:inherit;">
        <option value="square"${style.dot === 'square' ? ' selected' : ''}>Square</option>
        <option value="round"${style.dot === 'round' ? ' selected' : ''}>Round</option>
      </select>
    </label>
  `;
  qrWrap.appendChild(wrap);
  wrap.querySelector('#qr-fg').addEventListener('input', e => { setQRStyle({ fg: e.target.value }); refreshQR(); });
  wrap.querySelector('#qr-bg').addEventListener('input', e => { setQRStyle({ bg: e.target.value }); refreshQR(); });
  wrap.querySelector('#qr-dot').addEventListener('change', e => { setQRStyle({ dot: e.target.value }); refreshQR(); });
}

qrBtn.addEventListener('click', () => {
  if (!lastShort) return;
  if (qrWrap.classList.contains('show')) {
    qrWrap.classList.remove('show');
    return;
  }
  refreshQR();
  ensureQRControls();
  qrWrap.classList.add('show');
});

newBtn.addEventListener('click', () => {
  resultEl.hidden = true;
  qrWrap.classList.remove('show');
  form.reset();
  lastShort = null;
  targetInput.focus();
});
