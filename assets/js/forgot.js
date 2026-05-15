import { api, url, bindThemeToggle, toast } from './common.js';

bindThemeToggle(document.getElementById('theme-toggle'));

const L = window.LANG === 'sv' ? {
  err_email:    'Ogiltig e-postadress.',
  err_rate:     'För många försök. Vänta en stund.',
  err_generic:  'Något gick fel. Försök igen.',
  sent:         'Om e-posten finns hos oss har vi skickat en länk. Kolla inboxen (och spam).',
} : {
  err_email:    'That doesn\'t look like a valid email.',
  err_rate:     'Too many requests. Wait a moment.',
  err_generic:  'Something went wrong. Try again.',
  sent:         'If we have that email, we\'ve sent you a link. Check your inbox (and spam).',
};

const errorMessages = {
  invalid_email: L.err_email,
  rate_limited:  L.err_rate,
};

const errorEl  = document.getElementById('auth-error');
const infoEl   = document.getElementById('auth-info');
const form     = document.getElementById('forgot-form');
const submitEl = document.getElementById('forgot-submit');
const emailEl  = document.getElementById('email');

function showError(msg) { errorEl.textContent = msg; errorEl.classList.add('show'); infoEl.classList.remove('show'); }
function showInfo(msg)  { infoEl.textContent = msg;  infoEl.classList.add('show');  errorEl.classList.remove('show'); }

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  errorEl.classList.remove('show');
  infoEl.classList.remove('show');

  const email = emailEl.value.trim().toLowerCase();
  submitEl.disabled = true;
  try {
    await api('/api/auth/forgot', {
      method: 'POST',
      body: JSON.stringify({ email }),
    });
    showInfo(L.sent);
    // Don't unlock the button — re-submitting from this state would only
    // trip the per-email rate limit anyway.
  } catch (err) {
    showError(errorMessages[err.message] || L.err_generic);
    submitEl.disabled = false;
  }
});
