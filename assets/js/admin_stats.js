import { api, url, bindThemeToggle } from './common.js';

bindThemeToggle(document.getElementById('theme-toggle'));

const cards   = document.getElementById('stat-cards');
const ctyWrap = document.getElementById('countries-wrap');
const toastEl = document.getElementById('admin-toast');

function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c =>
    ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function fmtNum(n) {
  // Sv/En grouping separator; falls back to plain digits on non-locale clients.
  try { return Number(n).toLocaleString(); } catch { return String(n); }
}
function showError(msg) {
  toastEl.textContent = msg;
  toastEl.className = 'admin-toast error';
  toastEl.hidden = false;
  setTimeout(() => { toastEl.hidden = true; }, 4000);
}

// ISO 3166-1 alpha-2 → flag emoji. Country codes are uppercase A–Z, so
// shift each char to its Regional Indicator Symbol (U+1F1E6 + offset).
// "??" / unknown falls back to a globe.
function countryFlag(code) {
  if (!code || code === '??' || code.length !== 2) return '🌐';
  const A = 0x1F1E6;
  const cps = [...code.toUpperCase()].map(c => A + (c.charCodeAt(0) - 65));
  if (cps.some(c => c < A || c > A + 25)) return '🌐';
  return String.fromCodePoint(...cps);
}

// Hardcoded EN names for the common cases — keeps the page readable
// without pulling a full locale data table. Unknown codes just show the
// raw ISO code, which is still informative.
const COUNTRY_NAMES = {
  SE:'Sweden', NO:'Norway', DK:'Denmark', FI:'Finland', IS:'Iceland',
  US:'United States', GB:'United Kingdom', IE:'Ireland',
  DE:'Germany', FR:'France', ES:'Spain', IT:'Italy', NL:'Netherlands',
  BE:'Belgium', AT:'Austria', CH:'Switzerland', PT:'Portugal',
  PL:'Poland', CZ:'Czechia', HU:'Hungary', RO:'Romania',
  EE:'Estonia', LV:'Latvia', LT:'Lithuania',
  RU:'Russia', UA:'Ukraine',
  CA:'Canada', MX:'Mexico', BR:'Brazil', AR:'Argentina',
  AU:'Australia', NZ:'New Zealand',
  JP:'Japan', KR:'South Korea', CN:'China', TW:'Taiwan', HK:'Hong Kong',
  IN:'India', PK:'Pakistan', SG:'Singapore', TH:'Thailand', ID:'Indonesia',
  PH:'Philippines', MY:'Malaysia', VN:'Vietnam',
  IL:'Israel', AE:'United Arab Emirates', SA:'Saudi Arabia', TR:'Türkiye',
  ZA:'South Africa', EG:'Egypt', NG:'Nigeria', KE:'Kenya', MA:'Morocco',
};

function setCardValue(key, val) {
  const card = cards.querySelector(`[data-stat="${key}"] .stat-value`);
  if (card) card.textContent = fmtNum(val);
}

function renderCountries(rows) {
  if (!rows.length) {
    ctyWrap.innerHTML = '<p class="muted">No clicks recorded yet in the last 30 days.</p>';
    return;
  }
  const total = rows.reduce((s, r) => s + Number(r.count), 0);
  let html = '<table class="admin-table"><thead><tr>'
           + '<th></th><th>Country</th><th>Clicks</th><th>Share</th></tr></thead><tbody>';
  for (const r of rows) {
    const code = r.country;
    const label = COUNTRY_NAMES[code] || (code === '??' ? 'Unknown' : code);
    const pct = total > 0 ? ((r.count / total) * 100).toFixed(1) + '%' : '—';
    html += `<tr>
      <td style="font-size:18px;line-height:1;width:24px;">${countryFlag(code)}</td>
      <td>${escapeHtml(label)} <span class="mono faint" style="margin-left:6px;font-size:11px;">${escapeHtml(code)}</span></td>
      <td class="mono">${fmtNum(r.count)}</td>
      <td class="mono faint">${pct}</td>
    </tr>`;
  }
  html += '</tbody></table>';
  ctyWrap.innerHTML = html;
}

async function load() {
  try {
    const r = await api('/api/admin/stats');
    setCardValue('totals-all', r.totals.all_time);
    setCardValue('totals-30',  r.totals.days_30);
    setCardValue('totals-7',   r.totals.days_7);
    setCardValue('uniq-all',   r.unique.all_time);
    setCardValue('uniq-30',    r.unique.days_30);
    setCardValue('uniq-7',     r.unique.days_7);
    renderCountries(r.countries || []);
  } catch (err) {
    showError('Could not load stats: ' + err.message);
    ctyWrap.innerHTML = '';
  }
}

load();
