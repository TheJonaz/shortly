import { api, url, bindThemeToggle, toast } from './common.js';

bindThemeToggle(document.getElementById('theme-toggle'));

const L = window.LANG === 'sv' ? {
  err_weak:     'Lösenordet måste vara minst 8 tecken.',
  err_invalid:  'Länken är ogiltig eller har gått ut. Begär en ny.',
  err_used:     'Länken har redan använts. Begär en ny.',
  err_rate:     'För många försök. Vänta en stund.',
  err_generic:  'Något gick fel. Försök igen.',
  done:         'Klart. Du kan nu logga in med ditt nya lösenord.',
} : {
  err_weak:     'Password must be at least 8 characters.',
  err_invalid:  'This link is invalid or has expired. Request a new one.',
  err_used:     'This link has already been used. Request a new one.',
  err_rate:     'Too many requests. Wait a moment.',
  err_generic:  'Something went wrong. Try again.',
  done:         'Done. You can now sign in with your new password.',
};

const errorMessages = {
  weak_password: L.err_weak,
  invalid_token: L.err_invalid,
  expired:       L.err_invalid,
  already_used:  L.err_used,
  rate_limited:  L.err_rate,
};

const errorEl  = document.getElementById('auth-error');
const infoEl   = document.getElementById('auth-info');
const form     = document.getElementById('reset-form');
const submitEl = document.getElementById('reset-submit');
const pwEl     = document.getElementById('password');

function showError(msg) { errorEl.textContent = msg; errorEl.classList.add('show'); infoEl.classList.remove('show'); }
function showInfo(msg)  { infoEl.textContent = msg;  infoEl.classList.add('show');  errorEl.classList.remove('show'); }

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  errorEl.classList.remove('show');
  infoEl.classList.remove('show');

  const password = pwEl.value;
  const token    = form.dataset.token || '';
  if (password.length < 8) { showError(L.err_weak); return; }

  submitEl.disabled = true;
  try {
    await api('/api/auth/reset', {
      method: 'POST',
      body: JSON.stringify({ token, password }),
    });
    showInfo(L.done);
    // Soft redirect after a beat so the user sees the confirmation.
    setTimeout(() => { location.href = url('/login'); }, 1500);
  } catch (err) {
    showError(errorMessages[err.message] || L.err_generic);
    submitEl.disabled = false;
  }
});
