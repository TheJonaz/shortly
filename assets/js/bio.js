import { api, url, bindThemeToggle, copyText, toast } from './common.js';

bindThemeToggle(document.getElementById('theme-toggle'));

const L = window.LANG === 'sv' ? {
  saved: 'Sparat.',
  load_fail: 'Kunde inte ladda bio.',
  delete_confirm: 'Säker på att du vill ta bort din bio-sida?',
  err_invalid_bio_slug:    'Slug måste vara 2–32 tecken: små bokstäver, siffror, bindestreck, understreck.',
  err_bio_slug_reserved:   'Den slugen är reserverad. Välj en annan.',
  err_bio_slug_taken:      'Den slugen är upptagen.',
  err_bio_title_too_long:  'Rubriken får vara max 100 tecken.',
  err_invalid_bio_theme:   'Ogiltigt tema.',
  err_bio_links_too_many:  'Max 50 länkar per sida.',
  err_bio_link_label_too_long: 'Etikett max 80 tecken.',
  err_invalid_url:         'En av URL:erna ser ogiltig ut.',
  err_url_too_long:        'En URL är för lång.',
  err_invalid_protocol:    'Endast http:// och https:// fungerar.',
  err_generic:             'Något gick fel.',
} : {
  saved: 'Saved.',
  load_fail: 'Failed to load bio.',
  delete_confirm: 'Sure you want to delete your bio page?',
  err_invalid_bio_slug:    'Slug must be 2–32 chars: lowercase letters, digits, dash, underscore.',
  err_bio_slug_reserved:   'That slug is reserved. Pick another.',
  err_bio_slug_taken:      'That slug is taken.',
  err_bio_title_too_long:  'Title must be 100 characters or fewer.',
  err_invalid_bio_theme:   'Invalid theme.',
  err_bio_links_too_many:  'Max 50 links per page.',
  err_bio_link_label_too_long: 'Label must be 80 characters or fewer.',
  err_invalid_url:         'One of the URLs is not valid.',
  err_url_too_long:        'A URL is too long.',
  err_invalid_protocol:    'Only http:// and https:// links work.',
  err_generic:             'Something went wrong.',
};

const errorEl    = document.getElementById('bio-error');
const infoEl     = document.getElementById('bio-info');
const slugEl     = document.getElementById('bio-slug');
const titleEl    = document.getElementById('bio-title');
const themeEl    = document.getElementById('bio-theme');
const linksEl    = document.getElementById('bio-links');
const addBtn     = document.getElementById('bio-add');
const deleteBtn  = document.getElementById('bio-delete-btn');
const previewEl  = document.getElementById('bio-url-preview');
const publicEl   = document.getElementById('bio-public-link');
const form       = document.getElementById('bio-form');
const logoutBtn  = document.getElementById('logout-btn');

let publicBase = location.origin;

logoutBtn.addEventListener('click', async (e) => {
  e.preventDefault();
  try { await api('/api/auth/logout', { method: 'POST' }); } catch {}
  location.href = url('/');
});

function showError(msg) { errorEl.textContent = msg; errorEl.classList.add('show'); infoEl.classList.remove('show'); }
function showInfo(msg)  { infoEl.textContent  = msg; infoEl.classList.add('show');  errorEl.classList.remove('show'); }

function makeLinkRow(label = '', urlVal = '') {
  const row = document.createElement('div');
  row.className = 'bio-link-row';
  row.style.cssText = 'display:grid;grid-template-columns:1fr 2fr auto;gap:6px;';
  row.innerHTML = `
    <input type="text" class="bio-link-label" placeholder="Label" maxlength="80" value="${escapeAttr(label)}">
    <input type="url" class="bio-link-url" placeholder="https://..." value="${escapeAttr(urlVal)}">
    <button type="button" class="btn ghost small" data-remove>×</button>
  `;
  row.querySelector('[data-remove]').addEventListener('click', () => row.remove());
  return row;
}

function readLinks() {
  return [...linksEl.querySelectorAll('.bio-link-row')]
    .map(r => ({
      label: r.querySelector('.bio-link-label').value.trim(),
      url:   r.querySelector('.bio-link-url').value.trim(),
    }))
    .filter(l => l.label || l.url);
}

function updatePreview() {
  const slug = (slugEl.value || '').toLowerCase();
  if (slug) previewEl.textContent = `${publicBase}${url('/u/' + slug)}`;
  else previewEl.textContent = '';
}

function syncPublicLink(slug) {
  if (slug) {
    publicEl.innerHTML = `<a href="${url('/u/' + slug)}" target="_blank" rel="noopener" class="btn ghost small">${
      window.LANG === 'sv' ? 'Öppna offentlig sida' : 'Open public page'
    } →</a>`;
  } else {
    publicEl.innerHTML = '';
  }
}

slugEl.addEventListener('input', () => {
  // Auto-lowercase on the fly
  const v = slugEl.value;
  const lc = v.toLowerCase();
  if (v !== lc) {
    const pos = slugEl.selectionStart;
    slugEl.value = lc;
    slugEl.setSelectionRange(pos, pos);
  }
  updatePreview();
});

addBtn.addEventListener('click', () => linksEl.appendChild(makeLinkRow()));

async function loadBio() {
  let me;
  try { me = await api('/api/me'); } catch { location.href = url('/login'); return; }
  if (!me.user) { location.href = url('/login'); return; }
  if (me.publicUrl) publicBase = me.publicUrl.replace(url(''), '');

  let res;
  try { res = await api('/api/bio'); }
  catch { showError(L.load_fail); return; }

  if (res.bio) {
    slugEl.value  = res.bio.slug;
    titleEl.value = res.bio.title || '';
    themeEl.value = res.bio.theme || 'light';
    linksEl.innerHTML = '';
    (res.bio.links || []).forEach(l => linksEl.appendChild(makeLinkRow(l.label, l.url)));
    deleteBtn.hidden = false;
    syncPublicLink(res.bio.slug);
  } else {
    // Empty state — one starter row.
    linksEl.appendChild(makeLinkRow());
  }
  updatePreview();
}

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  errorEl.classList.remove('show');
  infoEl.classList.remove('show');
  const payload = {
    slug:  slugEl.value.trim().toLowerCase(),
    title: titleEl.value.trim(),
    theme: themeEl.value,
    links: readLinks(),
  };
  try {
    const res = await api('/api/bio', { method: 'PUT', body: JSON.stringify(payload) });
    showInfo(L.saved);
    deleteBtn.hidden = false;
    syncPublicLink(res.bio.slug);
  } catch (err) {
    const key = 'err_' + err.message;
    showError(L[key] || L.err_generic);
  }
});

deleteBtn.addEventListener('click', async () => {
  if (!confirm(L.delete_confirm)) return;
  try {
    await api('/api/bio', { method: 'DELETE' });
    location.reload();
  } catch { showError(L.err_generic); }
});

function escapeAttr(s) {
  return String(s).replace(/[&<>"']/g, c =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

loadBio();
