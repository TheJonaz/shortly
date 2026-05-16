import { api, url, bindThemeToggle } from './common.js';

bindThemeToggle(document.getElementById('theme-toggle'));

const wrap     = document.getElementById('users-wrap');
const pager    = document.getElementById('pager');
const pageInfo = document.getElementById('page-info');
const prevLink = document.getElementById('prev-page');
const nextLink = document.getElementById('next-page');
const toastEl  = document.getElementById('admin-toast');
const form     = document.getElementById('search-form');
const qEl      = document.getElementById('q');

const params = new URLSearchParams(location.search);
let q    = params.get('q') || '';
let page = Math.max(1, parseInt(params.get('page') || '1', 10));

function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c =>
    ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function fmtDate(ms) {
  if (!ms) return '—';
  return new Date(Number(ms)).toLocaleDateString(undefined,
    { year:'numeric', month:'short', day:'numeric' });
}
function showToast(msg, kind = 'info') {
  toastEl.textContent = msg;
  toastEl.className = 'admin-toast ' + kind;
  toastEl.hidden = false;
  setTimeout(() => { toastEl.hidden = true; }, 2400);
}

async function load() {
  wrap.textContent = 'Loading…';
  const qs = new URLSearchParams();
  if (q)        qs.set('q', q);
  if (page > 1) qs.set('page', String(page));
  try {
    const r = await api('/api/admin/users?' + qs.toString());
    render(r);
  } catch (err) {
    wrap.innerHTML = `<div class="admin-error">Couldn’t load users: ${escapeHtml(err.message)}</div>`;
    pager.hidden = true;
  }
}

function render(data) {
  const rows = data.users || [];
  if (rows.length === 0) {
    wrap.innerHTML = '<p class="muted">No users match.</p>';
    pager.hidden = true;
    return;
  }
  let html = `<table class="admin-table"><thead><tr>
    <th>ID</th><th>Email</th><th>Name</th><th>Tier</th><th>Created</th><th>Actions</th>
  </tr></thead><tbody>`;
  for (const u of rows) {
    html += `<tr data-uid="${u.id}">
      <td class="mono faint">${u.id}</td>
      <td>${escapeHtml(u.email)}</td>
      <td>${escapeHtml(u.name || '')}</td>
      <td>
        <select class="tier-select" data-uid="${u.id}">
          <option value="free"${u.tier === 'free' ? ' selected' : ''}>free</option>
          <option value="pro"${u.tier === 'pro' ? ' selected' : ''}>pro</option>
        </select>
      </td>
      <td class="mono faint">${fmtDate(u.created_at)}</td>
      <td>
        <button class="btn ghost xsmall clear-sessions" data-uid="${u.id}" title="Sign this user out everywhere">Wipe sessions</button>
      </td>
    </tr>`;
  }
  html += '</tbody></table>';
  wrap.innerHTML = html;

  // Pager visibility + state
  const totalPages = Math.max(1, Math.ceil(data.total / data.per_page));
  pageInfo.textContent = `Page ${page} of ${totalPages} · ${data.total} users`;
  pager.hidden = totalPages <= 1;
  prevLink.style.visibility = page > 1 ? 'visible' : 'hidden';
  nextLink.style.visibility = page < totalPages ? 'visible' : 'hidden';

  wrap.querySelectorAll('.tier-select').forEach(sel => {
    sel.addEventListener('change', async () => {
      const uid = sel.dataset.uid;
      const tier = sel.value;
      sel.disabled = true;
      try {
        await api(`/api/admin/users/${uid}/tier`, {
          method: 'POST', body: JSON.stringify({ tier }),
        });
        showToast(`User #${uid} → ${tier}`, 'ok');
      } catch (err) {
        showToast('Could not change tier: ' + err.message, 'error');
      } finally { sel.disabled = false; }
    });
  });
  wrap.querySelectorAll('.clear-sessions').forEach(btn => {
    btn.addEventListener('click', async () => {
      const uid = btn.dataset.uid;
      if (!confirm(`Sign user #${uid} out of all sessions?`)) return;
      btn.disabled = true;
      try {
        await api(`/api/admin/users/${uid}/sessions`, { method: 'DELETE' });
        showToast(`User #${uid} signed out everywhere`, 'ok');
      } catch (err) {
        showToast('Could not wipe sessions: ' + err.message, 'error');
      } finally { btn.disabled = false; }
    });
  });
}

function navigate(newQ, newPage) {
  q = newQ;
  page = newPage;
  const qs = new URLSearchParams();
  if (q)        qs.set('q', q);
  if (page > 1) qs.set('page', String(page));
  const tail = qs.toString();
  history.replaceState({}, '', location.pathname + (tail ? '?' + tail : ''));
  load();
}

form.addEventListener('submit', (e) => {
  e.preventDefault();
  navigate(qEl.value.trim(), 1);
});
prevLink.addEventListener('click', (e) => { e.preventDefault(); navigate(q, Math.max(1, page - 1)); });
nextLink.addEventListener('click', (e) => { e.preventDefault(); navigate(q, page + 1); });

load();
