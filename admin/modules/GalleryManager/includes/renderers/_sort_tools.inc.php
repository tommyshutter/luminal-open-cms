<?php
/**
 * Front-end Sort Tools — shared helper
 * @file /includes/renderers/_sort_tools.inc.php
 *
 * Reusable "sort tools" toolbar for any public item grid (image / video / pdf /
 * combined galleries today; Facebook Events next). It is layout-agnostic: it
 * reorders the DOM children of a container, so grid / masonry / rows all follow.
 *
 * A renderer integrates in three small steps:
 *   1) decide:   $on = function_exists('st_enabled') && st_enabled($data, $opts);
 *   2) per item: echo "<figure" . ($on ? st_item_attrs($path, $label) : '') . ">";
 *                (stamps data-name + data-mtime sort keys on each item element)
 *   3) wrap:     if ($on) $html = st_wrap($html, $slug, st_default($data, $opts));
 *                ($html must be the single container element to be sorted)
 *
 * No defaults are forced on galleries that don't opt in — every entry point is
 * guarded by st_enabled(), and the assets/JS emit only once per request.
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(__DIR__ . '/../../../../..') ?: dirname(__DIR__, 5));
}

if (!function_exists('st_truthy')) {
  function st_truthy($v): bool {
    if (is_bool($v)) return $v;
    if (is_int($v))  return $v !== 0;
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1','true','yes','on','enabled'], true);
  }
}

/** Is the sort toolbar enabled? Shortcode attrs ($opts) override saved data. */
if (!function_exists('st_enabled')) {
  function st_enabled(array $data, array $opts = []): bool {
    foreach (['sort','sort_tools','sorttools'] as $k) {
      if (array_key_exists($k, $opts)) return st_truthy($opts[$k]);
    }
    foreach (['sort_tools','sortTools','sorttools'] as $k) {
      if (array_key_exists($k, $data)) return st_truthy($data[$k]);
    }
    if (isset($data['options']) && is_array($data['options'])) {
      foreach (['sort_tools','sortTools','sorttools'] as $k) {
        if (array_key_exists($k, $data['options'])) return st_truthy($data['options'][$k]);
      }
    }
    return false;
  }
}

/** Default ordering applied on first paint. One of: newest|oldest|az|za|custom. */
if (!function_exists('st_default')) {
  function st_default(array $data, array $opts = []): string {
    $valid = ['newest','oldest','az','za','custom'];
    foreach ([$opts, $data, ($data['options'] ?? [])] as $src) {
      if (!is_array($src)) continue;
      foreach (['sort_default','sortDefault','sort_order'] as $k) {
        if (!empty($src[$k])) {
          $v = strtolower((string)$src[$k]);
          if (in_array($v, $valid, true)) return $v;
        }
      }
    }
    return 'newest';
  }
}

/** Per-item sort keys to inject into an item element's opening tag. */
if (!function_exists('st_item_attrs')) {
  function st_item_attrs(string $path, string $label = ''): string {
    $urlPath = parse_url($path, PHP_URL_PATH);
    $name = $label !== '' ? $label : basename((string)($urlPath !== false && $urlPath !== null ? $urlPath : $path));
    $mtime = 0;
    if (!preg_match('~^https?://~i', $path)) {
      $fs = SITE_ROOT . '/' . ltrim((string)($urlPath ?: $path), '/');
      if (is_file($fs)) $mtime = (int)@filemtime($fs);
    }
    return ' data-name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" data-mtime="' . $mtime . '"';
  }
}

/** mtime-only attr — for items that already emit their own data-name. */
if (!function_exists('st_mtime_attr')) {
  function st_mtime_attr(string $path): string {
    $mtime = 0;
    if (!preg_match('~^https?://~i', $path)) {
      $urlPath = parse_url($path, PHP_URL_PATH);
      $fs = SITE_ROOT . '/' . ltrim((string)($urlPath ?: $path), '/');
      if (is_file($fs)) $mtime = (int)@filemtime($fs);
    }
    return ' data-mtime="' . $mtime . '"';
  }
}

/** The toolbar markup (scoped to one .st-wrap). */
if (!function_exists('st_toolbar')) {
  function st_toolbar(string $slug, string $default = 'newest'): string {
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $btns = [
      'newest' => 'Most Recent',
      'oldest' => 'Oldest',
      'az'     => 'A → Z',
      'za'     => 'Z → A',
    ];
    $out = '<div class="st-bar" role="toolbar" aria-label="Sort items" data-default="' . $h($default) . '">'
         . '<span class="st-bar-label">Sort</span>';
    foreach ($btns as $key => $lbl) {
      $active = ($key === $default) ? ' is-active' : '';
      $out .= '<button type="button" class="st-btn' . $active . '" data-sort="' . $h($key) . '"'
            . ' aria-pressed="' . ($active ? 'true' : 'false') . '">' . $h($lbl) . '</button>';
    }
    $out .= '</div>';
    return $out;
  }
}

/** One-time CSS + JS (engine). Safe to call per item grid — emits once. */
if (!function_exists('st_assets')) {
  function st_assets(): string {
    static $done = false;
    if ($done) return '';
    $done = true;
    $css = <<<CSS
<style id="st-sort-tools-css">
.st-wrap{display:block}
.st-bar{display:flex;flex-wrap:wrap;align-items:center;gap:.4rem;margin:0 0 .75rem;}
.st-bar-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;opacity:.6;margin-right:.15rem;}
.st-btn{appearance:none;cursor:pointer;font:inherit;font-size:.8rem;font-weight:600;line-height:1;
  padding:.4rem .7rem;border-radius:999px;border:1px solid rgba(127,127,127,.35);
  background:rgba(127,127,127,.08);color:inherit;transition:background .15s,border-color .15s,color .15s;}
.st-btn:hover{background:rgba(127,127,127,.18);border-color:rgba(127,127,127,.55);}
.st-btn.is-active{background:rgba(56,189,248,.16);border-color:rgba(56,189,248,.7);color:#0ea5e9;}
@media (prefers-color-scheme:dark){.st-btn.is-active{color:#7dd3fc;}}
</style>
CSS;
    $js = <<<'JS'
<script id="st-sort-tools-js">
(function(){
  function key(el,mode){
    if(mode==='az'||mode==='za') return (el.getAttribute('data-name')||'').toLowerCase();
    return parseInt(el.getAttribute('data-mtime')||'0',10)||0;
  }
  function sortGrid(grid,mode){
    var items=Array.prototype.slice.call(grid.children);
    items.forEach(function(el,i){ if(el.__sti==null) el.__sti=i; }); // stable original index
    var coll=new Intl.Collator(undefined,{numeric:true,sensitivity:'base'});
    items.sort(function(a,b){
      if(mode==='az') return coll.compare(key(a,'az'),key(b,'az')) || (a.__sti-b.__sti);
      if(mode==='za') return coll.compare(key(b,'za'),key(a,'za')) || (a.__sti-b.__sti);
      var ka=key(a,'m'),kb=key(b,'m');
      if(mode==='oldest') return (ka-kb) || (a.__sti-b.__sti);
      return (kb-ka) || (a.__sti-b.__sti); // newest
    });
    var frag=document.createDocumentFragment();
    items.forEach(function(el){ frag.appendChild(el); });
    grid.appendChild(frag);
  }
  function initWrap(wrap){
    if(wrap.__stInit) return; wrap.__stInit=1;
    var bar=wrap.querySelector('.st-bar'); if(!bar) return;
    var grid=bar.nextElementSibling; if(!grid) return;
    var def=bar.getAttribute('data-default')||'newest';
    bar.addEventListener('click',function(e){
      var btn=e.target.closest('.st-btn'); if(!btn) return;
      var mode=btn.getAttribute('data-sort');
      bar.querySelectorAll('.st-btn').forEach(function(b){
        var on=b===btn; b.classList.toggle('is-active',on); b.setAttribute('aria-pressed',on?'true':'false');
      });
      sortGrid(grid,mode);
    });
    if(def && def!=='custom') sortGrid(grid,def);
  }
  function boot(){ document.querySelectorAll('.st-wrap').forEach(initWrap); }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',boot);
  else boot();
})();
</script>
JS;
    return $css . "\n" . $js . "\n";
  }
}

/** Wrap a single container element with the toolbar + engine assets. */
if (!function_exists('st_wrap')) {
  function st_wrap(string $containerHtml, string $slug, string $default = 'newest'): string {
    $h = htmlspecialchars($slug, ENT_QUOTES, 'UTF-8');
    return st_assets()
      . '<div class="st-wrap" data-st-for="' . $h . '">'
      . st_toolbar($slug, $default)
      . $containerHtml
      . '</div>';
  }
}
