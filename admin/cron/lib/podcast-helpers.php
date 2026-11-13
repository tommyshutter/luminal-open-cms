<?php
/**
 * Podcast Helpers — Generic podcast pipeline library
 *
 * Site-agnostic season/episode management, filename conventions,
 * duplicate prevention, registry, pruning, TTS synthesis.
 *
 * Season epoch: March 2026 = S01
 */
declare(strict_types=1);

// ── Site Identity ──────────────────────────────────────

/**
 * Derive a filename-safe site slug from the domain.
 * e.g. "example.com" → "example-com"
 *      "example.com"    → "example-com"
 */
function podcastSiteSlug(): string
{
    $siteRoot = defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 3);
    $domain = basename($siteRoot);
    return preg_replace('/[^a-z0-9]+/', '-', strtolower($domain));
}

// ── Season / Episode ───────────────────────────────────

/**
 * Compute season number from a date string.
 * March 2026 = S01, April 2026 = S02, etc.
 */
function podcastComputeSeason(string $date): int
{
    $dt = new DateTime($date);
    return ((int)$dt->format('Y') - 2026) * 12 + ((int)$dt->format('n') - 3) + 1;
}

/**
 * Mint the next episode number for a show within a season.
 * Reads the per-show registry and returns max(episode) + 1 for that season.
 */
function podcastMintEpisodeNumber(string $showSlug, int $season): int
{
    $registry = podcastLoadShowRegistry($showSlug);
    $maxEp = 0;
    foreach ($registry as $ep) {
        if (($ep['season'] ?? 0) === $season) {
            $maxEp = max($maxEp, $ep['episode'] ?? 0);
        }
    }
    return $maxEp + 1;
}

// ── Duplicate Prevention ───────────────────────────────

/**
 * Check if an episode already exists for a show on a given date.
 * Returns the existing episode meta if found, null otherwise.
 */
function podcastEpisodeExistsForDate(string $showSlug, string $date): ?array
{
    $registry = podcastLoadShowRegistry($showSlug);
    foreach ($registry as $ep) {
        if (($ep['date'] ?? '') === $date) {
            return $ep;
        }
    }
    return null;
}

// ── Filename ───────────────────────────────────────────

/**
 * Build the canonical filename for an episode.
 * Uses the site domain slug — no hardcoded site or character names.
 * Example: example-com-S01E03-episode-title-2026-03-13-1600.mp3
 */
function podcastBuildFilename(string $showSlug, int $season, int $episode, array $hosts, string $date, string $time24): string
{
    $siteSlug = podcastSiteSlug();
    $seasonStr = sprintf('S%02dE%02d', $season, $episode);
    $hostStr = implode('-', $hosts);
    return "{$siteSlug}-{$seasonStr}-{$showSlug}-{$hostStr}-{$date}-{$time24}.mp3";
}

// ── Registry ───────────────────────────────────────────

/**
 * Load a show's episode registry.
 */
function podcastLoadShowRegistry(string $showSlug): array
{
    $path = podcastShowRegistryPath($showSlug);
    if (!is_file($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

/**
 * Register an episode in the per-show ledger.
 * Rejects duplicates: only one episode per show per date.
 * Returns true if registered, false if duplicate blocked.
 */
function podcastRegisterEpisode(string $showSlug, array $meta): bool
{
    $registry = podcastLoadShowRegistry($showSlug);
    $date = $meta['date'] ?? '';
    if ($date) {
        foreach ($registry as $ep) {
            if (($ep['date'] ?? '') === $date) {
                return false;
            }
        }
    }
    $registry[] = $meta;
    $path = podcastShowRegistryPath($showSlug);
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    file_put_contents($path, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return true;
}

/**
 * Path to a show's registry file.
 */
function podcastShowRegistryPath(string $showSlug): string
{
    $siteRoot = defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 3);
    return $siteRoot . '/admin/data/podcasts/shows/' . $showSlug . '/episodes.json';
}

// ── Pruning ────────────────────────────────────────────

/**
 * Prune MP3 files older than $maxAgeDays from the episodes directory.
 * Skips files whose guid appears as pinned in any show registry.
 * Returns array of deleted filenames.
 */
function podcastPruneOldMp3s(string $episodesDir, int $maxAgeDays = 14): array
{
    $cutoff = time() - ($maxAgeDays * 86400);

    $pinned = [];
    $siteRoot = defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 3);
    $showsDir = $siteRoot . '/admin/data/podcasts/shows';
    foreach (glob($showsDir . '/*/episodes.json') as $regFile) {
        $episodes = json_decode(file_get_contents($regFile), true) ?: [];
        foreach ($episodes as $ep) {
            if (!empty($ep['pinned'])) {
                $pinned[$ep['filename'] ?? ''] = true;
            }
        }
    }

    $deleted = [];
    foreach (glob($episodesDir . '/*.mp3') as $file) {
        $basename = basename($file);
        if (isset($pinned[$basename])) continue;
        if (filemtime($file) < $cutoff) {
            unlink($file);
            $deleted[] = $basename;
        }
    }
    return $deleted;
}

// ── TTS Providers ──────────────────────────────────────

/**
 * TTS synthesis via ElevenLabs API.
 */
function podcastTtsElevenLabs(string $apiKey, string $voiceId, string $text, string $modelId = 'eleven_multilingual_v2'): ?string
{
    $url = "https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}";
    $payload = json_encode([
        'text'           => $text,
        'model_id'       => $modelId,
        'voice_settings' => ['stability' => 0.5, 'similarity_boost' => 0.75],
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'xi-api-key: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => $payload,
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200 || !$body) return null;
    return $body;
}

/**
 * TTS synthesis via OpenAI TTS API.
 */
function podcastTtsOpenAI(string $apiKey, string $voice, string $text, string $model = 'tts-1-hd'): ?string
{
    $url = 'https://api.openai.com/v1/audio/speech';
    $payload = json_encode([
        'model' => $model,
        'voice' => $voice,
        'input' => $text,
        'response_format' => 'mp3',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => $payload,
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200 || !$body) return null;
    return $body;
}

/**
 * TTS synthesis via Google Cloud Text-to-Speech API.
 */
function podcastTtsSynthesize(string $url, string $text, array $voice): ?string
{
    $langCode = 'en-US';
    if (preg_match('/^(en-[A-Z]{2})/', $voice['name'], $lm)) {
        $langCode = $lm[1];
    }

    $audioConfig = [
        'audioEncoding' => 'MP3',
        'speakingRate'  => $voice['speakingRate'] ?? 1.0,
        'effectsProfileId' => ['headphone-class-device'],
    ];
    if (strpos($voice['name'], 'Chirp3') === false && isset($voice['pitch'])) {
        $audioConfig['pitch'] = $voice['pitch'];
    }

    $payload = [
        'input' => ['text' => $text],
        'voice' => ['languageCode' => $langCode, 'name' => $voice['name']],
        'audioConfig' => $audioConfig,
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) return null;
    $result = json_decode($body, true);
    $audio = $result['audioContent'] ?? '';
    return $audio ? base64_decode($audio) : null;
}

// ── Script Parsing ─────────────────────────────────────

/**
 * Split text into TTS-safe chunks by sentence boundary.
 */
function podcastSplitForTts(string $text, int $maxBytes = 4800): array
{
    if (strlen($text) <= $maxBytes) return [$text];
    $sentences = preg_split('/(?<=[.!?…])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $chunks = [];
    $current = '';
    foreach ($sentences as $s) {
        if (strlen($current) + strlen($s) + 1 > $maxBytes) {
            if ($current !== '') $chunks[] = trim($current);
            $current = $s;
        } else {
            $current .= ($current !== '' ? ' ' : '') . $s;
        }
    }
    if ($current !== '') $chunks[] = trim($current);
    return $chunks;
}

/**
 * Parse a multi-voice script into speaker segments.
 * Supports both [SPEAKER] bracket and SPEAKER: colon tag formats.
 * Speaker names are generic — works with any cast (Chase, Professor, Rex, etc.)
 * Returns array of ['speaker' => 'CHASE'|'PROFESSOR'|..., 'text' => '...']
 */
function podcastParseSegments(string $script, string $defaultSpeaker = 'HOST1'): array
{
    $lines = preg_split('/\n+/', trim($script));
    $segments = [];
    $currentSpeaker = $defaultSpeaker;
    $currentText = '';

    $pattern = '/^(?:\[([A-Z][A-Z0-9_]+)\]|([A-Z][A-Z0-9_]+):)\s*(.*)$/i';

    foreach ($lines as $line) {
        $line = trim($line);
        if (!$line) continue;
        if (preg_match($pattern, $line, $m)) {
            if ($currentText !== '') {
                $segments[] = ['speaker' => $currentSpeaker, 'text' => trim($currentText)];
            }
            $currentSpeaker = strtoupper($m[1] ?: $m[2]);
            $currentText = $m[3];
        } else {
            $currentText .= ' ' . $line;
        }
    }
    if ($currentText !== '') {
        $segments[] = ['speaker' => $currentSpeaker, 'text' => trim($currentText)];
    }
    return $segments;
}
