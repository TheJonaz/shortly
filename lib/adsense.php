<?php
declare(strict_types=1);

// Google AdSense integration. Off by default — only emits the loader script
// and reserves the ad slots when `adsense_client` is set in config.php.
// Pro tier never sees ads (gated at the call site).

function adsense_client(): string {
    return (string) (config()['adsense_client'] ?? '');
}

function adsense_is_configured(): bool {
    return adsense_client() !== '';
}

function adsense_slot(string $name): string {
    $slots = (array) (config()['adsense_slots'] ?? []);
    return (string) ($slots[$name] ?? '');
}
