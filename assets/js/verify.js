import { api, url, bindThemeToggle, toast } from './common.js';

bindThemeToggle(document.getElementById('theme-toggle'));

const L = window.LANG === 'sv' ? {
  err_invalid_code: 'Fel kod.',
  err_code_format:  'Koden ska vara 6 siffror.',
  err_email:        'Ogiltig e-postadress.',
  err_required:     'Fyll i e-post och kod.',
  err_expired:      'Koden har gått ut. Registrera dig igen.',
  err_too_many:     'För många försök. Registrera dig igen.',
  err_rate:         'För många försök. Vänta en stund.',
  err_generic:      'Något gick fel. Försök igen.',
  resent:           'Ny kod skickad om e-posten finns registrerad.',
} : {
  err_invalid_code: 'Wrong code.',
  err_code_format:  'The code must be 6 digits.',
  err_email:        'That doesn\'t look like a valid email.',
  err_required:     'Enter both email and code.',
  err_expired:      'The code has expired. Please register again.',
  err_too_many:     'Too many attempts. Please register again.',
  err_rate:         'Too many requests. Wait a moment.',
  err_generic:      'Something went wrong. Try again.',
  resent:           'A new code has been sent if the email is registered.',
};

const errorMessages = {
  invalid_code:             L.err_invalid_code,
  invalid_email:            L.err_email,
  email_and_code_required:  L.err_required,
  expired:                  L.err_expired,
  too_many_attempts:        L.err_too_many,
  rate_limited:             L.err_rate,
};

const errorEl  = document.getElementById('auth-error');
const infoEl   = document.getElementById('auth-info');
const form     = document.getElementById('verify-form');
const submitEl = document.getElementById('verify-submit');
const emailEl  = document.getElementById('email');
const codeEl   = document.getElementById('code');

function showError(msg) {
  errorEl.textContent = msg;
  errorEl.classList.add('show');
  infoEl.classList.remove('show');
}
function showInfo(msg) {
  infoEl.textContent = msg;
  infoEl.classList.add('show');
  errorEl.classList.remove('show');
}

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  errorEl.classList.remove('show');
  infoEl.classList.remove('show');

  const email = emailEl.value.trim().toLowerCase();
  const code  = codeEl.value.trim();
  if (!/^\d{6}$/.test(code)) { showError(L.err_code_format); return; }

  submitEl.disabled = true;
  try {
    await api('/api/auth/verify', {
      method: 'POST',
      body: JSON.stringify({ email, code }),
    });
    location.href = url('/app');
  } catch (err) {
    showError(errorMessages[err.message] || L.err_generic);
    submitEl.disabled = false;
  }
});

document.getElementById('resend-link').addEventListener('click', async (e) => {
  e.preventDefault();
  const email = emailEl.value.trim().toLowerCase();
  if (!email) { showError(L.err_email); return; }
  try {
    await api('/api/auth/resend', {
      method: 'POST',
      body: JSON.stringify({ email }),
    });
    showInfo(L.resent);
  } catch (err) {
    showError(errorMessages[err.message] || L.err_generic);
  }
});
