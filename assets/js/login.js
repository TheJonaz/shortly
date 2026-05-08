import { api, url, bindThemeToggle, toast } from './common.js';

for (const a of document.querySelectorAll('[data-base-link]')) {
  a.href = url(a.dataset.baseLink);
}
bindThemeToggle(document.getElementById('theme-toggle'));

const L = window.LANG === 'sv' ? {
  err_taken:      'Den e-posten är redan registrerad. Försök logga in.',
  err_creds:      'Fel e-post eller lösenord.',
  err_required:   'Båda fälten är obligatoriska.',
  err_email:      'Ogiltig e-postadress.',
  err_password:   'Lösenordet måste vara minst 8 tecken.',
  err_generic:    'Något gick fel. Försök igen.',
  err_email_mismatch: 'E-postadresserna stämmer inte överens.',
  err_all_fields: 'Alla fält måste fyllas i.',
  err_name_long:  'Namnet får vara max 100 tecken.',
  err_rate:       'För många försök. Vänta en stund.',
  err_captcha:    'Bot-kontrollen misslyckades. Ladda om sidan och försök igen.',
} : {
  err_taken:      'That email is already registered. Try signing in.',
  err_creds:      'Wrong email or password.',
  err_required:   'Both fields are required.',
  err_email:      'That doesn\'t look like a valid email.',
  err_password:   'Password must be at least 8 characters.',
  err_generic:    'Something went wrong. Try again.',
  err_email_mismatch: 'The email addresses don\'t match.',
  err_all_fields: 'All fields are required.',
  err_name_long:  'Name must be 100 characters or fewer.',
  err_rate:       'Too many attempts. Wait a moment.',
  err_captcha:    'Bot check failed. Reload the page and try again.',
};

const errorMessages = {
  email_taken:                 L.err_taken,
  invalid_credentials:         L.err_creds,
  email_and_password_required: L.err_required,
  invalid_email:               L.err_email,
  password_min_8:              L.err_password,
  email_mismatch:              L.err_email_mismatch,
  all_fields_required:         L.err_all_fields,
  name_too_long:               L.err_name_long,
  rate_limited:                L.err_rate,
  captcha_required:            L.err_captcha,
};

const isRegister = location.pathname.endsWith('/register');
const errorEl    = document.getElementById('auth-error');
const submitBtn  = document.getElementById('auth-submit');

document.getElementById('auth-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  errorEl.classList.remove('show');
  submitBtn.disabled = true;

  const email    = document.getElementById('email').value.trim();
  const password = document.getElementById('password').value;

  let body;
  if (isRegister) {
    const name          = document.getElementById('name').value.trim();
    const email_confirm = document.getElementById('email_confirm').value.trim();
    if (email !== email_confirm) {
      errorEl.textContent = L.err_email_mismatch;
      errorEl.classList.add('show');
      submitBtn.disabled = false;
      return;
    }
    const payload = { name, email, email_confirm, password };
    if (window.__turnstileToken) payload.turnstile_token = window.__turnstileToken;
    body = JSON.stringify(payload);
  } else {
    body = JSON.stringify({ email, password });
  }

  try {
    const res = await api(`/api/auth/${isRegister ? 'register' : 'login'}`, {
      method: 'POST',
      body,
    });

    if (isRegister && res.pending) {
      // Code sent — go enter it.
      location.href = url('/verify') + '?email=' + encodeURIComponent(res.email);
      return;
    }
    location.href = url('/app');
  } catch (err) {
    const msg = errorMessages[err.message] || L.err_generic;
    errorEl.textContent = msg;
    errorEl.classList.add('show');
    submitBtn.disabled = false;
  }
});
