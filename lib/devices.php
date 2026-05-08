<?php
declare(strict_types=1);

// Best-effort User-Agent → device-type classifier. Pure-PHP, no external
// data — good enough for "what fraction of clicks are mobile vs desktop".
//
// Returns one of:
//   'bot'      — search crawlers, monitoring, link previewers
//   'tablet'   — iPads, large Android tablets
//   'mobile'   — phones
//   'desktop'  — anything else with a recognisable browser hint
//   'unknown'  — empty/garbage UA
//
// Order matters: tablet before mobile (iPads contain "Mobile"-like tokens
// in some UAs); bot before everything (some bots impersonate browsers but
// most include "bot"/"crawler" in the UA string).

function device_type_from_ua(?string $ua): string {
    if ($ua === null || $ua === '') return 'unknown';
    $ua = (string) $ua;

    // Bot patterns — standard list of well-known crawlers, plus the catch-all
    // /bot|crawler|spider/i. Link previewers (Facebook, Slack, Discord, etc.)
    // are bots too — they fetch the URL to render an embed.
    if (preg_match('~(?:bot|crawler|spider|slurp|mediapartners|facebookexternalhit|twitterbot|linkedinbot|slackbot|discordbot|telegrambot|whatsapp|bingpreview|google-?image|chrome-lighthouse|headlesschrome|phantomjs|wget|curl|axios|python-requests|libwww-perl|httpclient|okhttp)~i', $ua)) {
        return 'bot';
    }

    // Tablet — match iPad and large Android tablets (which usually omit
    // "Mobile" from their UA). Done before mobile because some Android
    // tablets still include "Android" without the Mobile flag.
    if (preg_match('~ipad|tablet|playbook|kindle|silk|nexus 7|nexus 9|nexus 10~i', $ua)) {
        return 'tablet';
    }
    if (preg_match('~android~i', $ua) && !preg_match('~mobile~i', $ua)) {
        return 'tablet';
    }

    // Mobile — most phones. iPhone is explicit; Android with Mobile flag;
    // various legacy mobile prefixes.
    if (preg_match('~iphone|ipod|android.*mobile|windows phone|blackberry|bb10|opera mini|opera mobi|iemobile~i', $ua)) {
        return 'mobile';
    }

    // Anything else with a recognisable browser engine → desktop. UAs we
    // can't classify at all stay 'unknown' so they don't pollute desktop.
    if (preg_match('~chrome|firefox|safari|edge|trident|msie|opera|gecko~i', $ua)) {
        return 'desktop';
    }

    return 'unknown';
}
