<?php
/**
 * PrintfulManager — Front-end Storefront Renderer
 *
 * Renders the Printful product catalog on public pages via [[printful-store]] shortcode.
 * Replaces legacy /panels/printful/panel-printful.php.
 *
 * Reads products from admin/data/printful/products.json (synced by admin).
 * Self-contained: inline CSS + JS, no external asset dependencies.
 *
 * Shortcode attrs:
 *   [[printful-store]]                   — full catalog
 *   [[printful-store product="12345"]]   — single product
 *   [[printful-store limit="6"]]         — limit display count
 *
 * @package    LuminalCMS
 * @module     PrintfulManager
 * @version    2.0.0
 * @file       admin/modules/PrintfulManager/includes/storefront_renderer.php
 * @date       2026-03-20
 * @replaces   /panels/printful/panel-printful.php, /panels/printful/includes/printful-public-api.php
 */

if (!function_exists('render_printful_store')) {

function render_printful_store(array $attrs = []): string {
    if (!defined('SITE_ROOT')) return '<!-- printful-store: SITE_ROOT not defined -->';

    require_once __DIR__ . '/PrintfulPublicAPI.php';
    $api = new PrintfulPublicAPI();

    $singleId = $attrs['product'] ?? ($attrs['id'] ?? null);
    $limit    = isset($attrs['limit']) ? (int)$attrs['limit'] : 0;
    $columns  = isset($attrs['columns']) ? max(1, min(6, (int)$attrs['columns'])) : 0;
    // layout: '' (default = full store with cart+search), 'single', 'grid', 'column'
    $layout   = (string)($attrs['layout'] ?? '');

    if ($singleId) {
        $result = $api->getProduct($singleId);
        $products = ($result['success'] && $result['product']) ? [$result['product']] : [];
        $isSingle = true;
    } else {
        $result = $api->getProducts();
        $products = $result['products'] ?? [];
        $isSingle = ($layout === 'single');
    }

    // Character filter — side-car map at admin/data/printful/character-map.json
    // Format: { "403881560": ["luffy", "zoro"], "401733183": ["luffy"] }
    // Products not in the map are excluded when filtering.
    $character = strtolower(trim((string)($attrs['character'] ?? '')));
    if ($character !== '' && !$isSingle) {
        $mapFile = SITE_ROOT . '/admin/data/printful/character-map.json';
        $charMap = is_file($mapFile) ? (json_decode(file_get_contents($mapFile), true) ?: []) : [];
        $products = array_values(array_filter($products, function($p) use ($charMap, $character) {
            $pid = (string)($p['id'] ?? '');
            $chars = $charMap[$pid] ?? [];
            if (is_string($chars)) $chars = [$chars];
            if (!is_array($chars)) return false;
            foreach ($chars as $c) {
                if (strtolower(trim((string)$c)) === $character) return true;
            }
            return false;
        }));
    }

    if ($limit > 0 && !$isSingle) {
        $products = array_slice($products, 0, $limit);
    }

    if (empty($products)) {
        return '<div style="text-align:center;padding:32px;color:#888">No products available at this time.</div>';
    }

    // Display modes:
    //   showChrome = show search bar + cart button (default store only)
    //   showAffiliate = show the footer CTA banner
    //   gridClass = CSS class for the grid wrapper
    $showChrome = ($layout === '' && !$isSingle);
    // Affiliate pill only on the full storefront (default layout). Embedded grid/product/column
    // layouts stay clean — they're inline with editorial content. Site owners can override
    // by passing show_affiliate="1" in the shortcode if they want it back.
    $showAffiliate = ($layout === '' && !$isSingle) || !empty($attrs['show_affiliate']);
    $gridClass = 'pfs-grid';
    if ($isSingle || $layout === 'single') $gridClass .= ' pfs-single';
    elseif ($layout === 'column') $gridClass .= ' pfs-column';
    elseif ($layout === 'grid') $gridClass .= ' pfs-compact-grid';
    // Column count style
    $gridStyle = '';
    if ($columns > 0 && $layout !== 'single' && $layout !== 'column') {
        $gridStyle = ' style="grid-template-columns:repeat(' . $columns . ',1fr)"';
    }

    // Image fit + background controls (Tom 2026-07-05):
    //   fit: 'natural' (default — image shown at its own aspect ratio, no crop) | 'cover' (uniform crop box)
    //   bg:  '' (default dark card) | 'transparent' | any CSS color — sets card + image-space background
    $imgFit     = strtolower(trim((string)($attrs['fit'] ?? 'natural')));
    $storeBg    = trim((string)($attrs['bg'] ?? ''));
    $storeExtra = ($imgFit === 'cover') ? ' pfs-fit-cover' : '';
    $storeStyle = ($storeBg !== '')
        ? ' style="--pfs-card-bg:' . htmlspecialchars($storeBg, ENT_QUOTES) . ';--pfs-img-bg:' . htmlspecialchars($storeBg, ENT_QUOTES) . '"'
        : '';

    // Build inline HTML — fully self-contained
    $html = '<style>' . _pf_storefront_css() . '</style>';
    $html .= '<div class="pfs-store' . ($layout ? ' pfs-layout-' . htmlspecialchars($layout) : '') . $storeExtra . '"' . $storeStyle . '>';

    if ($showChrome) {
        $html .= '<div class="pfs-header">';
        $html .= '<div class="pfs-cart-btn" onclick="pfsToggleCart()">&#128722; <span id="pfsCartCount">0</span></div>';
        $html .= '<input type="text" id="pfsSearch" class="pfs-search" placeholder="Search products..." oninput="pfsFilter()">';
        $html .= '</div>';
    }

    // Load cached prices if available, otherwise show "View for pricing"
    $priceCache = SITE_ROOT . '/admin/data/printful/price-cache.json';
    $prices = is_file($priceCache) ? json_decode(file_get_contents($priceCache), true) : [];

    $html .= '<div class="' . $gridClass . '"' . $gridStyle . ' id="pfsGrid">';

    foreach ($products as $p) {
        $id    = htmlspecialchars((string)($p['id'] ?? ''));
        $name  = htmlspecialchars($p['name'] ?? 'Product');
        $thumb = htmlspecialchars($p['thumbnail_url'] ?? '');
        $variants = $p['variants'] ?? 0;
        $vCount = is_array($variants) ? count($variants) : (int)$variants;
        $fromPrice = $prices[$p['id']] ?? null;

        $html .= '<div class="pfs-card" data-id="' . $id . '" data-name="' . strtolower($name) . '" onclick="pfsShowProduct(' . $id . ')">';
        if ($thumb) {
            $html .= '<div class="pfs-img"><img src="' . $thumb . '" alt="' . $name . '" loading="lazy"></div>';
        }
        $html .= '<div class="pfs-info">';
        $html .= '<div class="pfs-name">' . $name . '</div>';
        if ($fromPrice) {
            $html .= '<div style="color:#f59e0b;font-weight:800;font-size:.95rem;margin:4px 0">From $' . $fromPrice . '</div>';
        }
        if ($vCount > 0) $html .= '<div class="pfs-variants">' . $vCount . ' options</div>';
        $html .= '</div></div>';
    }

    $html .= '</div>';

    // Cart drawer + product modal are now rendered globally via footer.php → global_cart.php
    // (injected site-wide so cart survives page navigation)

    $html .= '</div>';

    // Load PayPal config — Printful's OWN dedicated payment config ONLY.
    // Decoupled from MyStore / PaymentProviders on purpose (2026-07-08): PrintfulManager
    // and MyStore are independent modules with NO shared payment dependency. A blank
    // paypal_client_id here means on-site PayPal is OFF and orders fall through to the
    // draft-order path (Printful invoices the store owner). Do NOT re-add cross-module
    // fallbacks — that is exactly what routed one site's payments to another's account.
    $paypalId = '';
    $pfCfgFile = SITE_ROOT . '/admin/data/printful/printful-config.json';
    if (is_file($pfCfgFile)) {
        $pfCfg = json_decode(file_get_contents($pfCfgFile), true) ?: [];
        $paypalId = $pfCfg['paypal_client_id'] ?? '';
    }

    // PayPal SDK
    if ($paypalId) {
        $html .= '<script src="https://www.paypal.com/sdk/js?client-id=' . htmlspecialchars($paypalId) . '&currency=USD&enable-funding=venmo,card&disable-funding=paylater"></script>';
    }

    // JS
    // Load FB App ID for share dialog
    $fbAppId = '';
    $fbConfigFile = SITE_ROOT . '/admin/data/FacebookEvents/config.json';
    if (is_file($fbConfigFile)) {
        $fbCfg = json_decode(file_get_contents($fbConfigFile), true) ?: [];
        $fbAppId = $fbCfg['fb_app_id'] ?? '';
    }

    $html .= '<script>var PFS_PRODUCTS=' . json_encode($products, JSON_UNESCAPED_SLASHES) . ';';
    $html .= 'var PFS_PAYPAL=' . json_encode((bool)$paypalId) . ';';
    if ($fbAppId) $html .= 'var PFS_FB_APP_ID=' . json_encode($fbAppId) . ';';
    $html .= _pf_storefront_js();
    $html .= '</script>';

    // Luminal CMS Printful affiliate — minimized pill, full-store only
    if ($showAffiliate) {
        $affLink = defined('PrintfulConfig::LUMINAL_AFFILIATE_LINK')
            ? PrintfulConfig::LUMINAL_AFFILIATE_LINK
            : 'https://www.printful.com/a/12997513:b269bfd8142a38fa45e7808e13d777ac';
        $html .= '<div class="pfs-affiliate-pill">'
              . '<a href="' . htmlspecialchars($affLink) . '" target="_blank" rel="noopener sponsored" class="pfs-affiliate-link">'
              . '<span class="pfs-affiliate-label">Powered by</span>'
              . '<strong>Printful</strong>'
              . '<span class="pfs-affiliate-arrow">→</span>'
              . '</a>'
              . '</div>';
    }

    return $html;
}

function _pf_storefront_css(): string {
    return <<<'CSS'
.pfs-store { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; }
.pfs-header { display:flex;align-items:center;gap:12px;margin-bottom:18px;flex-wrap:wrap }
.pfs-search { flex:1;min-width:200px;padding:10px 14px;border:1px solid rgba(255,255,255,0.1);border-radius:8px;background:rgba(255,255,255,0.04);color:#e0e0e0;font-size:.85rem;font-family:inherit }
.pfs-search:focus { outline:none;border-color:#f59e0b }
.pfs-cart-btn { padding:8px 14px;border-radius:8px;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.2);color:#f59e0b;font-weight:700;cursor:pointer;font-size:.85rem;white-space:nowrap }
.pfs-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px }
.pfs-grid.pfs-single { grid-template-columns:1fr;max-width:500px;margin:0 auto }
.pfs-grid.pfs-compact-grid { gap:12px }
.pfs-grid.pfs-column { grid-template-columns:1fr;gap:10px;max-width:320px }
.pfs-grid.pfs-column .pfs-card { display:flex;align-items:center;gap:10px }
.pfs-grid.pfs-column .pfs-img { width:70px;flex-shrink:0 }
.pfs-grid.pfs-column .pfs-img img { width:100%;height:70px;object-fit:cover;border-radius:6px 0 0 6px }
.pfs-grid.pfs-column .pfs-info { flex:1;min-width:0;padding:8px 10px 8px 0 }
.pfs-grid.pfs-column .pfs-name { font-size:.82rem;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical }
.pfs-grid.pfs-column .pfs-variants { font-size:.7rem }
.pfs-affiliate-pill { margin-top:32px;text-align:center }
.pfs-affiliate-link { display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border:1px solid rgba(255,255,255,0.08);border-radius:999px;background:rgba(255,255,255,0.03);color:#9ca3af;font-size:0.7rem;text-decoration:none;transition:color .15s,border-color .15s }
.pfs-affiliate-link:hover { color:#f3f6fc;border-color:rgba(220,30,90,0.4) }
.pfs-affiliate-label { font-size:0.65rem;letter-spacing:0.05em;text-transform:uppercase;opacity:0.7 }
.pfs-affiliate-link strong { color:inherit;font-weight:700 }
.pfs-affiliate-arrow { opacity:0.5;font-size:0.75rem }
.pfs-card { background:var(--pfs-card-bg,rgba(30,30,30,0.9));border-radius:10px;overflow:hidden;border:1px solid rgba(255,255,255,0.06);cursor:pointer;transition:transform .2s,box-shadow .2s }
.pfs-card:hover { transform:translateY(-3px);box-shadow:0 8px 24px rgba(0,0,0,0.4);border-color:rgba(245,158,11,0.2) }
.pfs-img img { width:100%;height:auto;object-fit:contain;display:block;background:var(--pfs-img-bg,transparent) }
.pfs-store.pfs-fit-cover .pfs-img img { height:220px;object-fit:cover }
.pfs-info { padding:12px }
.pfs-name { font-size:.92rem;font-weight:700;color:#f0f0f0;margin-bottom:4px }
.pfs-variants { font-size:.75rem;color:#888 }
/* Cart + modal styles live in global_cart.php (injected fleet-wide via footer) */
@media(max-width:768px) { .pfs-grid{grid-template-columns:1fr 1fr} }
@media(max-width:480px) { .pfs-grid{grid-template-columns:1fr} }
CSS;
}

function _pf_storefront_js(): string {
    return <<<'JS'
// Cart state (pfsCart, pfsToggleCart, pfsRemoveFromCart, pfsUpdateCart, pfsSetQty, etc.)
// is loaded globally from global_cart.php via footer.php. This script only defines
// the storefront-specific functions (filter, show product modal, checkout flow).
// Fall back defensively if global_cart hasn't loaded yet:
if (!window.pfsCart) window.pfsCart = JSON.parse(localStorage.getItem('pfs_cart')||'[]');
if (typeof pfsUpdateCart !== 'function') { window.pfsUpdateCart = function(){}; }
if (typeof pfsToggleCart !== 'function') { window.pfsToggleCart = function(){ var c=document.getElementById('pfsCart'); if(c) c.style.display=c.style.display==='none'?'flex':'none'; }; }

function pfsFilter(){
    var q=document.getElementById('pfsSearch').value.toLowerCase();
    document.querySelectorAll('.pfs-card').forEach(function(c){
        c.style.display=(!q||c.dataset.name.indexOf(q)>=0)?'':'none';
    });
}

function pfsShowProduct(id){
    var p=PFS_PRODUCTS.find(function(x){return x.id==id});
    if(!p)return;
    var body=document.getElementById('pfsModalBody');
    body.innerHTML='<div style="text-align:center;padding:20px;color:#888">Loading product details...</div>';
    document.getElementById('pfsModal').style.display='flex';

    // Fetch variants with prices from API
    fetch('/admin/modules/PrintfulManager/api/checkout.php',{
        method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action:'get_product',product_id:id})
    }).then(function(r){return r.json()}).then(function(d){
        if(!d.ok){body.innerHTML='<p style="color:#dc2626">'+d.error+'</p>';return}
        var pr=d.product;
        var vars=d.variants||[];
        var h='<div style="text-align:center;margin-bottom:16px">';
        // Portrait 4:5 frame — matches the vertical lifestyle mockup Facebook renders on share.
        if(pr.thumbnail) h+='<div style="width:100%;max-width:300px;aspect-ratio:4/5;margin:0 auto;background:#0a0a0a;border-radius:10px;overflow:hidden;display:flex;align-items:center;justify-content:center"><img src="'+pr.thumbnail+'" style="max-width:100%;max-height:100%;object-fit:contain" alt=""></div>';
        h+='</div>';
        h+='<h2 style="color:#f0f0f0;font-size:1.2rem;margin:0 0 4px">'+pr.name+'</h2>';
        if(vars.length){
            // Printful names variants "Product - Color / Size". The old code kept only the last
            // "/" segment (size), silently dropping color. Parse the descriptor into axes so we
            // can offer Color + Size as dependent dropdowns (fallback: one "Select Option" list
            // showing the full descriptor, so color is still visible when there's no clean split).
            var pname=(pr.name||'');
            function pfsSuffix(vn){
                var s=(vn||'');
                if(pname && s.indexOf(pname)===0) s=s.slice(pname.length);
                return s.replace(/^\s*[-–—:]\s*/,'').trim();   // strip the joiner after the product name
            }
            var parsed=vars.map(function(v){
                var suf=pfsSuffix(v.name);
                var segs=suf.split('/').map(function(x){return x.trim()}).filter(Boolean);
                var hasColor=segs.length>=2;
                return {v:v, suffix:suf||(v.name||''), color:hasColor?segs[0]:'', size:hasColor?segs[segs.length-1]:(segs[0]||''), hasColor:hasColor};
            });
            var colors=[]; parsed.forEach(function(x){ if(x.color && colors.indexOf(x.color)<0) colors.push(x.color); });
            var twoAxes=parsed.length>0 && parsed.every(function(x){return x.hasColor}) && colors.length>1;
            window._pfsParsed=parsed; window._pfsTwoAxes=twoAxes;
            var selCss='width:100%;padding:10px;border:1px solid rgba(255,255,255,0.1);border-radius:8px;background:rgba(255,255,255,0.04);color:#e0e0e0;font-size:.88rem;font-family:inherit';
            var lblCss='font-size:.72rem;color:#888;text-transform:uppercase;font-weight:700;display:block;margin-bottom:6px';
            h+='<div style="margin:12px 0">';
            if(twoAxes){
                h+='<label style="'+lblCss+'">Color</label>';
                h+='<select id="pfsColorSel" style="'+selCss+';margin-bottom:10px">';
                colors.forEach(function(c){ h+='<option value="'+c.replace(/"/g,'&quot;')+'">'+c+'</option>'; });
                h+='</select>';
                h+='<label style="'+lblCss+'">Size</label>';
                h+='<select id="pfsVariantSel" style="'+selCss+'"></select>';   // filled by color on wire
            } else {
                h+='<label style="'+lblCss+'">Select Option</label>';
                h+='<select id="pfsVariantSel" style="'+selCss+'">';
                parsed.forEach(function(x){
                    var full=x.hasColor?(x.color+' / '+x.size):(x.suffix||'Option');
                    h+='<option value="'+x.v.id+'" data-price="'+x.v.retail_price+'" data-vid="'+x.v.variant_id+'" data-label="'+full.replace(/"/g,'&quot;')+'">'+full+' — $'+x.v.retail_price+'</option>';
                });
                h+='</select>';
            }
            h+='</div>';
        }
        h+='<div style="font-size:1.3rem;font-weight:900;color:#f59e0b;margin:10px 0" id="pfsPrice">$'+(vars[0]?vars[0].retail_price:'0.00')+'</div>';
        h+='<button onclick="pfsAddVariantToCart('+pr.id+')" style="width:100%;padding:12px;border:none;border-radius:8px;background:#f59e0b;color:#000;font-weight:800;font-size:.88rem;cursor:pointer;font-family:inherit">Add to Cart</button>';
        // Share buttons
        h+='<div style="display:flex;gap:8px;margin-top:12px;justify-content:center">';
        var shareUrl=location.origin+'/page.php?p=printful-store&product='+pr.id;
        var shareText=pr.name+(vars[0]?' — $'+vars[0].retail_price:'')+' — Check it out!';
        var imgUrl=pr.thumbnail||'';
        window._pfsShareData={name:pr.name,img:imgUrl,url:shareUrl,price:vars[0]?vars[0].retail_price:'',text:shareText};
        h+='<button onclick="pfsShareFB()" style="padding:8px 14px;border-radius:6px;background:#1877F2;color:#fff;font-size:.75rem;font-weight:700;cursor:pointer;border:none;font-family:inherit;display:flex;align-items:center;gap:5px"><svg width="14" height="14" fill="white" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>Share</button>';
        h+='<a href="https://twitter.com/intent/tweet?text='+encodeURIComponent(shareText)+'&url='+encodeURIComponent(shareUrl)+'" target="_blank" style="padding:8px 14px;border-radius:6px;background:#000;color:#fff;font-size:.75rem;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:5px"><svg width="14" height="14" fill="white" viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>Post</a>';
        h+='<button onclick="pfsShareClipboard()" style="padding:8px 14px;border-radius:6px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.1);color:#ccc;font-size:.75rem;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:5px">&#128279; Copy Link</button>';
        h+='</div>';
        body.innerHTML=h;

        var sel=document.getElementById('pfsVariantSel');
        var csel=document.getElementById('pfsColorSel');
        function pfsSyncPrice(){ var o=sel&&sel.selectedIndex>=0?sel.options[sel.selectedIndex]:null; if(o&&o.dataset) document.getElementById('pfsPrice').textContent='$'+o.dataset.price; }
        if(csel && window._pfsTwoAxes){
            function pfsFillSizes(){
                var color=csel.value;
                sel.innerHTML=(window._pfsParsed||[]).filter(function(x){return x.color===color}).map(function(x){
                    var full=x.color+' / '+x.size;
                    return '<option value="'+x.v.id+'" data-price="'+x.v.retail_price+'" data-vid="'+x.v.variant_id+'" data-label="'+full.replace(/"/g,'&quot;')+'">'+(x.size||full)+' — $'+x.v.retail_price+'</option>';
                }).join('');
                pfsSyncPrice();
            }
            csel.onchange=pfsFillSizes;
            pfsFillSizes();
        } else {
            pfsSyncPrice();
        }
        if(sel) sel.onchange=pfsSyncPrice;
    });
}

function pfsAddVariantToCart(productId){
    var p=PFS_PRODUCTS.find(function(x){return x.id==productId});
    if(!p)return;
    var sel=document.getElementById('pfsVariantSel');
    var opt=sel?sel.options[sel.selectedIndex]:null;
    var item={
        id:productId,
        name:p.name+(opt?' ('+((opt.dataset&&opt.dataset.label)?opt.dataset.label:opt.text.split('—')[0].trim())+')':''),
        img:p.thumbnail_url||'',
        qty:1,
        sync_variant_id:opt?parseInt(opt.value):0,
        variant_id:opt?parseInt(opt.dataset.vid):0,
        retail_price:opt?opt.dataset.price:'0.00'
    };
    var existing=pfsCart.find(function(c){return c.sync_variant_id===item.sync_variant_id});
    if(existing){existing.qty++}else{pfsCart.push(item)}
    localStorage.setItem('pfs_cart',JSON.stringify(pfsCart));
    pfsUpdateCart();
    document.getElementById('pfsModal').style.display='none';
    pfsToggleCart();
}

function pfsAddToCart(id){
    var p=PFS_PRODUCTS.find(function(x){return x.id==id});
    if(!p)return;
    var existing=pfsCart.find(function(c){return c.id==id});
    if(existing){existing.qty++}else{pfsCart.push({id:p.id,name:p.name,img:p.thumbnail_url||'',qty:1})}
    localStorage.setItem('pfs_cart',JSON.stringify(pfsCart));
    pfsUpdateCart();
    document.getElementById('pfsModal').style.display='none';
    pfsToggleCart();
}

// pfsRemoveFromCart + pfsUpdateCart are defined globally in global_cart.php

function pfsCheckout(){
    if(!pfsCart.length)return;
    var body=document.getElementById('pfsModalBody');
    var subtotal=pfsCart.reduce(function(s,c){return s+parseFloat(c.retail_price||0)*c.qty},0);

    var h='<h2 style="color:#f0f0f0;font-size:1.1rem;margin:0 0 16px">Checkout</h2>';

    // Order summary
    h+='<div style="margin-bottom:16px;padding:12px;background:rgba(255,255,255,0.03);border-radius:8px;border:1px solid rgba(255,255,255,0.06)">';
    pfsCart.forEach(function(c){
        h+='<div style="display:flex;justify-content:space-between;padding:4px 0;font-size:.82rem"><span style="color:#e0e0e0">'+c.name+' x'+c.qty+'</span><span style="color:#f59e0b;font-weight:700">$'+(parseFloat(c.retail_price||0)*c.qty).toFixed(2)+'</span></div>';
    });
    h+='<div style="display:flex;justify-content:space-between;padding:8px 0 0;margin-top:8px;border-top:1px solid rgba(255,255,255,0.06);font-weight:800;color:#f0f0f0"><span>Total</span><span>$'+subtotal.toFixed(2)+'</span></div>';
    h+='<div style="font-size:.68rem;color:#888;margin-top:4px">Free shipping on orders over $50</div>';
    h+='</div>';

    // Shipping form
    var fld='style="width:100%;padding:9px 12px;border:1px solid rgba(255,255,255,0.1);border-radius:8px;background:rgba(255,255,255,0.04);color:#e0e0e0;font-size:.85rem;font-family:inherit;margin-bottom:8px"';
    h+='<input type="text" id="pfsName" placeholder="Full Name" required '+fld+'>';
    h+='<input type="email" id="pfsEmail" placeholder="Email" required '+fld+'>';
    h+='<input type="tel" id="pfsPhone" placeholder="Phone (optional)" '+fld+'>';
    h+='<input type="text" id="pfsAddr1" placeholder="Address Line 1" required '+fld+'>';
    h+='<input type="text" id="pfsAddr2" placeholder="Apt, Suite (optional)" '+fld+'>';
    h+='<div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:6px">';
    h+='<input type="text" id="pfsCity" placeholder="City" required '+fld+'>';
    h+='<input type="text" id="pfsState" placeholder="State" maxlength="2" '+fld+'>';
    h+='<input type="text" id="pfsZip" placeholder="ZIP" required '+fld+'>';
    h+='</div>';

    h+='<div id="pfsCheckoutStatus" style="display:none;font-size:.82rem;padding:8px 0;text-align:center"></div>';

    // Payment section
    if(PFS_PAYPAL){
        h+='<div style="margin-top:12px;padding-top:12px;border-top:1px solid rgba(255,255,255,0.06)">';
        h+='<div style="font-size:.72rem;color:#888;text-transform:uppercase;font-weight:700;margin-bottom:8px">Pay with PayPal, Credit or Debit Card</div>';
        h+='<div id="pfsPaypalButtons"></div>';
        h+='</div>';
    }else{
        h+='<button onclick="pfsPlaceOrder()" style="width:100%;padding:14px;border:none;border-radius:8px;background:#f59e0b;color:#000;font-weight:900;font-size:.95rem;cursor:pointer;font-family:inherit;margin-top:12px">Place Order — $'+subtotal.toFixed(2)+'</button>';
        h+='<div style="font-size:.65rem;color:#dc2626;text-align:center;margin-top:6px">Payment processing not configured — order will be created as draft</div>';
    }
    h+='<div style="font-size:.65rem;color:#666;text-align:center;margin-top:8px">Fulfilled &amp; shipped by Printful</div>';

    body.innerHTML=h;
    document.getElementById('pfsModal').style.display='flex';

    // Render PayPal buttons
    if(PFS_PAYPAL && typeof paypal!=='undefined'){
        paypal.Buttons({
            style:{layout:'vertical',color:'gold',shape:'rect',label:'pay',height:45},
            fundingSource: undefined,
            createOrder:function(data,actions){
                // Validate shipping fields first
                if(!pfsValidateShipping()) return actions.reject();
                return actions.order.create({
                    purchase_units:[{
                        description:'Printful Store Order',
                        amount:{currency_code:'USD',value:subtotal.toFixed(2)},
                    }]
                });
            },
            onApprove:function(data,actions){
                return actions.order.capture().then(function(details){
                    // Payment captured — now create Printful order
                    pfsCreatePrintfulOrder(details.id, details.payer.email_address||'');
                });
            },
            onError:function(err){
                var s=document.getElementById('pfsCheckoutStatus');
                s.style.display='block';s.style.color='#dc2626';
                s.textContent='Payment error: '+(err.message||'Please try again');
            }
        }).render('#pfsPaypalButtons');
    }
}

function pfsValidateShipping(){
    var name=document.getElementById('pfsName').value.trim();
    var email=document.getElementById('pfsEmail').value.trim();
    var addr=document.getElementById('pfsAddr1').value.trim();
    var city=document.getElementById('pfsCity').value.trim();
    var zip=document.getElementById('pfsZip').value.trim();
    if(!name||!email||!addr||!city||!zip){
        var s=document.getElementById('pfsCheckoutStatus');
        s.style.display='block';s.style.color='#dc2626';
        s.textContent='Please fill in all shipping fields before paying';
        return false;
    }
    return true;
}

function pfsCreatePrintfulOrder(paypalTxId, payerEmail){
    var status=document.getElementById('pfsCheckoutStatus');
    status.style.display='block';status.style.color='#888';status.textContent='Processing order...';

    var items=pfsCart.map(function(c){return{sync_variant_id:c.sync_variant_id,variant_id:c.variant_id,quantity:c.qty,retail_price:c.retail_price}});

    fetch('/admin/modules/PrintfulManager/api/checkout.php',{
        method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({
            action:'create_order',
            payment_id:paypalTxId,
            payment_method:'paypal',
            customer:{
                name:document.getElementById('pfsName').value.trim(),
                email:document.getElementById('pfsEmail').value.trim(),
                phone:document.getElementById('pfsPhone').value.trim(),
                address1:document.getElementById('pfsAddr1').value.trim(),
                address2:document.getElementById('pfsAddr2').value.trim(),
                city:document.getElementById('pfsCity').value.trim(),
                state:document.getElementById('pfsState').value.trim(),
                zip:document.getElementById('pfsZip').value.trim(),
                country:'US'
            },
            items:items
        })
    }).then(function(r){return r.json()}).then(function(d){
        if(d.ok){
            status.style.color='#16a34a';
            status.textContent='Order confirmed! Check your email for confirmation.';
            pfsCart=[];
            localStorage.removeItem('pfs_cart');
            pfsUpdateCart();
            setTimeout(function(){document.getElementById('pfsModal').style.display='none'},3000);
        }else{
            status.style.color='#dc2626';
            status.textContent=d.error||'Order failed — payment was captured, contact support';
        }
    }).catch(function(){status.style.color='#dc2626';status.textContent='Network error — payment captured, contact support'});
}

function pfsShareFB(){
    var d=window._pfsShareData;if(!d)return;
    var fbUrl;
    if(window.PFS_FB_APP_ID){
        fbUrl='https://www.facebook.com/dialog/feed?'+
            'app_id='+PFS_FB_APP_ID+
            '&display=popup'+
            '&link='+encodeURIComponent(d.url)+
            '&picture='+encodeURIComponent(d.img)+
            '&name='+encodeURIComponent(d.name)+
            '&caption='+encodeURIComponent(d.price?'$'+d.price:'')+
            '&description='+encodeURIComponent('Check out this product!')+
            '&redirect_uri='+encodeURIComponent(location.origin);
    }else{
        fbUrl='https://www.facebook.com/sharer/sharer.php?u='+encodeURIComponent(d.url)+
            '&quote='+encodeURIComponent(d.text);
    }
    window.open(fbUrl,'fb-share','width=600,height=500,scrollbars=yes');
}

function pfsShareClipboard(){
    var d=window._pfsShareData;if(!d)return;
    var text=d.name+(d.price?' — $'+d.price:'')+'\n'+d.url;
    navigator.clipboard.writeText(text).then(function(){
        var n=document.createElement('div');
        n.style.cssText='position:fixed;top:20px;right:20px;z-index:99999;background:#13151a;border:1px solid #16a34a;border-radius:10px;padding:12px 18px;color:#16a34a;font-weight:700;font-size:.82rem;box-shadow:0 8px 24px rgba(0,0,0,0.4)';
        n.textContent='Link copied!';
        document.body.appendChild(n);
        setTimeout(function(){n.remove()},2000);
    });
}

function pfsPlaceOrder(){
    // Fallback for no-PayPal mode — creates draft order
    if(!pfsValidateShipping())return;
    var status=document.getElementById('pfsCheckoutStatus');
    status.style.display='block';status.style.color='#888';status.textContent='Creating order...';
    var items=pfsCart.map(function(c){return{sync_variant_id:c.sync_variant_id,variant_id:c.variant_id,quantity:c.qty,retail_price:c.retail_price}});
    fetch('/admin/modules/PrintfulManager/api/checkout.php',{
        method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({
            action:'create_order',
            customer:{
                name:document.getElementById('pfsName').value.trim(),
                email:document.getElementById('pfsEmail').value.trim(),
                phone:document.getElementById('pfsPhone').value.trim(),
                address1:document.getElementById('pfsAddr1').value.trim(),
                address2:document.getElementById('pfsAddr2').value.trim(),
                city:document.getElementById('pfsCity').value.trim(),
                state:document.getElementById('pfsState').value.trim(),
                zip:document.getElementById('pfsZip').value.trim(),
                country:'US'
            },
            items:items
        })
    }).then(function(r){return r.json()}).then(function(d){
        if(d.ok){
            status.style.color='#16a34a';status.textContent='Order submitted!';
            pfsCart=[];localStorage.removeItem('pfs_cart');pfsUpdateCart();
            setTimeout(function(){document.getElementById('pfsModal').style.display='none'},3000);
        }else{status.style.color='#dc2626';status.textContent=d.error||'Failed';}
    }).catch(function(){status.style.color='#dc2626';status.textContent='Network error';});
}
JS;
}

} // end function_exists guard
