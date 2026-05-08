import { api, url, bindThemeToggle } from './common.js';

bindThemeToggle(document.getElementById('theme-toggle'));

const L = window.LANG === 'sv' ? {
  thanks:       'Tack — rapporten är registrerad. Vi granskar och tar ner länken om den bryter mot reglerna.',
  suspended:    'Länken har redan avstängts.',
  err_invalid_slug: 'Slug måste vara 2–32 tecken.',
  err_invalid_reason: 'Välj en giltig anledning.',
  err_link_not_found: 'Vi hittar inte en länk med den slugen.',
  err_detail_too_long: 'Detaljer max 1000 tecken.',
  err_rate_limited: 'För många försök. Försök igen senare.',
  err_generic:  'Något gick fel.',
} : {
  thanks:       'Thanks — your report is logged. We\'ll review and take the link down if it breaks the rules.',
  suspended:    'This link has already been suspended.',
  err_invalid_slug: 'Slug must be 2–32 characters.',
  err_invalid_reason: 'Pick a valid reason.',
  err_link_not_found: 'No link with that slug exists.',
  err_detail_too_long: 'Details limited to 1000 characters.',
  err_rate_limited: 'Too many requests. Try again later.',
  err_generic:  'Something went wrong.',
};

const errorMessages = {
  invalid_slug:    L.err_invalid_slug,
  invalid_reason:  L.err_invalid_reason,
  link_not_found:  L.err_link_not_found,
  detail_too_long: L.err_detail_too_long,
  rate_limited:    L.err_rate_limited,
};

const form    = document.getElementById('report-form');
const errorEl = document.getElementById('report-error');
const infoEl  = document.getElementById('report-info');

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  errorEl.classList.remove('show');
  infoEl.classList.remove('show');

  const slug   = document.getElementById('report-slug').value.trim();
  const reason = document.getElementById('report-reason').value;
  const detail = document.getElementById('report-detail').value.trim();

  try {
    const res = await api('/api/abuse', {
      method: 'POST',
      body: JSON.stringify({ slug, reason, detail: detail || null }),
    });
    infoEl.textContent = res.suspended ? L.suspended : L.thanks;
    infoEl.classList.add('show');
    form.reset();
  } catch (err) {
    errorEl.textContent = errorMessages[err.message] || L.err_generic;
    errorEl.classList.add('show');
  }
});
