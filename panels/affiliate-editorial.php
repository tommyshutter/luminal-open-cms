<?php
/**
 * Affiliate Editorial Rack
 * Magazine-style product editorial section for fleet sites.
 *
 * Reads config from: admin/data/AffiliateProducts/editorial.json
 * Products from:     admin/data/AffiliateProducts/products.json
 *                    OR Amazon Creators API (auto-discovers + caches 24h)
 *
 * @module AffiliateProducts
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) return;

/* ── load editorial config ───────────────────────────────────────── */
$_ed_cfg_file = SITE_ROOT . '/admin/data/AffiliateProducts/editorial.json';
if (!is_file($_ed_cfg_file)) return;
$_ed_cfg = json_decode((string)file_get_contents($_ed_cfg_file), true) ?: [];
if (empty($_ed_cfg['enabled'])) return;

$_ed_title    = $_ed_cfg['section_title']    ?? 'THE EDIT';
$_ed_subtitle = $_ed_cfg['section_subtitle'] ?? 'Curated for the lifestyle';
$_ed_max      = min(9, max(3, (int)($_ed_cfg['max_items'] ?? 6)));
$_ed_keywords = $_ed_cfg['keywords']         ?? '';
$_ed_accent   = $_ed_cfg['accent_color']     ?? '#00ffff';
$_ed_view_all = $_ed_cfg['view_all_slug']    ?? '';

/* ── load products (local → API fallback) ────────────────────────── */
$_ap_data_dir = SITE_ROOT . '/admin/data/AffiliateProducts';
$_ed_products = [];

// 1) Try local products.json (admin-curated)
$_prod_file = $_ap_data_dir . '/products.json';
if (is_file($_prod_file)) {
    $_all = json_decode((string)file_get_contents($_prod_file), true) ?: [];
    // Filter: enabled, has image, optionally by category
    $edCat = $_ed_cfg['category'] ?? '';
    foreach ($_all as $_p) {
        if (empty($_p['enabled'])) continue;
        if ($edCat && ($_p['category'] ?? '') !== $edCat) continue;
        if (empty($_p['image']) && empty($_p['thumbnail'])) continue;
        $_ed_products[] = $_p;
        if (count($_ed_products) >= $_ed_max) break;
    }
}

// 2) If not enough products, try editorial cache (Amazon results)
$_cache_file = $_ap_data_dir . '/editorial_cache.json';
if (count($_ed_products) < 3 && is_file($_cache_file)) {
    $_cached = json_decode((string)file_get_contents($_cache_file), true) ?: [];
    $cacheAge = time() - (int)($_cached['cached_at'] ?? 0);
    if ($cacheAge < 86400 && !empty($_cached['items'])) {
        $_ed_products = $_cached['items'];
    }
}

// 3) Auto-discover from Amazon if still empty and keywords configured
if (count($_ed_products) < 3 && $_ed_keywords) {
    // Only run API call if cache is absent or >24h old
    $shouldFetch = !is_file($_cache_file) || (time() - (int)(json_decode((string)file_get_contents($_cache_file), true)['cached_at'] ?? 0) > 86400);
    if ($shouldFetch) {
        $apCfgFile = $_ap_data_dir . '/config.json';
        $apCfg     = is_file($apCfgFile) ? (json_decode((string)file_get_contents($apCfgFile), true) ?: []) : [];
        $clientId  = $apCfg['amazon_creators_client_id']     ?? '';
        $clientSec = $apCfg['amazon_creators_client_secret'] ?? '';
        $tag       = $apCfg['amazon_associate_tag']          ?? '';
        $mkt       = $apCfg['amazon_marketplace']            ?? 'www.amazon.com';

        if ($clientId && $clientSec && $tag) {
            // Get token
            $tok = '';
            $tokenCache = $_ap_data_dir . '/amazon_token.json';
            if (is_file($tokenCache)) {
                $tc = json_decode((string)file_get_contents($tokenCache), true);
                if (($tc['expires_at'] ?? 0) > time() + 60) $tok = $tc['access_token'] ?? '';
            }
            if (!$tok) {
                $ch = curl_init('https://api.amazon.com/auth/o2/token');
                curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>8,
                    CURLOPT_POSTFIELDS=>http_build_query(['grant_type'=>'client_credentials','client_id'=>$clientId,'client_secret'=>$clientSec]),
                    CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
                $tb = (string)curl_exec($ch); curl_close($ch);
                $tr = json_decode($tb, true);
                if (!empty($tr['access_token'])) {
                    $tok = $tr['access_token'];
                    $tr['expires_at'] = time() + (int)($tr['expires_in'] ?? 3600);
                    @file_put_contents($tokenCache, json_encode($tr));
                }
            }

            if ($tok) {
                $ch = curl_init('https://affiliate-program.amazon.com/creatorsapi/searchItems');
                curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>12,
                    CURLOPT_POSTFIELDS=>json_encode(['marketplace'=>$mkt,'partnerTag'=>$tag,'keywords'=>$_ed_keywords,
                        'itemCount'=>$_ed_max,'resources'=>['ItemInfo.Title','Offers.Listings.Price',
                        'Images.Primary.Medium','Images.Primary.Large','DetailPageURL','CustomerReviews.StarRating']]),
                    CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$tok,'Content-Type: application/json','Accept: application/json']]);
                $ab = (string)curl_exec($ch); $ac = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
                if ($ac === 200) {
                    $ar = json_decode($ab, true);
                    $rawItems = $ar['SearchResult']['Items'] ?? [];
                    $discovered = [];
                    foreach ($rawItems as $ri) {
                        $imgL = $ri['Images']['Primary']['Large']['URL'] ?? '';
                        $imgM = $ri['Images']['Primary']['Medium']['URL'] ?? '';
                        $img  = $imgL ?: $imgM;
                        if (!$img) continue;
                        $url  = $ri['DetailPageURL'] ?? '';
                        if ($url && $tag) $url .= (str_contains($url,'?')?'&':'?').'tag='.urlencode($tag);
                        $price = $ri['Offers']['Listings'][0]['Price']['DisplayAmount'] ?? '';
                        $discovered[] = [
                            'id' => 'amz-'.$ri['ASIN'],
                            'title' => $ri['ItemInfo']['Title']['DisplayValue'] ?? '',
                            'price' => $price,
                            'image' => $img,
                            'url'   => $url,
                            'source'=> 'amazon',
                            'asin'  => $ri['ASIN'] ?? '',
                            'affiliate_tag' => $tag,
                            'enabled' => true,
                        ];
                    }
                    if ($discovered) {
                        $_ed_products = $discovered;
                        @file_put_contents($_cache_file, json_encode(['cached_at'=>time(),'keywords'=>$_ed_keywords,'items'=>$discovered]));
                    }
                }
            }
        }
    } elseif (is_file($_cache_file)) {
        // Use stale cache rather than nothing
        $sc = json_decode((string)file_get_contents($_cache_file), true);
        if (!empty($sc['items'])) $_ed_products = $sc['items'];
    }
}

if (count($_ed_products) < 1) return; // nothing to show

// Clamp and normalize
$_ed_products = array_slice($_ed_products, 0, $_ed_max);
foreach ($_ed_products as &$_ep) {
    $_ep['image']     = $_ep['image']     ?? $_ep['thumbnail'] ?? '';
    $_ep['url']       = $_ep['url']       ?? '#';
    $_ep['title']     = htmlspecialchars((string)($_ep['title'] ?? ''), ENT_QUOTES, 'UTF-8');
    $_ep['price']     = htmlspecialchars((string)($_ep['price'] ?? ''), ENT_QUOTES, 'UTF-8');
    // Route through click-track if not already
    if ($_ep['url'] !== '#' && !str_contains((string)$_ep['url'], 'click-track')) {
        $pid = $_ep['asin'] ?? ($_ep['id'] ?? '');
        $_ep['url'] = '/panels/click-track.php?to=' . urlencode((string)$_ep['url'])
                    . '&pid=' . urlencode((string)$pid) . '&src=editorial';
    }
}
unset($_ep);

// Split into zones: hero (0), stack (1-2), strip (3+)
$_ed_hero   = $_ed_products[0] ?? null;
$_ed_stack  = array_slice($_ed_products, 1, 2);
$_ed_strip  = array_slice($_ed_products, 3);

$_ed_accent_safe = preg_replace('/[^a-fA-F0-9#rgba(),.% ]/', '', $_ed_accent);
$_site_host = $_SERVER['HTTP_HOST'] ?? '';

?>
<section class="ap-editorial" aria-label="Curated Products">
<style>
.ap-editorial {
  position: relative; z-index: 1;
  padding: 48px 0 64px; width: 100%; box-sizing: border-box;
}
.ap-editorial *,
.ap-editorial *::before,
.ap-editorial *::after { box-sizing: border-box; }

.ap-ed-inner { max-width: 1200px; margin: 0 auto; padding: 0 24px; }

/* ── Section Header ───────────────────────────────────────────── */
.ap-ed-header { margin-bottom: 32px; }
.ap-ed-eyebrow {
  font-family: 'accid', system-ui, sans-serif;
  font-size: 0.65rem; letter-spacing: 0.25em; text-transform: uppercase;
  color: <?= $_ed_accent_safe ?>; opacity: 0.85; margin-bottom: 6px;
}
.ap-ed-section-title {
  font-family: 'juliettregular wyxyg', serif;
  font-size: clamp(1.8rem, 4vw, 2.8rem);
  color: #fff; margin: 0 0 6px; font-weight: 400; letter-spacing: 0.02em;
}
.ap-ed-section-sub {
  font-family: 'accid', system-ui, sans-serif;
  font-size: 0.82rem; color: rgba(255,255,255,0.55); margin: 0;
}

/* ── Spread layout ────────────────────────────────────────────── */
.ap-ed-spread {
  display: grid;
  grid-template-columns: 55fr 45fr;
  gap: 14px;
  margin-bottom: 14px;
}
@media (max-width: 700px) {
  .ap-ed-spread { grid-template-columns: 1fr; }
}

/* ── Shared card base ─────────────────────────────────────────── */
.ap-ed-card {
  position: relative; display: block; overflow: hidden;
  border-radius: 16px; text-decoration: none; color: #fff;
  background: rgba(0,0,0,0.25);
  border: 1px solid rgba(255,255,255,0.1);
  box-shadow: 0 8px 40px rgba(0,0,0,0.35);
  transition: box-shadow 0.35s ease, transform 0.35s ease;
}
.ap-ed-card:hover {
  box-shadow: 0 20px 60px rgba(0,0,0,0.55);
  transform: translateY(-3px);
}
.ap-ed-card:hover .ap-ed-bg { transform: scale(1.04); }

/* ── Background image ─────────────────────────────────────────── */
.ap-ed-bg {
  position: absolute; inset: 0;
  background-size: cover; background-position: center;
  transition: transform 0.6s ease;
}
.ap-ed-no-img {
  position: absolute; inset: 0;
  display: flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, rgba(0,60,80,0.6) 0%, rgba(0,20,40,0.8) 100%);
  font-size: 3rem; opacity: 0.4;
}

/* ── Glass info overlay ───────────────────────────────────────── */
.ap-ed-glass {
  position: absolute; bottom: 0; left: 0; right: 0;
  padding: 20px 20px 18px;
  background: linear-gradient(to top, rgba(0,0,0,0.78) 0%, rgba(0,0,0,0.38) 65%, transparent 100%);
  backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
}
.ap-ed-card:hover .ap-ed-glass {
  backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
}

/* ── Hero card ────────────────────────────────────────────────── */
.ap-ed-hero { aspect-ratio: 3/4; }
@media (max-width: 700px) { .ap-ed-hero { aspect-ratio: 4/5; } }
.ap-ed-hero .ap-ed-glass { padding: 28px 24px 22px; }
.ap-ed-hero .ap-ed-prod-eyebrow {
  font-size: 0.58rem; letter-spacing: 0.2em; text-transform: uppercase;
  color: <?= $_ed_accent_safe ?>; margin-bottom: 6px; display: block;
}
.ap-ed-hero .ap-ed-prod-title {
  font-family: 'juliettregular wyxyg', serif;
  font-size: clamp(1.1rem, 2.5vw, 1.5rem); font-weight: 400;
  line-height: 1.25; margin: 0 0 8px; color: #fff;
}
.ap-ed-hero .ap-ed-prod-price {
  font-size: 0.9rem; color: <?= $_ed_accent_safe ?>; font-weight: 700;
  margin-bottom: 10px;
}
.ap-ed-hero .ap-ed-cta {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 0.72rem; letter-spacing: 0.12em; text-transform: uppercase;
  color: rgba(255,255,255,0.7); transition: color 0.2s;
}
.ap-ed-card:hover .ap-ed-cta { color: #fff; }
.ap-ed-cta::after { content: '→'; }

/* ── Stack cards ──────────────────────────────────────────────── */
.ap-ed-stack { display: flex; flex-direction: column; gap: 14px; }
.ap-ed-stack-item { aspect-ratio: 4/3; flex: 1; min-height: 0; }
.ap-ed-stack-item .ap-ed-prod-title {
  font-family: 'accid', system-ui, sans-serif;
  font-size: 0.82rem; font-weight: 600; margin: 0 0 4px; color: #fff;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.ap-ed-stack-item .ap-ed-prod-price {
  font-size: 0.78rem; color: <?= $_ed_accent_safe ?>; font-weight: 700;
}

/* ── Strip ────────────────────────────────────────────────────── */
.ap-ed-strip {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 12px;
  margin-bottom: 28px;
}
.ap-ed-strip-item { aspect-ratio: 5/4; border-radius: 12px; }
.ap-ed-strip-item .ap-ed-prod-title {
  font-size: 0.75rem; font-weight: 600; margin: 0 0 3px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.ap-ed-strip-item .ap-ed-prod-price {
  font-size: 0.7rem; color: <?= $_ed_accent_safe ?>;
}

/* ── Footer link ──────────────────────────────────────────────── */
.ap-ed-footer { text-align: right; }
.ap-ed-view-all {
  display: inline-flex; align-items: center; gap: 8px;
  font-family: 'accid', system-ui, sans-serif;
  font-size: 0.72rem; letter-spacing: 0.14em; text-transform: uppercase;
  color: rgba(255,255,255,0.5); text-decoration: none;
  border-bottom: 1px solid rgba(255,255,255,0.15); padding-bottom: 2px;
  transition: color 0.2s, border-color 0.2s;
}
.ap-ed-view-all:hover { color: <?= $_ed_accent_safe ?>; border-color: <?= $_ed_accent_safe ?>; }
.ap-ed-view-all::after { content: '→'; }
.ap-ed-disclosure {
  margin-top: 10px;
  font-size: 0.62rem; color: rgba(255,255,255,0.25); text-align: center;
}
</style>

<div class="ap-ed-inner">

  <!-- Header -->
  <div class="ap-ed-header">
    <div class="ap-ed-eyebrow">Curated Picks</div>
    <h2 class="ap-ed-section-title"><?= htmlspecialchars($_ed_title, ENT_QUOTES, 'UTF-8') ?></h2>
    <?php if ($_ed_subtitle): ?>
    <p class="ap-ed-section-sub"><?= htmlspecialchars($_ed_subtitle, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
  </div>

  <!-- Spread: hero + stack -->
  <?php if ($_ed_hero): ?>
  <div class="ap-ed-spread">

    <!-- Hero -->
    <a class="ap-ed-card ap-ed-hero" href="<?= htmlspecialchars($_ed_hero['url'], ENT_QUOTES) ?>"
       target="_blank" rel="noopener sponsored" aria-label="<?= $_ed_hero['title'] ?>">
      <?php if ($_ed_hero['image']): ?>
      <div class="ap-ed-bg" style="background-image:url('<?= htmlspecialchars($_ed_hero['image'], ENT_QUOTES) ?>')"
           role="img" aria-hidden="true"></div>
      <?php else: ?><div class="ap-ed-no-img">🌊</div><?php endif; ?>
      <div class="ap-ed-glass">
        <span class="ap-ed-prod-eyebrow">Featured</span>
        <div class="ap-ed-prod-title"><?= $_ed_hero['title'] ?></div>
        <?php if ($_ed_hero['price']): ?>
        <div class="ap-ed-prod-price"><?= $_ed_hero['price'] ?></div>
        <?php endif; ?>
        <span class="ap-ed-cta">Shop Now</span>
      </div>
    </a>

    <!-- Stack -->
    <?php if ($_ed_stack): ?>
    <div class="ap-ed-stack">
      <?php foreach ($_ed_stack as $_si): ?>
      <a class="ap-ed-card ap-ed-stack-item" href="<?= htmlspecialchars($_si['url'], ENT_QUOTES) ?>"
         target="_blank" rel="noopener sponsored" aria-label="<?= $_si['title'] ?>">
        <?php if ($_si['image']): ?>
        <div class="ap-ed-bg" style="background-image:url('<?= htmlspecialchars($_si['image'], ENT_QUOTES) ?>')" aria-hidden="true"></div>
        <?php else: ?><div class="ap-ed-no-img">☀️</div><?php endif; ?>
        <div class="ap-ed-glass">
          <div class="ap-ed-prod-title"><?= $_si['title'] ?></div>
          <?php if ($_si['price']): ?>
          <div class="ap-ed-prod-price"><?= $_si['price'] ?></div>
          <?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>
  <?php endif; ?>

  <!-- Strip -->
  <?php if ($_ed_strip): ?>
  <div class="ap-ed-strip">
    <?php foreach ($_ed_strip as $_si): ?>
    <a class="ap-ed-card ap-ed-strip-item" href="<?= htmlspecialchars($_si['url'], ENT_QUOTES) ?>"
       target="_blank" rel="noopener sponsored" aria-label="<?= $_si['title'] ?>">
      <?php if ($_si['image']): ?>
      <div class="ap-ed-bg" style="background-image:url('<?= htmlspecialchars($_si['image'], ENT_QUOTES) ?>')" aria-hidden="true"></div>
      <?php else: ?><div class="ap-ed-no-img">🏖️</div><?php endif; ?>
      <div class="ap-ed-glass" style="padding:12px 14px 10px">
        <div class="ap-ed-prod-title"><?= $_si['title'] ?></div>
        <?php if ($_si['price']): ?><div class="ap-ed-prod-price"><?= $_si['price'] ?></div><?php endif; ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Footer row -->
  <div class="ap-ed-footer">
    <?php if ($_ed_view_all): ?>
    <a href="<?= htmlspecialchars('/page.php?p='.$_ed_view_all, ENT_QUOTES) ?>" class="ap-ed-view-all">See The Full Edit</a>
    <?php endif; ?>
  </div>

  <p class="ap-ed-disclosure">As an Amazon Associate, this site earns from qualifying purchases.</p>

</div>
</section>
