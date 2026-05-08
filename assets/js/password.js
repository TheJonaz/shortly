import { api } from './common.js';

const form = document.getElementById('unlock-form');
const slug = form.dataset.slug || location.pathname.split('/').filter(Boolean).pop();
const errorEl = document.getElementById('error');
const btn = document.getElementById('unlock-btn');

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  errorEl.classList.remove('show');
  btn.disabled = true;
  btn.textContent = 'Unlocking…';
  const password = document.getElementById('password').value;

  try {
    const res = await api(`/api/unlock/${encodeURIComponent(slug)}`, {
      method: 'POST',
      body: JSON.stringify({ password }),
    });
    location.href = res.target;
  } catch (err) {
    btn.disabled = false;
    btn.textContent = 'Unlock & continue';
    errorEl.textContent =
      err.message === 'invalid_password' ? 'Wrong password.' :
      err.message === 'expired' ? 'This link has expired.' :
      err.message === 'not_found' ? 'No password-protected link with this slug.' :
      'Something went wrong.';
    errorEl.classList.add('show');
  }
});
