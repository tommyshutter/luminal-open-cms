<?php
/**
 * Affiliate Products Shortcode Renderer
 * @file includes/renderers/affiliate_products.renderer.php
 *
 * Usage:
 *   [[affiliate-products]]                              — all enabled products
 *   [[affiliate-products category="studio-gear"]]       — filtered by category
 *   [[affiliate-products limit="6" columns="4"]]        — with limit + columns
 *   [[affiliate-product:ap-20260221-a1b2c3d4]]          — single product card
 *
 * Data: admin/data/AffiliateProducts/products.json
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../../../..') ?: dirname(__DIR__, 5));
}

/**
 * Build a click-tracking URL that routes through the local go.php endpoint.
 * Falls back to the raw affiliate URL if the product has no id (legacy data).
 */
function afp_click_url(array $product): string {
    $id = trim((string)($product['id'] ?? ''));
    if (!$id) return (string)($product['url'] ?? '#');
    return '/admin/modules/AffiliateProducts/go.php?p=' . urlencode($id);
}

/**
 * Render affiliate product grid.
 */
function render_affiliate_products(array $attrs = []): string {
    $dataFile = SITE_ROOT . '/admin/data/AffiliateProducts/products.json';
    if (!is_file($dataFile)) return '<!-- affiliate-products: no products configured -->';

    $all = json_decode((string)file_get_contents($dataFile), true);
    if (!is_array($all) || empty($all)) return '<!-- affiliate-products: no products -->';

    // Filter: must be enabled, not ignored, and not unavailable
    $products = array_filter($all, fn($p) => ($p['enabled'] ?? true)
        && empty($p['ignored'])
        && empty($p['unavailable']));

    // Category filter
    $category = trim($attrs['category'] ?? '');
    if ($category !== '') {
        $products = array_filter($products, fn($p) => ($p['category'] ?? '') === $category);
    }

    // Character filter — products can have a `characters` array (e.g. ["luffy", "zoro"])
    // or a single `character` string. Untagged products are excluded when filtering.
    $character = strtolower(trim($attrs['character'] ?? ''));
    if ($character !== '') {
        $products = array_filter($products, function($p) use ($character) {
            $chars = $p['characters'] ?? $p['character'] ?? [];
            if (is_string($chars)) $chars = [$chars];
            if (!is_array($chars)) return false;
            foreach ($chars as $c) {
                if (strtolower(trim((string)$c)) === $character) return true;
            }
            return false;
        });
    }

    // Sort by sort_order
    usort($products, fn($a, $b) => ($a['sort_order'] ?? 999) <=> ($b['sort_order'] ?? 999));

    // Offset + Limit
    $offset = max(0, (int)($attrs['offset'] ?? 0));
    if ($offset > 0) $products = array_slice($products, $offset);
    $limit = (int)($attrs['limit'] ?? 0);
    if ($limit > 0) $products = array_slice($products, 0, $limit);

    if (empty($products)) return '<!-- affiliate-products: no matching products -->';

    // Columns
    $columns = max(1, min(8, (int)($attrs['columns'] ?? 4)));
    $cardMin = intval(900 / $columns) . 'px';

    $esc = function(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    };

    // Unique instance ID
    static $instanceCount = 0;
    $instanceCount++;
    $uid = 'afp-' . $instanceCount;

    // Inject CSS once
    static $cssInjected = false;
    $css = '';
    if (!$cssInjected) {
        $cssInjected = true;
        $css = <<<'AFPCSS'
<style>
.afp-grid{
  display:grid; grid-template-columns:repeat(auto-fill, minmax(var(--afp-card-min,220px), 1fr));
  gap:14px; padding:8px 0;
}
.afp-card{
  background:rgba(0,0,0,.35); border:1px solid rgba(255,255,255,.12);
  border-radius:12px; overflow:hidden; display:flex; flex-direction:column;
  transition:border-color .2s, transform .15s; text-decoration:none; color:inherit;
}
.afp-card:hover{ border-color:rgba(255,255,255,.25); transform:translateY(-2px); }
.afp-card-img{
  width:100%; aspect-ratio:4/3; object-fit:cover; display:block;
  background:rgba(0,0,0,.2);
}
.afp-card-placeholder{
  width:100%; aspect-ratio:4/3; display:flex; align-items:center;
  justify-content:center; background:rgba(0,0,0,.15); color:#444; font-size:28px;
}
.afp-card-body{ padding:10px 12px; flex:1; display:flex; flex-direction:column; }
.afp-card-title{
  font:600 13px/1.3 system-ui,sans-serif; color:#eee; margin-bottom:4px;
  overflow:hidden; text-overflow:ellipsis; display:-webkit-box;
  -webkit-line-clamp:2; -webkit-box-orient:vertical;
}
.afp-card-desc{
  font:12px/1.4 system-ui,sans-serif; color:#999; margin-bottom:8px; flex:1;
  overflow:hidden; text-overflow:ellipsis; display:-webkit-box;
  -webkit-line-clamp:2; -webkit-box-orient:vertical;
}
.afp-card-meta{ display:flex; align-items:center; justify-content:space-between; gap:6px; }
.afp-price{ font:700 14px/1 system-ui,sans-serif; color:#10b981; }
.afp-rating{ font:12px/1 system-ui,sans-serif; color:#fbbf24; }
.afp-card-footer{
  display:flex; align-items:center; justify-content:space-between;
  padding:6px 12px; border-top:1px solid rgba(255,255,255,.06);
}
.afp-badge{
  display:inline-flex; align-items:center; gap:3px;
  padding:2px 7px; border-radius:4px; font:600 9px/1 system-ui,sans-serif;
  text-transform:uppercase; letter-spacing:.5px;
}
.afp-badge-amazon{ background:rgba(255,153,0,.15); color:#ff9900; }
.afp-badge-walmart{ background:rgba(0,113,220,.15); color:#0071dc; }
.afp-badge-bestbuy{ background:rgba(0,59,100,.15); color:#5a9bd5; }
.afp-badge-target{ background:rgba(204,0,0,.15); color:#cc0000; }
.afp-badge-generic{ background:rgba(255,255,255,.06); color:#888; }
.afp-cta{
  font:600 11px/1 system-ui,sans-serif; color:#a78bfa;
  text-decoration:none; display:flex; align-items:center; gap:4px;
}
/* Single card embed */
.afp-single{ display:inline-block; max-width:300px; }
.afp-single .afp-card{ max-width:300px; }
@media(max-width:768px){
  .afp-grid{ grid-template-columns:repeat(auto-fill, minmax(160px, 1fr)) !important; gap:10px; }
}
@media(max-width:480px){
  .afp-grid{ grid-template-columns:1fr !important; }
}
</style>
AFPCSS;
    }

    // Build HTML
    $html = $css;
    $html .= '<div class="afp-grid" id="' . $uid . '" style="--afp-card-min:' . $cardMin . ';--afp-cols:' . $columns . '">' . "\n";

    foreach ($products as $p) {
        // Hard filter: skip any product without a working image. The
        // affiliate_product_run validator blanks broken image URLs at
        // save-time, so an empty image here means the upstream (usually
        // Amazon) can't serve a proper image for this product. We're
        // linking affiliate traffic to them for pennies — if they can't
        // hold up their end on images, we skip rather than render a sad
        // placeholder card.
        $image = trim((string)($p['image'] ?? ''));
        if ($image === '') continue;

        $url = $esc(afp_click_url($p));
        $title = $esc($p['title'] ?? 'Product');
        $desc = $esc($p['description'] ?? '');
        $price = $esc($p['price'] ?? '');
        $rating = $esc($p['rating'] ?? '');
        $source = $p['source'] ?? 'generic';

        $imgSrc = str_starts_with($image, 'http') ? $image : '/' . ltrim($image, '/');
        $imgTag = '<img class="afp-card-img" src="' . $esc($imgSrc) . '" alt="' . $title . '" loading="lazy" onerror="this.parentElement.closest(\'.afp-card\')&&this.parentElement.closest(\'.afp-card\').remove()">';

        $ratingHtml = $rating ? '<span class="afp-rating">&#9733; ' . $rating . '</span>' : '';

        $html .= '<a class="afp-card" href="' . $url . '" target="_blank" rel="noopener nofollow sponsored">' . "\n"
            . $imgTag . "\n"
            . '<div class="afp-card-body">' . "\n"
            . '<div class="afp-card-title">' . $title . '</div>' . "\n"
            . ($desc ? '<div class="afp-card-desc">' . $desc . '</div>' . "\n" : '')
            . '<div class="afp-card-meta">'
            . ($price ? '<span class="afp-price">' . $price . '</span>' : '<span></span>')
            . $ratingHtml
            . '</div>' . "\n"
            . '</div>' . "\n"
            . '<div class="afp-card-footer">'
            . '<span class="afp-badge afp-badge-' . $esc($source) . '">' . $esc($source) . '</span>'
            . '<span class="afp-cta">View &#8594;</span>'
            . '</div>' . "\n"
            . '</a>' . "\n";
    }

    $html .= '</div>' . "\n";
    return $html;
}

/**
 * Render a single affiliate product card.
 */
function render_affiliate_product(array $attrs = []): string {
    $id = trim($attrs['slug'] ?? $attrs['id'] ?? '');
    if (!$id) return '<!-- affiliate-product: missing ID -->';

    $dataFile = SITE_ROOT . '/admin/data/AffiliateProducts/products.json';
    if (!is_file($dataFile)) return '<!-- affiliate-product: no products configured -->';

    $all = json_decode((string)file_get_contents($dataFile), true);
    if (!is_array($all)) return '<!-- affiliate-product: invalid data -->';

    $product = null;
    foreach ($all as $p) {
        if (($p['id'] ?? '') === $id
            && ($p['enabled'] ?? true)
            && empty($p['ignored'])
            && empty($p['unavailable'])) {
            $product = $p;
            break;
        }
    }
    if (!$product) return '<!-- affiliate-product: product not found -->';

    // Re-use the grid renderer with a single product wrapper
    $esc = function(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    };

    // Inject CSS (uses same static guard as grid)
    static $singleCssCheck = false;
    $css = '';
    if (!$singleCssCheck) {
        // Trigger CSS injection from grid renderer if not already done
        $singleCssCheck = true;
        // Call grid renderer with empty set just to inject CSS
        static $cssInjected;
        if (!isset($cssInjected) || !$cssInjected) {
            // CSS will be injected by render_affiliate_products if called,
            // or we inject it inline for standalone single-card usage
        }
    }

    $url = $esc(afp_click_url($product));
    $title = $esc($product['title'] ?? 'Product');
    $desc = $esc($product['description'] ?? '');
    $price = $esc($product['price'] ?? '');
    $rating = $esc($product['rating'] ?? '');
    $source = $product['source'] ?? 'generic';
    $image = $product['image'] ?? '';

    $imgSrc = $image
        ? (str_starts_with($image, 'http') ? $image : '/' . ltrim($image, '/'))
        : '';
    $imgTag = $imgSrc
        ? '<img class="afp-card-img" src="' . $esc($imgSrc) . '" alt="' . $title . '" loading="lazy">'
        : '<div class="afp-card-placeholder"><i class="fa-solid fa-box"></i></div>';
    $ratingHtml = $rating ? '<span class="afp-rating">&#9733; ' . $rating . '</span>' : '';

    // Ensure CSS is present
    $needCss = '';
    static $singleCssInjected = false;
    if (!$singleCssInjected) {
        $singleCssInjected = true;
        // Check if grid CSS was already injected
        static $cssInjectedRef;
        if (empty($cssInjectedRef)) {
            // Inject minimal CSS for single card
            $needCss = '<style>.afp-single{display:inline-block;max-width:300px;}.afp-single .afp-card{max-width:300px;}</style>';
        }
    }

    return $needCss
        . '<div class="afp-single">'
        . '<a class="afp-card" href="' . $url . '" target="_blank" rel="noopener nofollow sponsored">'
        . $imgTag
        . '<div class="afp-card-body">'
        . '<div class="afp-card-title">' . $title . '</div>'
        . ($desc ? '<div class="afp-card-desc">' . $desc . '</div>' : '')
        . '<div class="afp-card-meta">'
        . ($price ? '<span class="afp-price">' . $price . '</span>' : '<span></span>')
        . $ratingHtml
        . '</div>'
        . '</div>'
        . '<div class="afp-card-footer">'
        . '<span class="afp-badge afp-badge-' . $esc($source) . '">' . $esc($source) . '</span>'
        . '<span class="afp-cta">View &#8594;</span>'
        . '</div>'
        . '</a>'
        . '</div>';
}
