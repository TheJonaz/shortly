// Tiny GDPR consent gate for the visit-tracking beacon.
//
// Behavior:
//  - First visit (no localStorage entry): show the banner. Beacon does NOT fire.
//  - "Accept" → store 'accepted', fire beacon now and on every subsequent visit.
//  - "Decline" → store 'declined', never fire beacon.
//  - "Privacy" link in footer clears the choice → reload re-prompts.
//
// No tracking happens without an explicit positive choice — that's the GDPR
// "freely given, specific, informed and unambiguous" requirement.

const KEY = 'shortly.consent';
// Optional analytics beacon URL. Set by views/_consent.php via
// `window.SHORTLY_BEACON_URL` when `consent_beacon_url` is configured.
// Empty/unset = no beacon fires; the banner is shown purely so the privacy
// story is honest about the (functional-only) cookies the app does set.
const BEACON_URL = (typeof window !== 'undefined' && window.SHORTLY_BEACON_URL) || '';

function readChoice() {
  try { return localStorage.getItem(KEY); } catch { return null; }
}
function writeChoice(v) {
  try { localStorage.setItem(KEY, v); } catch {}
}
function clearChoice() {
  try { localStorage.removeItem(KEY); } catch {}
}

function fireBeacon() {
  if (BEACON_URL && navigator.sendBeacon) {
    navigator.sendBeacon(BEACON_URL);
  }
}

function dismissBanner(banner) {
  if (banner) banner.hidden = true;
}

(function init() {
  const banner = document.getElementById('consent-banner');
  const resetLink = document.getElementById('consent-reset');

  // Bind the footer "Privacy" reset regardless of current choice — GDPR
  // requires withdrawal to be as easy as giving consent.
  if (resetLink) {
    resetLink.addEventListener('click', (e) => {
      e.preventDefault();
      clearChoice();
      location.reload();
    });
  }

  const choice = readChoice();
  if (choice === 'accepted') {
    fireBeacon();
    return;
  }
  if (choice === 'declined') {
    return;
  }

  // No choice yet → reveal the banner and wire up the buttons.
  if (!banner) return;
  banner.hidden = false;

  const accept = banner.querySelector('[data-consent="accept"]');
  const decline = banner.querySelector('[data-consent="decline"]');

  accept?.addEventListener('click', () => {
    writeChoice('accepted');
    fireBeacon();
    dismissBanner(banner);
  });
  decline?.addEventListener('click', () => {
    writeChoice('declined');
    dismissBanner(banner);
  });
})();
