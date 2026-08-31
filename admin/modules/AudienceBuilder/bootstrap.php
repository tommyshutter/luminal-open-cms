<?php
/**
 * AudienceBuilder defaults seeder.
 *
 * AudienceBuilder is base "standard equipment" (ships in the `content` package
 * to every site), but admin/data is NOT deployed — so each site must seed its
 * own defaults: an ENABLED, STANDALONE config (leads kept local; hub-mirror is
 * an opt-in via hub_url/hub_enabled → AudienceCollector on the hub) plus the
 * two standard forms (contact + email signup).
 *
 * Idempotent: only creates what's missing; never clobbers a client's custom
 * config or a form slug they already have.
 */
declare(strict_types=1);

if (!function_exists('ab_ensure_defaults')) {
/**
 * Create the two standard forms (contact + email signup) + an enabled standalone
 * config, applying $style (default|glass|bare) to the forms. Idempotent: only
 * creates a slug that's absent — never overwrites a client's form.
 * On-demand only (the admin "Create basic forms" button); not auto-run.
 */
function ab_ensure_defaults(string $siteRoot, string $style = 'default', string $pixel = ''): array {
    $created  = [];
    $style    = in_array($style, ['default', 'glass', 'bare'], true) ? $style : 'default';
    $pixel    = preg_replace('/[^A-Za-z0-9._-]/', '', $pixel);
    $dataDir  = $siteRoot . '/admin/data/AudienceBuilder';
    $formsDir = $dataDir . '/forms';
    $defDir   = __DIR__ . '/defaults';
    if (!is_dir($formsDir)) @mkdir($formsDir, 0775, true);

    // Config — enable (gates the public ab-forms.css + ab-form-hook.js injection
    // in footer.php) in STANDALONE mode. Hub-mirror stays opt-in.
    $cfgFile = $dataDir . '/config.json';
    if (!is_file($cfgFile)) {
        @file_put_contents($cfgFile, json_encode(
            ['enabled' => true, 'store_leads' => true],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ));
        $created[] = 'config.json';
    } else {
        // ensure enabled when the admin explicitly asks to create the forms
        $cfg = json_decode((string) file_get_contents($cfgFile), true) ?: [];
        if (empty($cfg['enabled'])) {
            $cfg['enabled'] = true;
            @file_put_contents($cfgFile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    foreach (['contact', 'signup'] as $slug) {
        $dst = $formsDir . '/' . $slug . '.json';
        $src = $defDir . '/' . $slug . '.json';
        if (!is_file($dst) && is_file($src)) {
            $tpl = json_decode((string) file_get_contents($src), true) ?: [];
            $tpl['id']         = $tpl['id'] ?? ('f-seed-' . $slug);
            $tpl['form_style'] = $style;
            if ($pixel !== '') {
                $tpl['tracking'] = ['pixel_id' => $pixel, 'conversion_event' => $slug . '_submit'];
            }
            $tpl['created_at'] = $tpl['created_at'] ?? gmdate('c');
            @file_put_contents($dst, json_encode($tpl, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $created[] = "forms/$slug.json";
        }
    }
    return $created;
}
}

// CLI seed: php bootstrap.php /path/to/site
if (PHP_SAPI === 'cli' && isset($argv[1])) {
    $r = ab_ensure_defaults(rtrim($argv[1], '/'));
    echo basename($argv[1]) . ': ' . ($r ? 'seeded ' . implode(', ', $r) : 'already complete') . "\n";
}
