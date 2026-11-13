<?php
/**
 * hero_art.php — Generate a fresh hero picture for ONE article.
 *
 * The same engine as the podcast Generate Cover:
 * Gemini writes a brief from the article, the image model paints it. Differences: 16:9 (the hero slider
 * and the article page are wide), no title band, so the whole frame is the picture.
 *
 * The key is the site's own Google key from AI Resources. Nothing here holds or prints a key.
 *
 * Never destructive: each picture is a NEW file, /media/images/articles/{slug}-ai-{time}.jpg. The
 * article's previous hero_image is remembered in admin/data/ArticlesManager/hero-history.json (kept
 * apart from the article, so a normal Save in the editor cannot wipe it) and can be put back.
 *
 *   am_hero_generate($siteRoot, $domain, $store, $slug, $direction) → ['ok'=>bool, 'hero_image'=>…, 'prev'=>…, 'error'=>…]
 *   am_hero_restore($siteRoot, $store, $slug)                       → ['ok'=>bool, 'hero_image'=>…, 'prev'=>…, 'error'=>…]
 */
declare(strict_types=1);

if (!function_exists('am_hero_generate')) {

define('AM_HERO_BRIEF_MODEL', 'gemini-2.5-flash');
define('AM_HERO_IMAGE_MODEL', 'gemini-3.1-flash-image');

/** The site's Google key, decrypted through the site's own vault ('' if none). */
function am_hero_key(string $siteRoot): string {
    $reg = $siteRoot . '/admin/modules/AIResources/providers/ProviderRegistry.php';
    $cf  = $siteRoot . '/admin/data/AIResources/config.json';
    if (!is_file($reg) || !is_file($cf)) return '';
    require_once $reg;
    $cfg = (new ProviderRegistry($cf))->getConfig();
    foreach (($cfg['providers'] ?? []) as $p) {
        $k = (string)($p['apiKey'] ?? '');
        if (($p['type'] ?? '') === 'google' && $k !== '' && strncmp($k, 'ENC:', 4) !== 0) return $k;
    }
    return '';
}

function am_hero_gemini(string $model, array $body, string $key, int $timeout): array {
    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_HTTPHEADER => ['Content-Type: application/json', "x-goog-api-key: $key"]]);
    $raw = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, json_decode($raw, true) ?: []];
}

/** Gemini's own words for a failure, short enough to show beside the button. */
function am_hero_why(int $code, array $j, string $what): string {
    $m = (string)($j['error']['message'] ?? ($j['candidates'][0]['finishReason'] ?? ''));
    if ($code === 402 || stripos($m, 'prepayment') !== false) $m = 'the Gemini prepaid credit is used up — top it up in Google AI Studio';
    return "$what failed (HTTP $code" . ($m !== '' ? ': ' . mb_substr($m, 0, 160) : '') . ')';
}

function am_hero_brief(array $a, string $domain, string $direction, string $key, ?string &$err): ?string {
    $body = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string)($a['body_html'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $ask = "You are the photo editor choosing the lead picture for ONE magazine article.\n"
         . "Site: $domain\nSection: " . ($a['eyebrow'] ?? '') . "\nHeadline: " . strip_tags((string)($a['title'] ?? '')) . "\n"
         . "Standfirst: " . strip_tags((string)($a['excerpt'] ?? '')) . "\nArticle (excerpt): " . mb_substr($body, 0, 4000) . "\n"
         . ($direction !== '' ? "Editor's direction for this picture (follow it): $direction\n" : '')
         . "\nWrite ONE image-generation prompt, 60 to 110 words, for a WIDE 16:9 editorial photograph that shows what THIS article is about, "
         . "set where the article is set.\nRules: no real people and no recognisable likeness of any public figure; people, if any, are "
         . "anonymous, seen from behind, at a distance or out of focus. No brand logos, no trademarks, no copyrighted characters. No text, "
         . "letters or numbers anywhere — signs, screens, labels and packaging are blank, turned away or softly out of focus. Keep the main "
         . "subject in the upper two thirds; the lower third will carry the headline. Natural light, rich colour, magazine-cover quality, "
         . "photographic, not illustration.\nReply with the prompt only.";
    [$code, $j] = am_hero_gemini(AM_HERO_BRIEF_MODEL, ['contents' => [['role' => 'user', 'parts' => [['text' => $ask]]]],
        'generationConfig' => ['maxOutputTokens' => 600, 'temperature' => 0.9, 'thinkingConfig' => ['thinkingBudget' => 0]]], $key, 60);
    $t = trim((string)($j['candidates'][0]['content']['parts'][0]['text'] ?? ''));
    if ($code === 200 && strlen($t) > 40) return $t;
    $err = am_hero_why($code, $j, 'Brief'); return null;
}

/** Paint the brief, then write a 1600x900 JPEG under ~400 KB to $out. */
function am_hero_picture(string $prompt, string $key, string $out, ?string &$err): bool {
    [$code, $j] = am_hero_gemini(AM_HERO_IMAGE_MODEL, ['contents' => [['parts' => [['text' => 'Generate an image. ' . $prompt
        . ' Absolutely no readable text, letters, numbers, logos or watermark anywhere.']]]],
        'generationConfig' => ['responseModalities' => ['IMAGE'], 'imageConfig' => ['aspectRatio' => '16:9']]], $key, 240);
    $b64 = '';
    foreach (($j['candidates'][0]['content']['parts'] ?? []) as $part) if (!empty($part['inlineData']['data'])) $b64 = $part['inlineData']['data'];
    if ($code !== 200 || $b64 === '') { $err = am_hero_why($code, $j, 'Picture'); return false; }
    $src = @imagecreatefromstring((string)base64_decode($b64));
    if (!$src) { $err = 'The picture came back unreadable'; return false; }
    // Cover-fit to exactly 1600x900 (the model's 16:9 is close but not always exact).
    $sw = imagesx($src); $sh = imagesy($src); $W = 1600; $H = 900;
    $scale = max($W / $sw, $H / $sh); $cw = (int)round($W / $scale); $ch = (int)round($H / $scale);
    $dst = imagecreatetruecolor($W, $H);
    imagecopyresampled($dst, $src, 0, 0, (int)(($sw - $cw) / 2), (int)(($sh - $ch) / 2), $W, $H, $cw, $ch);
    imagedestroy($src);
    imageinterlace($dst, true);
    $ok = false;
    foreach ([84, 78, 72, 66] as $q) {
        $ok = imagejpeg($dst, $out, $q);
        clearstatcache(true, $out);
        if (!$ok || filesize($out) <= 400 * 1024) break;
    }
    imagedestroy($dst);
    if (!$ok) $err = 'Could not save the picture';
    return $ok;
}

/** hero-history.json: {slug: [older hero_image, …newest last]} — the "put back" trail. */
function am_hero_history_file(string $siteRoot): string { return $siteRoot . '/admin/data/ArticlesManager/hero-history.json'; }
function am_hero_history(string $siteRoot): array {
    $f = am_hero_history_file($siteRoot);
    return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
}
function am_hero_history_save(string $siteRoot, array $h): void {
    $f = am_hero_history_file($siteRoot); $t = $f . '.tmp';
    file_put_contents($t, json_encode($h, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); rename($t, $f);
}
function am_hero_prev(string $siteRoot, string $slug): string {
    $h = am_hero_history($siteRoot)[$slug] ?? [];
    return $h ? (string)end($h) : '';
}

function am_hero_generate(string $siteRoot, string $domain, \ArticlesManager\ArticleStore $store, string $slug, string $direction = ''): array {
    @set_time_limit(330);
    $a = $store->get($slug);
    if (!$a) return ['ok' => false, 'error' => 'Article not found'];
    $key = am_hero_key($siteRoot);
    if ($key === '') return ['ok' => false, 'error' => 'This site has no Google (Gemini) key yet: add one in AI Resources'];
    $direction = mb_substr(trim(strip_tags($direction)), 0, 300);
    $err = null;
    $brief = am_hero_brief($a, $domain, $direction, $key, $err);
    if ($brief === null) return ['ok' => false, 'error' => $err];
    $dir = $siteRoot . '/media/images/articles';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $safe = preg_replace('/[^a-z0-9-]+/', '-', strtolower($slug));
    $rel = '/media/images/articles/' . $safe . '-ai-' . date('Ymd-His') . '.jpg';
    if (!am_hero_picture($brief, $key, $siteRoot . $rel, $err)) return ['ok' => false, 'error' => $err];
    @file_put_contents($siteRoot . preg_replace('/\.jpg$/', '.txt', $rel), AM_HERO_IMAGE_MODEL . "\n" . ($direction !== '' ? "direction: $direction\n" : '') . "$brief\n");
    $prev = (string)($a['hero_image'] ?? '');
    // Remember the old picture only if there is one to come back to (several pipeline articles point at a
    // file that was never written).
    $prevReal = $prev !== '' && (preg_match('~^https?://~i', $prev) || is_file($siteRoot . '/' . ltrim($prev, '/')));
    if ($prevReal) {
        $h = am_hero_history($siteRoot);
        $h[$slug] = array_slice(array_merge($h[$slug] ?? [], [$prev]), -10);
        am_hero_history_save($siteRoot, $h);
    }
    $a['hero_image'] = $rel;
    $store->save($a);
    return ['ok' => true, 'hero_image' => $rel, 'prev' => am_hero_prev($siteRoot, $slug)];
}

/** Put the previous picture back; the one it replaces is NOT deleted (its file stays on disk). */
function am_hero_restore(string $siteRoot, \ArticlesManager\ArticleStore $store, string $slug): array {
    $a = $store->get($slug);
    if (!$a) return ['ok' => false, 'error' => 'Article not found'];
    $h = am_hero_history($siteRoot);
    if (empty($h[$slug])) return ['ok' => false, 'error' => 'No earlier picture to put back'];
    $back = (string)array_pop($h[$slug]);
    if (!$h[$slug]) unset($h[$slug]);
    am_hero_history_save($siteRoot, $h);
    $a['hero_image'] = $back;
    $store->save($a);
    return ['ok' => true, 'hero_image' => $back, 'prev' => am_hero_prev($siteRoot, $slug)];
}

}
