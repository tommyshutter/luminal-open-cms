<?php
/**
 * SpamGuard — lightweight, dependency-free heuristics to reject bot/jibberish
 * form submissions at the earliest server-side choke point.
 *
 * Designed against real observed bot traffic on the lead-capture forms
 * (AudienceBuilder / panels/ab-submit.php). The signatures are deliberately
 * conservative — they target the unmistakable machine patterns and let
 * anything human-plausible through:
 *
 *   - Name = a single no-space token of random mixed case
 *            e.g. "mzrFdesiVHwTNSoAIUQ", "KqVqQqzgPmfbEShrPVGGiaT"
 *   - Email = Gmail-style dot-injection spam (Gmail ignores dots, so one inbox
 *            spawns endless variants) e.g. "b.al.a.n.o.w.u.h.aj43.6@gmail.com"
 *   - Honeypot = a hidden field a human never sees but a bot fills.
 *
 * Real leads in the same stream ("Randall Davis" / randean45@icloud.com,
 * "Joe Murray" / coachjoe44@comcast.net) pass cleanly.
 *
 * Usage:
 *   require_once SITE_ROOT . '/admin/lib/SpamGuard.php';
 *   $reason = spamguard_check_lead($name, $email, $fields);
 *   if ($reason !== null) { ... reject / drop / log ... }
 *
 * Returns null when the submission looks legitimate, or a short machine-readable
 * reason code (e.g. "name_random_case", "email_dot_spam", "honeypot") when it
 * should be rejected.
 *
 * @package LuminalCMS
 */
declare(strict_types=1);

if (!function_exists('spamguard_check_lead')) {

    /**
     * Tunable thresholds. Conservative by design — raise sensitivity only with
     * data, since a false positive silently drops a real customer.
     */
    function spamguard_thresholds(): array
    {
        return [
            'name_case_switch_min'   => 5,   // case flips within one token => random
            'name_case_token_minlen' => 8,   // ...only counts on tokens this long
            'name_nospace_minlen'    => 15,  // single token, no space, this long = bot
            'name_vowel_minlen'      => 12,  // vowel-ratio test only above this length
            'name_vowel_ratio_max'   => 0.20,// fewer vowels than this on a long token = bot
            'email_local_dots_max'   => 4,   // dots in the local part at/above this = spam
        ];
    }

    /**
     * Honeypot field names. If any of these arrive non-empty, it's a bot —
     * a human never sees (or fills) them. Forms can opt in by rendering a
     * visually-hidden input with one of these names.
     */
    function spamguard_honeypot_names(): array
    {
        return ['_hp', 'hp_field', 'website_url', 'contact_url', 'fax_number'];
    }

    /**
     * Count upper<->lower case transitions within a single token.
     * "Randall" => 1, "TOM" => 0, "mzrFdesiVHwTNSoAIUQ" => many.
     */
    function spamguard_case_switches(string $token): int
    {
        $switches = 0;
        $prev = null; // true=upper, false=lower, null=non-letter
        $len = strlen($token);
        for ($i = 0; $i < $len; $i++) {
            $c = $token[$i];
            if ($c >= 'A' && $c <= 'Z') {
                $cur = true;
            } elseif ($c >= 'a' && $c <= 'z') {
                $cur = false;
            } else {
                $prev = null;
                continue;
            }
            if ($prev !== null && $cur !== $prev) {
                $switches++;
            }
            $prev = $cur;
        }
        return $switches;
    }

    /**
     * Heuristic: is this name machine-generated jibberish?
     */
    function spamguard_name_is_jibberish(string $name): ?string
    {
        $t = spamguard_thresholds();
        $name = trim($name);
        if ($name === '') {
            return null; // emptiness is handled by the caller's required-field check
        }

        // Single no-space alpha blob of significant length = not a human name.
        if (!preg_match('/\s/', $name)
            && strlen($name) >= $t['name_nospace_minlen']
            && ctype_alpha($name)) {
            return 'name_long_nospace';
        }

        // Per-token random-case + vowel-starvation tests.
        $tokens = preg_split('/\s+/', $name) ?: [];
        foreach ($tokens as $tok) {
            $len = strlen($tok);
            if ($len >= $t['name_case_token_minlen']
                && spamguard_case_switches($tok) >= $t['name_case_switch_min']) {
                return 'name_random_case';
            }
            if ($len >= $t['name_vowel_minlen']) {
                $vowels = preg_match_all('/[aeiouAEIOU]/', $tok);
                if ($vowels / $len < $t['name_vowel_ratio_max']) {
                    return 'name_low_vowel';
                }
            }
        }

        return null;
    }

    /**
     * Heuristic: is this email a dot-injection spam variant?
     * (Gmail and several providers ignore dots in the local part, so bots fan
     * one real inbox into countless dotted variants.)
     */
    function spamguard_email_is_spam(string $email): ?string
    {
        $email = trim($email);
        $at = strrpos($email, '@');
        if ($at === false) {
            return null; // caller validated format already
        }
        $local = substr($email, 0, $at);
        $t = spamguard_thresholds();
        if (substr_count($local, '.') >= $t['email_local_dots_max']) {
            return 'email_dot_spam';
        }
        return null;
    }

    /**
     * Master check. Returns a reason code string if the submission should be
     * rejected, or null if it looks legitimate.
     *
     * @param string $name   Submitted name field.
     * @param string $email  Submitted email field.
     * @param array  $fields All submitted fields (for honeypot inspection).
     */
    function spamguard_check_lead(string $name, string $email, array $fields = []): ?string
    {
        // Honeypot: any of the trap fields filled => bot.
        foreach (spamguard_honeypot_names() as $hp) {
            if (isset($fields[$hp]) && trim((string)$fields[$hp]) !== '') {
                return 'honeypot';
            }
        }

        if (($r = spamguard_name_is_jibberish($name)) !== null) {
            return $r;
        }
        if (($r = spamguard_email_is_spam($email)) !== null) {
            return $r;
        }

        return null;
    }
}
