<?php
/**
 * Luminal CMS — Footer social-icon resolution (shared library)
 * @file /includes/social_icons.php
 *
 * ONE source of truth for "given a platform name (or a URL), which icon file
 * do we draw?". Consumed by:
 *   - /footer.php                                  (render time)
 *   - /admin/modules/SiteSettings/api.php          (the admin picker's option list)
 *   - /admin/scripts/footer-convert.php            (the custom_html → form converter)
 *
 * FLATPACK RULE: every icon resolves to a file that already ships in
 * /panels/footer_images/. No CDN, no remote font, no invented SVG glyphs.
 * A platform with no shipped icon renders as a styled text pill instead —
 * that is deliberate, not a gap to paper over with a wrong-looking glyph.
 *
 * panels/ is never purged (feedback_panels_never_purged_sweep_technique), so
 * these paths are stable on every site in the fleet.
 */

declare(strict_types=1);

if (!function_exists('luminal_social_icon_map')) {
/**
 * platform-key => icon file under /panels/footer_images/.
 * Keys are already normalised (lowercase, alphanumerics only).
 */
function luminal_social_icon_map(): array {
    return [
        'facebook'       => 'facebook-icon.png',
        'fb'             => 'facebook-icon.png',
        'instagram'      => 'insta_color.png',
        'insta'          => 'insta_color.png',
        'ig'             => 'insta_color.png',
        'youtube'        => 'youtube.jpg',
        'yt'             => 'youtube.jpg',
        'tiktok'         => 'tik-tok.png',
        'x'              => 'twitterx.png',
        'twitter'        => 'twitterx.png',
        'email'          => 'email.png',
        'mail'           => 'email.png',
        'contact'        => 'email.png',
        'applepodcasts'  => 'Apple_PodCasts.png',
        'apple'          => 'Apple_PodCasts.png',
        'podcasts'       => 'Apple_PodCasts.png',
    ];
}
}

if (!function_exists('luminal_social_key')) {
/** Normalise a free-text platform label to a map key ("Tik Tok" → "tiktok"). */
function luminal_social_key(string $platform): string {
    return (string)preg_replace('/[^a-z0-9]/', '', strtolower(trim($platform)));
}
}

if (!function_exists('luminal_social_platform_from_url')) {
/**
 * Best-effort platform name from a link URL. Used by the converter so a
 * hand-written <a href="https://tiktok.com/@x"> becomes platform "TikTok"
 * without anyone having to type it. Returns '' when nothing is recognised.
 */
function luminal_social_platform_from_url(string $url): string {
    $u = strtolower(trim($url));
    if ($u === '') return '';
    if (str_starts_with($u, 'mailto:')) return 'Email';
    $host = (string)parse_url($u, PHP_URL_HOST);
    if ($host === '') return '';
    $host = preg_replace('/^www\./', '', $host);
    $known = [
        'facebook.com'      => 'Facebook',
        'fb.com'            => 'Facebook',
        'instagram.com'     => 'Instagram',
        'youtube.com'       => 'YouTube',
        'youtu.be'          => 'YouTube',
        'tiktok.com'        => 'TikTok',
        'x.com'             => 'X',
        'twitter.com'       => 'X',
        'podcasts.apple.com'=> 'Apple Podcasts',
    ];
    foreach ($known as $needle => $label) {
        if ($host === $needle || str_ends_with($host, '.' . $needle)) return $label;
    }
    return '';
}
}

if (!function_exists('luminal_social_icon')) {
/**
 * Resolve the icon for one social row.
 *
 * @param string $platform  free-text label from the admin form
 * @param string $icon      explicit path the admin chose ('' = auto)
 * @param string $siteRoot  absolute site root; '' skips the existence check
 * @return string           site-absolute URL, or '' to render text instead
 *
 * An explicit choice always wins — including the sentinel 'none', which is how
 * an admin says "this one is a TEXT link, don't auto-icon it".
 */
function luminal_social_icon(string $platform, string $icon = '', string $siteRoot = ''): string {
    $icon = trim($icon);
    if ($icon === 'none') return '';
    if ($icon !== '') {
        // Explicit path. Keep it site-absolute and reject anything trying to
        // climb out of the webroot; this string lands in a src= attribute.
        if (str_contains($icon, '..')) return '';
        return '/' . ltrim($icon, '/');
    }
    $file = luminal_social_icon_map()[luminal_social_key($platform)] ?? '';
    if ($file === '') return '';
    if ($siteRoot !== '' && !is_file($siteRoot . '/panels/footer_images/' . $file)) return '';
    return '/panels/footer_images/' . $file;
}
}

if (!function_exists('luminal_footer_icon_choices')) {
/**
 * Every icon the admin picker may offer on THIS site: the shipped
 * /panels/footer_images/ contents, newest naming first. Returns
 * [['value' => 'panels/footer_images/tik-tok.png', 'label' => 'tik-tok.png'], …]
 */
function luminal_footer_icon_choices(string $siteRoot): array {
    $dir = rtrim($siteRoot, '/') . '/panels/footer_images';
    if (!is_dir($dir)) return [];
    $out = [];
    foreach (scandir($dir) ?: [] as $f) {
        if ($f[0] === '.') continue;
        if (!preg_match('/\.(png|jpg|jpeg|gif|webp|svg)$/i', $f)) continue;
        $out[] = ['value' => 'panels/footer_images/' . $f, 'label' => $f];
    }
    usort($out, fn($a, $b) => strnatcasecmp($a['label'], $b['label']));
    return $out;
}
}
