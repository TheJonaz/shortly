import { api, url, bindThemeToggle, copyText, timeAgo, formatDateTime, toast } from './common.js';

for (const a of document.querySelectorAll('[data-base-link]')) {
  a.href = url(a.dataset.baseLink);
}
bindThemeToggle(document.getElementById('theme-toggle'));

const L = window.LANG === 'sv' ? {
  loading:        'Laddar…',
  no_clicks:      'Inga klick än.',
  load_fail:      'Kunde inte ladda statistik.',
  delete_confirm: 'Ta bort den här korta länken? Det raderar även klickhistoriken.',
  deleted:        'Borttagen',
  delete_fail:    'Borttagning misslyckades',
  link_fail:      'Kunde inte ladda länkar',
  last30:         'Senaste 30 dagarna',
  recent_clicks:  'Senaste klick',
  links:          'länk', links_pl: 'länkar',
  clicks:         'klick',
  unique_short:   'unika',
  total_label:    'Totalt',
  unique_label:   'Unika',
  pw_badge:       'Lösenordsskyddad',
  expired_badge:  'Utgången',
  exp_badge:      'Utgår',
  edit:           'Redigera',
  edit_link:      'Redigera länk',
  save:           'Spara',
  cancel:         'Avbryt',
  edit_keep_pw:   'Lämna oförändrat',
  edit_new_pw:    'Nytt lösenord',
  edit_clear_pw:  'Ta bort lösenord',
  edit_clear_exp: 'Ta bort utgång',
  saved:          'Sparat',
  csv_export:     'Exportera klick (CSV)',
  devices_title:  'Enheter',
  upgrade_to_pro_short: 'Pro krävs',
} : {
  loading:        'Loading…',
  no_clicks:      'No clicks yet.',
  load_fail:      'Failed to load stats.',
  delete_confirm: 'Delete this short link? This also removes its click history.',
  deleted:        'Deleted',
  delete_fail:    'Delete failed',
  link_fail:      'Failed to load links',
  last30:         'Last 30 days',
  recent_clicks:  'Recent clicks',
  links:          'link', links_pl: 'links',
  clicks:         'click',
  unique_short:   'unique',
  total_label:    'Total',
  unique_label:   'Unique',
  pw_badge:       'Password protected',
  expired_badge:  'Expired',
  exp_badge:      'Expires',
  edit:           'Edit',
  edit_link:      'Edit link',
  save:           'Save',
  cancel:         'Cancel',
  edit_keep_pw:   'Keep unchanged',
  edit_new_pw:    'New password',
  edit_clear_pw:  'Remove password',
  edit_clear_exp: 'Remove expiry',
  saved:          'Saved',
  csv_export:     'Export clicks (CSV)',
  devices_title:  'Devices',
  upgrade_to_pro_short: 'Pro required',
};

let CURRENT_TIER = 'anon';

const tableEl = document.getElementById('link-table');
const emptyEl = document.getElementById('empty');
const summaryEl = document.getElementById('summary');
const userEmailEl = document.getElementById('user-email');
const logoutBtn = document.getElementById('logout-btn');
const billingEl = document.getElementById('billing');

const expandedRows = new Set();

async function bootstrap() {
  let me;
  try { me = await api('/api/me'); }
  catch { location.href = url('/login'); return; }
  if (!me.user) { location.href = url('/login'); return; }
  CURRENT_TIER = me.tier || 'free';
  userEmailEl.textContent = me.user.name || me.user.email;
  // Surface ?upgraded / ?canceled flash messages from Stripe redirect.
  const params = new URLSearchParams(location.search);
  if (params.get('upgraded') === '1') toast(BL.thanks);
  if (params.get('canceled') === '1') toast(BL.canceled);
  if (params.has('upgraded') || params.has('canceled')) {
    history.replaceState({}, '', location.pathname);
  }
  await Promise.all([loadLinks(), loadBilling(me.tier)]);
}

const BL = window.LANG === 'sv' ? {
  upgrade_h:    'Uppgradera till Pro',
  upgrade_sub:  'Få avancerad statistik, redigerbara länkar, lösenordsskydd, anpassad utgång, bulk-uppladdning och mer.',
  monthly:      '5 USD / månad',
  yearly:       '50 USD / år',
  save:         'Spara 17%',
  btn_monthly:  'Välj månadsplan',
  btn_yearly:   'Välj årsplan',
  pro_h:        'Du är Pro ✓',
  pro_plan:     'Plan',
  pro_renews:   'Förnyas',
  pro_cancels:  'Sägs upp vid periodens slut',
  pro_status:   'Status',
  manage:       'Hantera prenumeration',
  thanks:       'Tack — uppgraderingen är klar.',
  canceled:     'Uppgraderingen avbröts.',
  err_unavail:  'Betalning är inte tillgänglig just nu.',
  err_generic:  'Något gick fel.',
} : {
  upgrade_h:    'Upgrade to Pro',
  upgrade_sub:  'Get advanced analytics, editable links, password protection, custom expiry, bulk upload and more.',
  monthly:      '$5 / month',
  yearly:       '$50 / year',
  save:         'Save 17%',
  btn_monthly:  'Choose monthly',
  btn_yearly:   'Choose yearly',
  pro_h:        'You are Pro ✓',
  pro_plan:     'Plan',
  pro_renews:   'Renews',
  pro_cancels:  'Cancels at period end',
  pro_status:   'Status',
  manage:       'Manage subscription',
  thanks:       'Thanks — your upgrade is live.',
  canceled:     'Upgrade canceled.',
  err_unavail:  'Billing is unavailable right now.',
  err_generic:  'Something went wrong.',
};

async function loadBilling(tier) {
  let st = null;
  try { st = await api('/api/billing/status'); } catch {}
  if (tier === 'pro') {
    renderProBilling(st);
  } else if (st && st.billing_available) {
    renderUpgradeCTA();
  } else {
    // Stripe not configured on this instance — hide the section entirely
    // so we don't show a CTA that would 503 on click.
    billingEl.hidden = true;
  }
}

function renderUpgradeCTA() {
  billingEl.innerHTML = `
    <h3 style="margin:0 0 8px;font-size:18px;">${BL.upgrade_h}</h3>
    <p class="muted" style="margin:0 0 18px;">${BL.upgrade_sub}</p>
    <div style="display:flex;gap:12px;flex-wrap:wrap;">
      <div style="flex:1 1 220px;padding:16px;border:1px solid var(--rule,#ddd);border-radius:10px;">
        <div style="font-size:14px;opacity:.7;">Monthly</div>
        <div style="font-size:24px;font-weight:500;margin:4px 0 12px;">${BL.monthly}</div>
        <button class="btn primary" data-plan="monthly">${BL.btn_monthly}</button>
      </div>
      <div style="flex:1 1 220px;padding:16px;border:1px solid var(--rule,#ddd);border-radius:10px;position:relative;">
        <div style="position:absolute;top:-10px;right:12px;font-size:11px;background:var(--accent,#181613);color:var(--accent-ink,#fff);padding:2px 8px;border-radius:8px;">${BL.save}</div>
        <div style="font-size:14px;opacity:.7;">Yearly</div>
        <div style="font-size:24px;font-weight:500;margin:4px 0 12px;">${BL.yearly}</div>
        <button class="btn primary" data-plan="yearly">${BL.btn_yearly}</button>
      </div>
    </div>
  `;
  billingEl.querySelectorAll('[data-plan]').forEach(btn => {
    btn.addEventListener('click', () => startCheckout(btn.dataset.plan, btn));
  });
}

function renderProBilling(st) {
  const sub = st && st.subscription;
  const renewDate = sub && sub.current_period_end
    ? new Date(sub.current_period_end).toLocaleDateString()
    : '—';
  const cancelLabel = sub && sub.cancel_at_period_end ? BL.pro_cancels : BL.pro_renews;
  billingEl.innerHTML = `
    <h3 style="margin:0 0 12px;font-size:18px;">${BL.pro_h}</h3>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;font-size:13px;margin-bottom:16px;">
      <div><div style="opacity:.6">${BL.pro_plan}</div><div style="font-weight:500">${escapeHtml((sub && sub.plan) || '—')}</div></div>
      <div><div style="opacity:.6">${BL.pro_status}</div><div style="font-weight:500">${escapeHtml((sub && sub.status) || '—')}</div></div>
      <div><div style="opacity:.6">${cancelLabel}</div><div style="font-weight:500">${renewDate}</div></div>
    </div>
    <button class="btn ghost" id="billing-portal">${BL.manage}</button>
  `;
  document.getElementById('billing-portal').addEventListener('click', startPortal);
}

async function startCheckout(plan, btn) {
  if (btn) btn.disabled = true;
  try {
    const res = await api('/api/billing/checkout', {
      method: 'POST', body: JSON.stringify({ plan }),
    });
    location.href = res.url;
  } catch (err) {
    if (btn) btn.disabled = false;
    toast(err.message === 'billing_unavailable' ? BL.err_unavail : BL.err_generic, 'error');
  }
}

async function startPortal() {
  try {
    const res = await api('/api/billing/portal', { method: 'POST' });
    location.href = res.url;
  } catch (err) {
    toast(err.message === 'billing_unavailable' ? BL.err_unavail : BL.err_generic, 'error');
  }
}

async function loadLinks() {
  let res;
  try { res = await api('/api/links'); }
  catch (e) {
    if (e.status === 401) { location.href = url('/login'); return; }
    toast('Failed to load links', 'error');
    return;
  }
  renderLinks(res.links);
}

function renderLinks(links) {
  if (!links.length) {
    emptyEl.hidden = false;
    tableEl.innerHTML = '';
    summaryEl.innerHTML = '';
    return;
  }
  emptyEl.hidden = true;
  const totalClicks = links.reduce((s, l) => s + (l.click_count || 0), 0);
  const linkWord  = links.length === 1 ? L.links : L.links_pl;
  const clickWord = L.clicks;
  summaryEl.innerHTML = `<strong>${links.length}</strong> ${linkWord} · <strong>${totalClicks}</strong> ${clickWord}`;
  tableEl.innerHTML = links.map(linkRowHtml).join('');

  for (const row of tableEl.querySelectorAll('.link-row')) {
    row.addEventListener('click', (e) => {
      if (e.target.closest('button, a')) return;
      toggleStats(row.dataset.id);
    });
  }
  for (const btn of tableEl.querySelectorAll('[data-action]')) {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const id = btn.closest('.link-row').dataset.id;
      const action = btn.dataset.action;
      handleAction(action, id, btn);
    });
  }
}

function linkRowHtml(l) {
  const expired = l.expires_at && l.expires_at < Date.now();
  const badges = [];
  if (l.has_password) badges.push(`<span class="badge" title="${L.pw_badge}">PW</span>`);
  if (l.expires_at) badges.push(`<span class="badge" title="${expired ? L.expired_badge : L.exp_badge + ' ' + formatDateTime(l.expires_at)}" style="${expired ? 'background:#8a1f1f;color:#fff' : ''}">${expired ? L.expired_badge.toUpperCase() : 'EXP'}</span>`);
  // Tag chips: a tiny dot in tag.color + name. Server already returned a
  // safe, server-validated array; still escape both fields defensively.
  const tagChips = (l.tags || []).map(t =>
    `<span class="tag-chip" style="display:inline-flex;align-items:center;gap:4px;font-size:11px;padding:2px 7px;border-radius:10px;background:rgba(0,0,0,.05);margin-left:4px;">
       <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:${t.color || '#888'}"></span>
       ${escapeHtml(t.name)}
     </span>`
  ).join('');
  // "X · Y unique" — show unique only if it differs from total to keep it tidy.
  const total = l.click_count || 0;
  const unique = l.unique_clicks || 0;
  const clicksLabel = (unique && unique !== total)
    ? `${total} <span style="opacity:.5;font-size:11px">· ${unique} ${L.unique_short}</span>`
    : `${total}`;

  return `
    <article class="link-row" data-id="${l.id}">
      <div class="slug">
        <a href="${l.short_url}" target="_blank" rel="noopener" style="color:inherit;text-decoration:none">/${l.slug}</a>
        ${badges.join('')}
        ${tagChips}
      </div>
      <div class="target" title="${escapeAttr(l.target)}">${escapeHtml(l.target)}</div>
      <div class="clicks">${clicksLabel}</div>
      <div class="when">${timeAgo(l.last_click) || timeAgo(l.created_at)}</div>
      <div class="actions">
        <button class="icon-btn" data-action="copy" title="Copy short URL">${iconCopy}</button>
        ${CURRENT_TIER === 'pro'
          ? `<button class="icon-btn" data-action="edit" title="${L.edit_link}">${iconEdit}</button>`
          : ''}
        <button class="icon-btn danger" data-action="delete" title="Delete link">${iconTrash}</button>
      </div>
    </article>
    <div class="stats-panel" id="stats-${l.id}"></div>
  `;
}

async function handleAction(action, id, btn) {
  const link = await getLinkSummary(id);
  if (action === 'copy') {
    if (link) copyText(link.short_url);
    return;
  }
  if (action === 'edit') {
    openEditModal(id);
    return;
  }
  if (action === 'delete') {
    if (!confirm(L.delete_confirm)) return;
    try {
      await api(`/api/links/${id}`, { method: 'DELETE' });
      toast(L.deleted);
      await loadLinks();
    } catch {
      toast(L.delete_fail, 'error');
    }
  }
}

async function getLinkSummary(id) {
  const row = document.querySelector(`.link-row[data-id="${id}"]`);
  if (!row) return null;
  const slug = row.querySelector('.slug a').textContent.trim().slice(1);
  const a = row.querySelector('.slug a');
  return { short_url: a.href, slug };
}

async function toggleStats(id) {
  const panel = document.getElementById(`stats-${id}`);
  if (!panel) return;
  if (expandedRows.has(id)) {
    panel.classList.remove('show');
    panel.innerHTML = '';
    expandedRows.delete(id);
    return;
  }
  expandedRows.add(id);
  panel.classList.add('show');
  panel.innerHTML = `<p class="muted">${L.loading}</p>`;
  try {
    const data = await api(`/api/links/${id}/stats`);
    panel.innerHTML = renderStats(data);
  } catch {
    panel.innerHTML = `<p class="muted">${L.load_fail}</p>`;
  }
}

function renderStats(data) {
  const days = [...data.by_day].reverse(); // chronological
  const max = Math.max(1, ...days.map((d) => d.n));

  const bars = days.length
    ? days.map((d) => {
        const h = Math.round((d.n / max) * 80);
        return `<div class="bar" style="height:${h}px" data-count="${d.n} on ${d.day}" data-day="${d.day}"></div>`;
      }).join('')
    : `<p class="muted" style="margin:0">${L.no_clicks}</p>`;

  const direct = window.LANG === 'sv' ? 'direkt' : 'direct';
  const recent = data.recent.length
    ? data.recent.slice(0, 12).map((c) => `
        <div class="recent-row">
          <span>${formatDateTime(c.ts)}</span>
          <span title="${escapeAttr(c.user_agent || '')}">${escapeHtml((c.referrer || direct).slice(0, 80))}</span>
        </div>
      `).join('')
    : '';

  const totals = data.totals || { clicks: 0, unique: 0 };
  const totalsRow = `
    <div class="stats-totals" style="display:flex;gap:24px;font-size:13px;margin-bottom:12px;opacity:.85">
      <span><strong>${totals.clicks}</strong> ${L.total_label.toLowerCase()}</span>
      <span><strong>${totals.unique}</strong> ${L.unique_label.toLowerCase()}</span>
      ${CURRENT_TIER === 'pro' && totals.clicks > 0
        ? `<a href="${url('/api/links/' + data.link.id + '/clicks.csv')}" style="margin-left:auto;font-size:12px;text-decoration:none;opacity:.7">${L.csv_export} ↓</a>`
        : ''}
    </div>
  `;

  // Pro-only: device-type breakdown bars. Hidden for free users so the
  // upgrade incentive isn't undermined by partial visibility.
  const devices = (CURRENT_TIER === 'pro' && data.by_device && data.by_device.length)
    ? renderDeviceBreakdown(data.by_device, totals.clicks)
    : '';

  return `
    ${totalsRow}
    ${devices}
    <h3>${L.last30}</h3>
    <div class="bars">${bars}</div>
    ${recent ? `<div class="recent"><h3 style="margin-top:24px">${L.recent_clicks}</h3>${recent}</div>` : ''}
  `;
}

function renderDeviceBreakdown(rows, total) {
  if (!total) return '';
  const order = ['desktop', 'mobile', 'tablet', 'bot', 'unknown'];
  const colors = { desktop: '#3b82f6', mobile: '#10b981', tablet: '#a855f7', bot: '#9ca3af', unknown: '#d1d5db' };
  const sorted = [...rows].sort((a, b) =>
    order.indexOf(a.device) - order.indexOf(b.device));
  const segments = sorted.map(r => {
    const pct = (r.n / total) * 100;
    return `<div title="${r.device}: ${r.n} (${pct.toFixed(1)}%)" style="width:${pct}%;background:${colors[r.device] || '#888'};"></div>`;
  }).join('');
  const legend = sorted.map(r => `
    <span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;">
      <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${colors[r.device] || '#888'};"></span>
      ${r.device} <strong>${r.n}</strong>
    </span>
  `).join('');
  return `
    <h3 style="margin-top:24px">${L.devices_title}</h3>
    <div style="display:flex;height:8px;border-radius:4px;overflow:hidden;margin-bottom:8px;">${segments}</div>
    <div style="display:flex;gap:16px;flex-wrap:wrap;opacity:.85;">${legend}</div>
  `;
}

// ── Edit modal ──────────────────────────────────────────────────────────
let editModal = null;
function ensureEditModal() {
  if (editModal) return editModal;
  editModal = document.createElement('div');
  editModal.id = 'edit-modal';
  editModal.hidden = true;
  editModal.style.cssText =
    'position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:9998;' +
    'display:flex;align-items:center;justify-content:center;padding:16px;';
  editModal.innerHTML = `
    <div style="background:var(--field,#fff);color:var(--ink,#181613);
                border:1px solid var(--rule,#ddd);border-radius:12px;
                padding:24px;max-width:480px;width:100%;
                box-shadow:0 12px 40px rgba(0,0,0,.25);">
      <h3 style="margin:0 0 16px;">${L.edit_link}</h3>
      <form id="edit-form" autocomplete="off" style="display:grid;gap:14px;">
        <div>
          <label for="edit-slug" style="font-size:13px;">slug</label>
          <input type="text" id="edit-slug" pattern="[a-z0-9_-]{2,32}" maxlength="32" required>
        </div>
        <div>
          <label for="edit-target" style="font-size:13px;">target</label>
          <input type="url" id="edit-target" required>
        </div>
        <div>
          <label for="edit-expires" style="font-size:13px;">expires_at</label>
          <input type="datetime-local" id="edit-expires">
          <button type="button" id="edit-clear-expires" class="btn ghost small" style="margin-top:6px;font-size:11px;">${L.edit_clear_exp}</button>
        </div>
        <div>
          <label for="edit-password" style="font-size:13px;">password</label>
          <input type="password" id="edit-password" placeholder="${L.edit_keep_pw}" autocomplete="new-password">
          <button type="button" id="edit-clear-password" class="btn ghost small" style="margin-top:6px;font-size:11px;">${L.edit_clear_pw}</button>
        </div>
        <div class="auth-error" id="edit-error"></div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px;">
          <button type="button" class="btn ghost" id="edit-cancel">${L.cancel}</button>
          <button type="submit" class="btn primary">${L.save}</button>
        </div>
      </form>
    </div>
  `;
  document.body.appendChild(editModal);
  editModal.querySelector('#edit-cancel').addEventListener('click', closeEditModal);
  editModal.addEventListener('click', e => { if (e.target === editModal) closeEditModal(); });
  return editModal;
}

let editingLinkId = null;
let editClearPassword = false;
let editClearExpiry = false;

async function openEditModal(linkId) {
  ensureEditModal();
  editingLinkId = linkId;
  editClearPassword = false;
  editClearExpiry = false;
  // Fetch the latest stats endpoint so we have authoritative target/expiry.
  let data;
  try { data = await api(`/api/links/${linkId}/stats`); }
  catch { toast(L.load_fail, 'error'); return; }
  const link = data.link;
  document.getElementById('edit-slug').value = link.slug || '';
  document.getElementById('edit-target').value = link.target || '';
  const expEl = document.getElementById('edit-expires');
  expEl.value = link.expires_at
    ? new Date(link.expires_at - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16)
    : '';
  const pwEl = document.getElementById('edit-password');
  pwEl.value = '';
  pwEl.placeholder = link.has_password ? L.edit_keep_pw + ' (●●●)' : L.edit_keep_pw;
  document.getElementById('edit-error').classList.remove('show');
  editModal.hidden = false;
}

function closeEditModal() {
  if (editModal) editModal.hidden = true;
  editingLinkId = null;
}

document.addEventListener('click', e => {
  if (e.target.id === 'edit-clear-password') {
    editClearPassword = true;
    document.getElementById('edit-password').value = '';
    document.getElementById('edit-password').placeholder = L.edit_clear_pw + ' ✓';
  }
  if (e.target.id === 'edit-clear-expires') {
    editClearExpiry = true;
    document.getElementById('edit-expires').value = '';
  }
});

document.addEventListener('submit', async e => {
  if (e.target.id !== 'edit-form') return;
  e.preventDefault();
  if (!editingLinkId) return;
  const errEl = document.getElementById('edit-error');
  errEl.classList.remove('show');

  const body = {};
  body.slug   = document.getElementById('edit-slug').value.trim().toLowerCase();
  body.target = document.getElementById('edit-target').value.trim();

  const expVal = document.getElementById('edit-expires').value;
  if (editClearExpiry) {
    body.expires_at = null;
  } else if (expVal) {
    body.expires_at = new Date(expVal).getTime();
  }

  const pwVal = document.getElementById('edit-password').value;
  if (editClearPassword) {
    body.password = null;
  } else if (pwVal) {
    body.password = pwVal;
  }

  try {
    await api(`/api/links/${editingLinkId}`, { method: 'PATCH', body: JSON.stringify(body) });
    toast(L.saved);
    closeEditModal();
    await loadLinks();
  } catch (err) {
    const editErrors = {
      slug_taken: 'That slug is already taken.',
      slug_reserved: 'That slug is reserved.',
      invalid_slug: 'Invalid slug format.',
      invalid_url: 'Invalid URL.',
      url_too_long: 'URL too long.',
      invalid_protocol: 'Only http(s) URLs allowed.',
      password_too_long: 'Password too long (max 72 chars).',
      expiry_in_past: 'Expiry must be in the future.',
      expiry_too_far: 'Expiry too far in the future.',
      upgrade_required: 'Pro required for this feature.',
    };
    errEl.textContent = editErrors[err.message] || 'Save failed.';
    errEl.classList.add('show');
  }
});

logoutBtn.addEventListener('click', async (e) => {
  e.preventDefault();
  try { await api('/api/auth/logout', { method: 'POST' }); } catch {}
  location.href = url('/');
});

const iconCopy = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
const iconTrash = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>';
const iconEdit  = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>';

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function escapeAttr(s) { return escapeHtml(s); }

bootstrap();
