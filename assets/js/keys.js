import { api, url, bindThemeToggle, copyText, formatDateTime } from './common.js';

bindThemeToggle(document.getElementById('theme-toggle'));

const L = window.LANG === 'sv' ? {
  no_keys:        'Inga nycklar än.',
  load_fail:      'Kunde inte ladda nycklar.',
  revoke_confirm: 'Återkalla nyckeln? Skript som använder den slutar fungera.',
  copy:           'Kopiera',
  copied:         'Kopierad',
  revoke:         'Återkalla',
  revoked:        'Återkallad',
  created:        'Skapad',
  last_used:      'Senast använd',
  never:          'Aldrig',
  err_label_too_long:    'Etiketten får vara max 100 tecken.',
  err_apikey_limit:      'Max 20 aktiva nycklar per användare.',
  err_session_required:  'Skapa nycklar via inloggning, inte API.',
  err_generic:           'Något gick fel.',
} : {
  no_keys:        'No keys yet.',
  load_fail:      'Failed to load keys.',
  revoke_confirm: 'Revoke this key? Scripts using it will stop working.',
  copy:           'Copy',
  copied:         'Copied',
  revoke:         'Revoke',
  revoked:        'Revoked',
  created:        'Created',
  last_used:      'Last used',
  never:          'Never',
  err_label_too_long:    'Label must be 100 characters or fewer.',
  err_apikey_limit:      'Max 20 active keys per user.',
  err_session_required:  'Create keys via signed-in session, not API.',
  err_generic:           'Something went wrong.',
};

const errorMessages = {
  label_too_long:       L.err_label_too_long,
  apikey_limit_reached: L.err_apikey_limit,
  session_required:     L.err_session_required,
};

const errorEl  = document.getElementById('keys-error');
const listEl   = document.getElementById('keys-list');
const labelEl  = document.getElementById('key-label');
const form     = document.getElementById('keys-create-form');
const freshEl  = document.getElementById('keys-fresh');
const freshVal = document.getElementById('keys-fresh-value');
const copyBtn  = document.getElementById('keys-fresh-copy');
const logoutBtn= document.getElementById('logout-btn');

logoutBtn.addEventListener('click', async (e) => {
  e.preventDefault();
  try { await api('/api/auth/logout', { method: 'POST' }); } catch {}
  location.href = url('/');
});

function showError(msg) { errorEl.textContent = msg; errorEl.classList.add('show'); }
function clearError() { errorEl.classList.remove('show'); }

function rowHtml(k) {
  const usedAt = k.last_used_at ? formatDateTime(k.last_used_at) : L.never;
  const created = formatDateTime(k.created_at);
  const status = k.revoked
    ? `<span style="opacity:.55">(${L.revoked})</span>`
    : `<button type="button" class="btn ghost small" data-revoke="${k.id}">${L.revoke}</button>`;
  return `
    <article class="key-row" style="display:grid;grid-template-columns:auto 1fr auto;gap:14px;align-items:center;padding:12px 0;border-bottom:1px solid var(--rule,#ddd);${k.revoked ? 'opacity:.5' : ''}">
      <div>
        <code style="font-family:var(--mono,monospace);font-size:13px">${escapeHtml(k.prefix)}…</code>
        ${k.label ? `<div style="font-size:12px;opacity:.65">${escapeHtml(k.label)}</div>` : ''}
      </div>
      <div style="font-size:12px;opacity:.65">
        ${L.created}: ${created}<br>
        ${L.last_used}: ${usedAt}
      </div>
      <div>${status}</div>
    </article>
  `;
}

async function loadKeys() {
  let res;
  try { res = await api('/api/keys'); }
  catch (err) {
    if (err.status === 401) { location.href = url('/login'); return; }
    showError(L.load_fail); return;
  }
  if (!res.keys.length) {
    listEl.innerHTML = `<p class="muted">${L.no_keys}</p>`;
    return;
  }
  listEl.innerHTML = res.keys.map(rowHtml).join('');
  listEl.querySelectorAll('[data-revoke]').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (!confirm(L.revoke_confirm)) return;
      const id = btn.dataset.revoke;
      try {
        await api(`/api/keys/${id}`, { method: 'DELETE' });
        loadKeys();
      } catch { showError(L.err_generic); }
    });
  });
}

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  clearError();
  const label = labelEl.value.trim();
  try {
    const res = await api('/api/keys', { method: 'POST', body: JSON.stringify({ label: label || null }) });
    freshVal.textContent = res.key.key;
    freshEl.hidden = false;
    labelEl.value = '';
    await loadKeys();
  } catch (err) {
    showError(errorMessages[err.message] || L.err_generic);
  }
});

copyBtn.addEventListener('click', async () => {
  await copyText(freshVal.textContent);
  copyBtn.textContent = L.copied;
  setTimeout(() => { copyBtn.textContent = L.copy; }, 1500);
});

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

loadKeys();
