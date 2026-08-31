<?php
/**
 * The Site - Frontend Page Footer Template
 * @file-location: SITE-ROOT/footer.php
 * @version 2025.09.05.18.45.00-EST
 * @author ChatGPT
 * @description Footer with unified gallery + lightbox stack.
 *   - De-duplicates pdf-gallery-loader.js
 *   - Uses new #ri-modal lightbox for PDFs, images, and videos
 *   - (Legacy) #lightbox kept in DOM but JS disabled to avoid double-binding
 */
?>
    </main>
    <?php
    // Load footer settings from site-settings.json
    $settingsFile = __DIR__ . '/admin/data/site-settings.json';
    $footerData = null;
    if (file_exists($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true);
        $footerData = $settings['footer'] ?? null;
    }

    // Platform -> icon resolution (shared with the admin form and the converter),
    // so "TikTok" typed into Social Links draws the shipped tik-tok.png without
    // anyone hand-writing an <img>. See /includes/social_icons.php.
    //
    // Deliberately NOT a bare require_once. This template runs on every page of
    // every site in the fleet; a missing helper must degrade to plain text
    // links, never take the whole site down. (A partial deploy, an older
    // per-site allowlist, or a hand-pushed footer.php would otherwise fatal
    // every request.) The stubs below reproduce the pre-icon behaviour exactly.
    $luminalIconLib = __DIR__ . '/includes/social_icons.php';
    if (is_file($luminalIconLib)) require_once $luminalIconLib;
    if (!function_exists('luminal_social_icon')) {
        error_log('footer: includes/social_icons.php missing — footer social links falling back to text');
        function luminal_social_icon(string $p, string $i = '', string $r = ''): string { return ''; }
        function luminal_social_platform_from_url(string $u): string { return ''; }
    }

    // Empty-footer case. (footer_content.html retired 2026-07-02 — it was byte-
    // identical empty stock on every site and the @include path never matched
    // where the files lived, so the fallback was dead. Custom footer markup is now
    // the admin "Custom Footer HTML" field: site-settings.json -> footer.custom_html,
    // rendered in the dynamic branch below.)
    if (!$footerData || empty(array_filter($footerData))) {
    ?>
    <footer class="site-footer"></footer>
    <?php
    } else {
    // Dynamic footer from site-settings.json — CMS provides the wrapper
    ?>
    <footer class="site-footer">
        <div class="footer-container">
                <div class="footer-content">
                    <?php if (!empty($footerData['title'])): ?>
                    <h2 class="footer-title"><?= htmlspecialchars($footerData['title']) ?></h2>
                    <?php endif; ?>

                    <?php if (!empty($footerData['contact_email']) || !empty($footerData['phone']) || !empty($footerData['address'])): ?>
                    <div class="footer-contact">
                        <?php if (!empty($footerData['address'])): ?>
                            <p class="footer-address"><?= nl2br(htmlspecialchars($footerData['address'])) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($footerData['phone'])): ?>
                            <p class="footer-phone"><a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $footerData['phone']), ENT_QUOTES) ?>"><?= htmlspecialchars($footerData['phone']) ?></a></p>
                        <?php endif; ?>
                        <?php if (!empty($footerData['contact_email'])): ?>
                            <p class="footer-email"><a href="mailto:<?= htmlspecialchars($footerData['contact_email'], ENT_QUOTES) ?>"><?= htmlspecialchars($footerData['contact_email']) ?></a></p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($footerData['social_links']) && is_array($footerData['social_links'])): ?>
                    <div class="footer-social">
                        <?php foreach ($footerData['social_links'] as $link): ?>
                            <?php
                            // A row needs a URL. The platform is the accessible name; when it
                            // is missing we fall back to the URL's own host rather than
                            // dropping the link (the old code silently discarded such rows).
                            if (!is_array($link)) continue;
                            $slUrl = trim((string)($link['url'] ?? ''));
                            if ($slUrl === '') continue;
                            $slName = trim((string)($link['platform'] ?? ''));
                            if ($slName === '') $slName = luminal_social_platform_from_url($slUrl);
                            if ($slName === '') $slName = (string)parse_url($slUrl, PHP_URL_HOST);
                            $slIcon = luminal_social_icon($slName, (string)($link['icon'] ?? ''), __DIR__);
                            $slMail = str_starts_with(strtolower($slUrl), 'mailto:');
                            ?>
                            <a class="footer-social-link<?= $slIcon ? '' : ' is-text' ?>"
                               href="<?= htmlspecialchars($slUrl, ENT_QUOTES) ?>"
                               <?= $slMail ? '' : 'target="_blank" rel="noopener noreferrer"' ?>
                               title="<?= htmlspecialchars($slName, ENT_QUOTES) ?>">
                                <?php if ($slIcon): ?>
                                    <img class="footer-social-icon" src="<?= htmlspecialchars($slIcon, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($slName, ENT_QUOTES) ?>" loading="lazy">
                                <?php else: ?>
                                    <span class="footer-social-label"><?= htmlspecialchars($slName) ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($footerData['affiliate_links']) && is_array($footerData['affiliate_links'])): ?>
                    <div class="footer-affiliates">
                        <?php foreach ($footerData['affiliate_links'] as $link): ?>
                            <?php if (is_array($link) && !empty($link['label']) && !empty($link['url'])): ?>
                                <a href="<?= htmlspecialchars($link['url'], ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer" class="footer-partner-link<?= !empty($link['mono']) ? ' is-mono' : '' ?>"><?php if (!empty($link['logo'])): ?><img src="/<?= htmlspecialchars(ltrim(str_replace('..', '', (string)$link['logo']), '/'), ENT_QUOTES) ?>" alt="<?= htmlspecialchars($link['label']) ?>" class="footer-partner-logo" loading="lazy"><?php else: ?><?= htmlspecialchars($link['label']) ?><?php endif; ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($footerData['credits'])): ?>
                    <div class="footer-credits">
                        <?= nl2br(htmlspecialchars($footerData['credits'])) ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($footerData['tagline'])): ?>
                    <p class="footer-tagline"><?= htmlspecialchars($footerData['tagline']) ?></p>
                    <?php endif; ?>

                    <?php if (!empty($footerData['legal_links']) && is_array($footerData['legal_links'])): ?>
                    <div class="footer-legal">
                        <?php foreach ($footerData['legal_links'] as $link): ?>
                            <?php if (is_array($link) && !empty($link['label']) && !empty($link['url'])): ?>
                                <a href="<?= htmlspecialchars($link['url'], ENT_QUOTES) ?>"><?= htmlspecialchars($link['label']) ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($footerData['custom_html'])): ?>
                    <div class="footer-custom">
                        <?php
                        /* custom_html is stored data, not a template — it is echoed, never
                         * include()d, so a <?php … ?> block in here CANNOT execute. Left
                         * alone the browser swallows it as a bogus comment and the markup
                         * around it silently disappears (this is what killed the copyright
                         * link on some sites). Strip it and say so in the log
                         * rather than shipping dead code to the page. */
                        $fcHtml = (string)$footerData['custom_html'];
                        if (str_contains($fcHtml, '<?')) {
                            $fcHtml = (string)preg_replace('/<\?(?:php|=)?.*?(?:\?>|$)/s', '', $fcHtml);
                            error_log('footer: stripped inert PHP from footer.custom_html — move it to a Page/HTML Block or a real template');
                        }
                        echo $fcHtml;
                        ?>
                    </div>
                    <?php endif; ?>
                </div>
        </div>
    </footer>
                <?php
    }
    ?>

    <!-- Legacy lightbox DOM kept for compatibility; JS disabled below -->
    <div id="lightbox" class="lightbox" aria-hidden="true">
        <span class="lightbox-close" id="lightbox-close">×</span>
        <div class="lightbox-content-container">
            <div id="lightbox-content"></div>
        </div>
    </div>

    <script>
      // Expose SITE_ROOT_URL to JS if defined
      window.SITE_ROOT_URL_JS = '<?php echo defined('SITE_ROOT_URL') ? SITE_ROOT_URL : ''; ?>';
    </script>

     <!-- Core site scripts -->
     <script src="/js/main.js"></script>
    
     <script src="/panels/panel-loader.js"></script>

     <!-- Gallery loaders (placeholders -> panel HTML) -->
     <script src="/js/pdf-gallery-loader.js"></script>
     <script src="/js/image-gallery-loader.js"></script>
     <script src="/js/video-gallery-loader.js"></script>
    
     <script src="/js/stack-video-enhancer.js"></script>

     <script src="https://www.youtube.com/iframe_api"></script>
    
     <script src="/js/lightbox.js"></script>
        
    <!-- Thumb helpers -->
    <script src="/js/thumb-autobuild.js"></script>

    <script src="/js/thumb-fallbacks.js"></script>

    <?php
    // Printful — global cart drawer (renders if PrintfulManager module installed)
    $pfCart = __DIR__ . '/admin/modules/PrintfulManager/includes/global_cart.php';
    if (is_file($pfCart)) {
        require_once $pfCart;
        if (function_exists('pf_render_global_cart')) pf_render_global_cart();
    }
    ?>

    <?php
    // AudienceBuilder — inject form styles + hook if enabled
    $abConf = __DIR__ . '/admin/data/AudienceBuilder/config.json';
    if (is_file($abConf)) {
        $abCfg = json_decode(file_get_contents($abConf), true);
        if (!empty($abCfg['enabled'])) {
            echo '<link rel="stylesheet" href="/css/ab-forms.css">' . "\n";
            echo '<script src="/admin/modules/AudienceBuilder/ab-form-hook.js"></script>' . "\n";
        }
    }

    // Age Verification Gate — site-wide, owned by SiteSettings (admin/data/
    // site-settings.json → age_gate). Blocking overlay on page load, cookie-
    // remembered so it doesn't re-prompt every page.
    $ssFile = __DIR__ . '/admin/data/site-settings.json';
    if (is_file($ssFile)) {
        $ssData = json_decode((string)@file_get_contents($ssFile), true) ?: [];
        $ag = $ssData['age_gate'] ?? null;
        // Don't gate logged-in admins — the session is already active (page.php
        // starts it before output), so just read it; never session_start() here
        // (footer runs after headers are sent).
        $agIsAdmin = (session_status() === PHP_SESSION_ACTIVE) && !empty($_SESSION['user_id']);
        // Browser-independent preview: ?age_gate=preview|reset forces the gate to
        // render even for admins (the JS handles the cookie side). Lets anyone
        // eyeball the styled overlay in any browser without private mode.
        $agPreview = in_array($_GET['age_gate'] ?? '', ['preview', 'reset'], true);
        if (is_array($ag) && !empty($ag['enabled']) && (!$agIsAdmin || $agPreview)) {
            $agConf = [
                'title'         => $ag['title'] ?? 'Age Verification',
                'prompt'        => $ag['prompt'] ?? '',
                'confirm_text'  => $ag['confirm_text'] ?? '',
                'deny_text'     => $ag['deny_text'] ?? '',
                'threshold'     => (int)($ag['threshold'] ?? 21),
                'remember_days' => (int)($ag['remember_days'] ?? 30),
                'idle_minutes'  => (int)($ag['idle_minutes'] ?? 0),
                'audit'         => !empty($ag['audit']),
                'deny_url'      => $ag['deny_url'] ?? '',
                'deny_message'  => $ag['deny_message'] ?? '',
            ];
            // Cache-bust by file mtime — Brave (and other aggressive caches)
            // otherwise serve a stale age-gate.js/.css and miss new behaviour.
            $agCssV = @filemtime(__DIR__ . '/css/age-gate.css') ?: time();
            $agJsV  = @filemtime(__DIR__ . '/admin/modules/SiteSettings/age-gate.js') ?: time();
            echo '<link rel="stylesheet" href="/css/age-gate.css?v=' . $agCssV . '">' . "\n";
            echo '<script>window.AB_AGE_GATE=' . json_encode($agConf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) . ';</script>' . "\n";
            echo '<script src="/admin/modules/SiteSettings/age-gate.js?v=' . $agJsV . '"></script>' . "\n";
        }
    }
    ?>


    <?php
    // Floating Video Player — read config from site-settings.json
    $vfEnabled = true;
    $vfMode    = 'both';
    if (isset($settings) && is_array($settings)) {
        $vf = $settings['video_float'] ?? [];
        if (isset($vf['enabled']) && !$vf['enabled']) $vfEnabled = false;
        if (!empty($vf['mode'])) $vfMode = $vf['mode'];
    }
    if ($vfEnabled):
    ?>
    <link rel="stylesheet" href="/css/video-float.css">
    <script>window.LUMINAL_VIDEO_FLOAT={enabled:true,mode:<?= json_encode($vfMode) ?>};</script>
    <script src="/js/video-float.js"></script>
    <?php endif; ?>

    <?php
    // Contact FAB — floating ? button with modal contact form
    @include __DIR__ . '/includes/contact-fab.php';
    ?>
    <?php
    // Persistent bottom browser podcast player (opt-in per site; once-guarded so it emits
    // once even though page.php + article.php both include this shared footer).
    if (defined('SITE_ROOT') && is_file(SITE_ROOT . '/includes/podcast_bottom_player.php')) {
        include SITE_ROOT . '/includes/podcast_bottom_player.php';
    }
    ?>
</body>
</html>