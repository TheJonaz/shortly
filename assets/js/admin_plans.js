import { api, url, bindThemeToggle } from './common.js';

bindThemeToggle(document.getElementById('theme-toggle'));

const wrap         = document.getElementById('plans-wrap');
const toastEl      = document.getElementById('admin-toast');
const archivedTog  = document.getElementById('show-archived');
const createForm   = document.getElementById('create-form');
const createSubmit = document.getElementById('create-submit');

function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c =>
    ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function showToast(msg, kind = 'info') {
  toastEl.textContent = msg;
  toastEl.className = 'admin-toast ' + kind;
  toastEl.hidden = false;
  setTimeout(() => { toastEl.hidden = true; }, 3000);
}
function fmtAmount(p) {
  // Stripe returns unit_amount in minor units (öre/cent). 4900 sek → "49.00 SEK".
  if (p.unit_amount == null) return '—';
  return (p.unit_amount / 100).toFixed(2) + ' ' + (p.currency || '').toUpperCase();
}
function fmtInterval(p) {
  if (!p.recurring) return 'one-shot';
  return p.recurring.interval + (p.recurring.interval_count > 1 ? ` × ${p.recurring.interval_count}` : '');
}
function fmtCurrencyOptions(p) {
  const opts = p.currency_options || {};
  const keys = Object.keys(opts);
  if (!keys.length) return '<span class="faint">—</span>';
  return keys.map(k => {
    const cents = opts[k].unit_amount;
    return `<span class="cur-chip">${k.toUpperCase()} ${(cents/100).toFixed(2)}</span>`;
  }).join(' ');
}

async function load() {
  wrap.textContent = 'Loading…';
  try {
    const qs = archivedTog.checked ? '?archived=1' : '';
    const r = await api('/api/admin/plans' + qs);
    render(r.data || []);
  } catch (err) {
    wrap.innerHTML = `<div class="admin-error">Couldn’t load plans: ${escapeHtml(err.message)}</div>`;
  }
}

function render(prices) {
  if (prices.length === 0) {
    wrap.innerHTML = '<p class="muted">No prices found.</p>';
    return;
  }
  let html = `<table class="admin-table"><thead><tr>
    <th>ID</th><th>Nickname</th><th>Interval</th><th>Base</th>
    <th>Other currencies</th><th>Lookup key</th><th>Status</th><th>Actions</th>
  </tr></thead><tbody>`;
  for (const p of prices) {
    const archived = !p.active;
    html += `<tr class="${archived ? 'is-archived' : ''}">
      <td class="mono faint" title="${escapeHtml(p.id)}">${escapeHtml(p.id.slice(0, 16))}…</td>
      <td>${escapeHtml(p.nickname || '')}</td>
      <td>${escapeHtml(fmtInterval(p))}</td>
      <td class="mono">${escapeHtml(fmtAmount(p))}</td>
      <td>${fmtCurrencyOptions(p)}</td>
      <td><code class="mono small">${escapeHtml(p.lookup_key || '')}</code></td>
      <td>${archived ? '<span class="pill faint">archived</span>' : '<span class="pill ok">active</span>'}</td>
      <td>
        ${archived
          ? `<button class="btn ghost xsmall unarchive-btn" data-id="${escapeHtml(p.id)}">Unarchive</button>`
          : `<button class="btn ghost xsmall archive-btn"   data-id="${escapeHtml(p.id)}">Archive</button>`}
      </td>
    </tr>`;
  }
  html += '</tbody></table>';
  wrap.innerHTML = html;

  wrap.querySelectorAll('.archive-btn').forEach(btn => {
    btn.addEventListener('click', () => actOn(btn, 'archive'));
  });
  wrap.querySelectorAll('.unarchive-btn').forEach(btn => {
    btn.addEventListener('click', () => actOn(btn, 'unarchive'));
  });
}

async function actOn(btn, op) {
  const id = btn.dataset.id;
  if (op === 'archive' && !confirm(`Archive Price ${id}? Existing subscriptions keep billing; new Checkouts on this price will be refused.`)) return;
  btn.disabled = true;
  try {
    await api(`/api/admin/plans/${id}/${op}`, { method: 'POST' });
    showToast(`Price ${op === 'archive' ? 'archived' : 'unarchived'}.`, 'ok');
    load();
  } catch (err) {
    showToast(`Stripe rejected ${op}: ${err.message}`, 'error');
    btn.disabled = false;
  }
}

archivedTog.addEventListener('change', load);

createForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(createForm);
  const currency  = fd.get('currency');
  const payload = {
    currency,
    interval:    fd.get('interval'),
    unit_amount: parseInt(fd.get('unit_amount'), 10),
    lookup_key:  (fd.get('lookup_key')  || '').toString().trim(),
    nickname:    (fd.get('nickname')    || '').toString().trim(),
    currency_options: {},
  };
  for (const code of ['sek', 'eur', 'usd']) {
    const v = parseInt(fd.get('opt_' + code), 10);
    if (Number.isFinite(v) && v > 0 && code !== currency) {
      payload.currency_options[code] = v;
    }
  }
  createSubmit.disabled = true;
  try {
    await api('/api/admin/plans', { method: 'POST', body: JSON.stringify(payload) });
    showToast('Price created.', 'ok');
    createForm.reset();
    load();
  } catch (err) {
    showToast('Stripe rejected create: ' + err.message, 'error');
  } finally {
    createSubmit.disabled = false;
  }
});

load();
