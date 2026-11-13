<?php
/**
 * Printful Global Cart — site-wide cart drawer
 *
 * Injected into footer.php on every page that includes Luminal's footer.
 * Renders: cart drawer HTML + shared cart JS (state, +/- qty, remove, checkout).
 * Works in conjunction with:
 *   - Cart icon in header (document_header_body.php)
 *   - Per-product add-to-cart logic in storefront_renderer.php
 *
 * Guards:
 *   - Only renders if PrintfulManager is installed on this site (module.json present)
 *   - Only renders once per page (static flag to prevent double-inject)
 */

if (!function_exists('pf_render_global_cart')) {
    function pf_render_global_cart(): void {
        static $rendered = false;
        if ($rendered) return;
        $rendered = true;

        // Guard: only render if PrintfulManager is actually installed
        $moduleJson = (defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 4))
            . '/admin/modules/PrintfulManager/module.json';
        if (!is_file($moduleJson)) return;
        ?>
<!-- Printful Global Cart (fleet-wide, injected via footer) -->
<div class="pfs-cart" id="pfsCart" style="display:none">
  <div class="pfs-cart-header">
    <h3>Shopping Cart</h3>
    <button onclick="pfsToggleCart()" style="background:none;border:none;color:#888;font-size:1.5rem;cursor:pointer">&times;</button>
  </div>
  <div id="pfsCartItems"><p style="color:#666;padding:12px">Your cart is empty</p></div>
  <div class="pfs-cart-footer">
    <div>Total: <strong id="pfsCartTotal">$0.00</strong></div>
    <button class="pfs-checkout-btn" onclick="pfsCheckout()">Proceed to Checkout</button>
  </div>
</div>

<!-- Shared product detail modal (used by storefront + cart flows) -->
<div class="pfs-modal" id="pfsModal" style="display:none" onclick="if(event.target===this)this.style.display='none'">
  <div class="pfs-modal-content">
    <button class="pfs-modal-close" onclick="document.getElementById('pfsModal').style.display='none'">&times;</button>
    <div id="pfsModalBody"></div>
  </div>
</div>

<style>
/* Scoped cart styles — duplicated minimally from storefront to cover pages without [[printful-*]] */
.pfs-cart{position:fixed;top:0;right:0;width:360px;max-width:90vw;height:100vh;background:#13151a;border-left:1px solid rgba(255,255,255,0.08);box-shadow:-8px 0 30px rgba(0,0,0,0.5);z-index:9999;display:flex;flex-direction:column;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}
.pfs-cart-header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid rgba(255,255,255,0.06)}
.pfs-cart-header h3{margin:0;color:#f0f0f0;font-size:1rem}
#pfsCartItems{flex:1;overflow-y:auto;padding:12px 20px}
.pfs-cart-footer{padding:16px 20px;border-top:1px solid rgba(255,255,255,0.06);color:#f0f0f0}
.pfs-cart-item{display:flex;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.04)}
.pfs-cart-item img{width:50px;height:50px;border-radius:6px;object-fit:cover;flex-shrink:0}
.pfs-cart-item-info{flex:1;min-width:0;font-size:.82rem}
.pfs-cart-item-name{color:#f0f0f0;font-weight:600;margin-bottom:3px;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.pfs-cart-item-price{color:#f59e0b;font-weight:700;font-size:.82rem;margin-bottom:4px}
.pfs-qty-ctl{display:inline-flex;align-items:center;gap:2px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.1);border-radius:6px;overflow:hidden}
.pfs-qty-btn{background:transparent;border:none;color:#e0e0e0;font-size:.95rem;font-weight:700;width:24px;height:24px;cursor:pointer;line-height:1;padding:0}
.pfs-qty-btn:hover{background:rgba(245,158,11,0.15);color:#f59e0b}
.pfs-qty-val{min-width:22px;text-align:center;font-size:.78rem;color:#f0f0f0;font-weight:600;line-height:1}
.pfs-cart-item-rm{background:none;border:none;color:#dc2626;cursor:pointer;font-size:1.15rem;flex-shrink:0;margin-left:4px;padding:0 4px}
.pfs-cart-item-rm:hover{color:#f87171}
.pfs-checkout-btn{width:100%;padding:12px;border:none;border-radius:8px;background:#f59e0b;color:#000;font-weight:800;font-size:.88rem;cursor:pointer;margin-top:10px;font-family:inherit}
.pfs-checkout-btn:hover{background:#fbbf24}
.pfs-modal{position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,0.7);backdrop-filter:blur(6px);display:flex;align-items:center;justify-content:center}
.pfs-modal-content{background:#13151a;border:1px solid rgba(255,255,255,0.1);border-radius:14px;width:500px;max-width:94vw;max-height:85vh;overflow-y:auto;padding:24px;position:relative;box-shadow:0 20px 60px rgba(0,0,0,0.6);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}
.pfs-modal-close{position:absolute;top:12px;right:16px;background:none;border:none;color:#888;font-size:1.5rem;cursor:pointer}
/* Cart icon in header */
.pfs-cart-icon{position:relative;display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:8px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);color:inherit;text-decoration:none;cursor:pointer;transition:background .15s,border-color .15s;margin-left:8px}
.pfs-cart-icon:hover{background:rgba(245,158,11,0.12);border-color:rgba(245,158,11,0.35)}
.pfs-cart-icon svg{width:18px;height:18px}
.pfs-cart-badge{position:absolute;top:-4px;right:-4px;min-width:18px;height:18px;padding:0 5px;border-radius:9px;background:#dc2626;color:#fff;font-size:.62rem;font-weight:800;display:flex;align-items:center;justify-content:center;line-height:1}
.pfs-cart-badge.empty{background:#475569;color:#cbd5e1}
@media(max-width:768px){.pfs-cart{width:100%}}
</style>

<script>
// Global Printful cart — state + handlers. Works independently of any [[printful-*]] shortcode render.
(function(){
  if (window.PFS_CART_LOADED) return;
  window.PFS_CART_LOADED = true;

  window.pfsCart = JSON.parse(localStorage.getItem('pfs_cart') || '[]');

  window.pfsToggleCart = function() {
    var c = document.getElementById('pfsCart');
    if (!c) return;
    c.style.display = c.style.display === 'none' ? 'flex' : 'none';
  };

  window.pfsRemoveFromCart = function(idx) {
    pfsCart.splice(idx, 1);
    localStorage.setItem('pfs_cart', JSON.stringify(pfsCart));
    pfsUpdateCart();
  };

  window.pfsSetQty = function(idx, qty) {
    qty = Math.max(0, parseInt(qty, 10) || 0);
    if (qty === 0) { pfsRemoveFromCart(idx); return; }
    if (qty > 99) qty = 99;
    pfsCart[idx].qty = qty;
    localStorage.setItem('pfs_cart', JSON.stringify(pfsCart));
    pfsUpdateCart();
  };

  window.pfsIncQty = function(idx) { pfsSetQty(idx, (pfsCart[idx] && pfsCart[idx].qty || 0) + 1); };
  window.pfsDecQty = function(idx) { pfsSetQty(idx, (pfsCart[idx] && pfsCart[idx].qty || 0) - 1); };

  window.pfsUpdateCart = function() {
    var count = pfsCart.reduce(function(s, c) { return s + c.qty; }, 0);
    // Update ALL cart count elements (header icon badge + any inline counters)
    document.querySelectorAll('#pfsCartCount, .pfs-cart-count').forEach(function(el) {
      el.textContent = count;
    });
    // Badge empty styling
    document.querySelectorAll('.pfs-cart-badge').forEach(function(el) {
      el.classList.toggle('empty', count === 0);
    });
    var items = document.getElementById('pfsCartItems');
    if (!items) return;
    if (!pfsCart.length) {
      items.innerHTML = '<p style="color:#666;padding:12px">Your cart is empty</p>';
      var totalEl0 = document.getElementById('pfsCartTotal');
      if (totalEl0) totalEl0.textContent = '$0.00';
      return;
    }
    var h = '';
    var total = 0;
    pfsCart.forEach(function(c, i) {
      var lineTotal = parseFloat(c.retail_price || 0) * c.qty;
      total += lineTotal;
      h += '<div class="pfs-cart-item">';
      if (c.img) h += '<img src="' + c.img + '" alt="">';
      h += '<div class="pfs-cart-item-info">';
      h += '<div class="pfs-cart-item-name">' + (c.name || 'Item') + '</div>';
      h += '<div class="pfs-cart-item-price">$' + lineTotal.toFixed(2) + '</div>';
      h += '<div class="pfs-qty-ctl" role="group" aria-label="Quantity">';
      h += '<button class="pfs-qty-btn" onclick="pfsDecQty(' + i + ')" aria-label="Decrease quantity">−</button>';
      h += '<span class="pfs-qty-val">' + c.qty + '</span>';
      h += '<button class="pfs-qty-btn" onclick="pfsIncQty(' + i + ')" aria-label="Increase quantity">+</button>';
      h += '</div></div>';
      h += '<button class="pfs-cart-item-rm" onclick="pfsRemoveFromCart(' + i + ')" aria-label="Remove item">&times;</button>';
      h += '</div>';
    });
    items.innerHTML = h;
    var totalEl = document.getElementById('pfsCartTotal');
    if (totalEl) totalEl.textContent = '$' + total.toFixed(2);
  };

  // Sync across tabs
  window.addEventListener('storage', function(e) {
    if (e.key === 'pfs_cart') {
      window.pfsCart = JSON.parse(e.newValue || '[]');
      pfsUpdateCart();
    }
  });

  // Initial render on page load
  document.addEventListener('DOMContentLoaded', pfsUpdateCart);
  // Also try immediate render in case DOMContentLoaded already fired
  if (document.readyState !== 'loading') pfsUpdateCart();
})();
</script>
<?php
    }
}
