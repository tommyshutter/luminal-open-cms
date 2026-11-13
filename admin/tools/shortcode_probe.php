<?php
/**
 * @appname   Luminal CMS
 * @file      /admin/probes/shortcode_probe.php
 * @version   2025.09.22.1912.40-EST
 * @author    ChatGPT
 * @license   Business Source License
 * @description
 *   READ-ONLY HTML probe to verify shortcode → renderer → JSON pipeline.
 *   IMPROVED: robust SITE_ROOT autodetect from /admin/probes, path existence checks.
 */

declare(strict_types=1);

/* Robust SITE_ROOT detection */
$here = realpath(__DIR__) ?: __DIR__;
$candidateA = realpath($here . '/../../');
$candidateB = realpath($here . '/..');
$scoreA = (int) (is_dir($candidateA . '/admin')) + (int) (is_dir($candidateA . '/includes'));
$scoreB = (int) (is_dir($candidateB . '/admin')) + (int) (is_dir($candidateB . '/includes'));
$SITE_ROOT = ($scoreA >= $scoreB) ? $candidateA : $candidateB;
if (!defined('SITE_ROOT')) define('SITE_ROOT', $SITE_ROOT ?: $here);

$DEBUG = true;

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function tree_like(string $root, int $maxDepth = 3): string {
    $root = rtrim($root,'/');
    if (!is_dir($root)) return "<pre>∅ (not a directory)</pre>";
    $out=[]; $out[] = basename($root).'/';
    $it=function($dir,$prefix,$depth) use (&$it,&$out,$maxDepth){
        if ($depth>$maxDepth) return;
        $entries=@scandir($dir); if($entries===false) return;
        $entries=array_values(array_filter($entries,fn($e)=>$e!=='.'&&$e!=='..'));
        $n=count($entries);
        foreach($entries as $i=>$name){
            $path=$dir.'/'.$name; $last=($i===$n-1); $con=$last?'└── ':'├── ';
            $out[]=$prefix.$con.$name.(is_dir($path)?'/':'');
            if(is_dir($path)) $it($path,$prefix.($last?'    ':'│   '),$depth+1);
        }
    };
    $it($root,'',1); return "<pre>".h(implode("\n",$out))."</pre>";
}

$ADMIN_HEADER = SITE_ROOT . '/admin/admin_header.php';
$has_admin_header = is_file($ADMIN_HEADER);

@include_once SITE_ROOT . '/includes/shortcodes.php';
@include_once SITE_ROOT . '/admin/modules/GalleryManager/includes/renderers/image_gallery.renderer.php';
@include_once SITE_ROOT . '/admin/modules/GalleryManager/includes/renderers/video_gallery.renderer.php';
@include_once SITE_ROOT . '/admin/modules/GalleryManager/includes/renderers/pdf_gallery.renderer.php';

$has_img = function_exists('render_image_gallery');
$has_vid = function_exists('render_video_gallery');
$has_pdf = function_exists('render_pdf_gallery');

$type = isset($_GET['type']) ? strtolower(trim((string)$_GET['type'])) : 'images';
$slug = isset($_GET['gal']) ? preg_replace('/[^A-Za-z0-9._-]/','', (string)$_GET['gal']) : '';
$mode = isset($_GET['mode']) ? strtolower(trim((string)$_GET['mode'])) : 'render';
$allowedTypes = ['images','videos','pdfs']; if (!in_array($type,$allowedTypes,true)) $type='images';

$DATA_ROOT = SITE_ROOT . '/admin/data';
$GAL_ROOT  = $DATA_ROOT . '/galleries';
$JSON_PATH = $GAL_ROOT . '/' . $type . '/' . $slug . '.json';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Shortcode Probe</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; line-height:1.4; background:#0e0e0e; color:#eee;}
.wrap { max-width:1100px; margin:24px auto; padding:16px; }
.panel { background:#121212; border:1px solid #2a2a2a; border-radius:10px; padding:16px; margin-bottom:16px; }
h1,h2,h3 { margin:0 0 8px 0; }
label { display:block; margin:6px 0 2px; font-weight:600; }
input[type=text], select { width:100%; padding:8px; border-radius:8px; border:1px solid #333; background:#111; color:#eee; }
button { padding:10px 14px; border-radius:10px; border:1px solid #444; background:#222; color:#fff; cursor:pointer; }
.row { display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
.sb { background:#1b1b1b; border:1px solid #333; border-radius:10px; padding:12px; }
.sb-row { display:flex; gap:12px; border-bottom:1px dashed #333; padding:4px 0; }
.sb-row:last-child { border-bottom:none; }
.sb-k { width:210px; color:#aaa; }
.sb-v { flex:1; color:#fff; }
.small { color:#aaa; font-size:12px; }
pre { white-space:pre; overflow:auto; padding:8px; background:#111; border:1px solid #333; border-radius:8px; }
.badge { display:inline-block; padding:2px 8px; border-radius:999px; background:#333; color:#ddd; font-size:12px; margin-left:8px; }
.code { font-family: ui-monospace, Menlo, Consolas, "Liberation Mono", monospace; }
.notice { padding:10px; border-radius:8px; background:#1d2a1d; border:1px solid #2b5a2b; color:#cfe9cf; }
.warn { padding:10px; border-radius:8px; background:#2a1d1d; border:1px solid #5a2b2b; color:#e9cfcf; }
</style>
</head>
<body>
<?php if ($has_admin_header) { @include $ADMIN_HEADER; } ?>
<div class="wrap">
  <h1>Shortcode Probe <span class="badge">read-only</span></h1>

  <div class="panel">
    <form method="get" action="">
      <div class="row">
        <div>
          <label for="type">Gallery Type</label>
          <select id="type" name="type">
            <?php foreach ($allowedTypes as $t): ?>
              <option value="<?=h($t)?>" <?= $type===$t?'selected':''; ?>><?=h($t)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="gal">Slug (filename without .json)</label>
          <input id="gal" type="text" name="gal" value="<?=h($slug)?>" placeholder="e.g. gallery-2025-09-18-0257">
        </div>
      </div>
      <div class="row" style="margin-top:12px;">
        <div>
          <label for="mode">Mode</label>
          <select id="mode" name="mode">
            <option value="render" <?= $mode==='render'?'selected':''; ?>>Render sample (existing renderer)</option>
            <option value="raw" <?= $mode==='raw'?'selected':''; ?>>Show raw JSON + counts</option>
            <option value="scan" <?= $mode==='scan'?'selected':''; ?>>Tree scan folders</option>
          </select>
        </div>
        <div style="align-self:end;">
          <button type="submit">Run Probe</button>
        </div>
      </div>
    </form>
  </div>

  <div class="row">
    <div class="sb">
      <h3>Sanity Bar</h3>
      <?php
        $paths = [
          'SITE_ROOT' => SITE_ROOT,
          '/admin' => SITE_ROOT.'/admin',
          '/includes' => SITE_ROOT.'/includes',
          '/admin/data' => SITE_ROOT.'/admin/data',
          '/admin/data/galleries' => SITE_ROOT.'/admin/data/galleries',
        ];
        foreach ($paths as $k=>$v) {
          $state = is_dir($v) ? 'dir' : (is_file($v) ? 'file' : 'missing');
          echo "<div class='sb-row'><span class='sb-k'>".h($k)."</span><span class='sb-v'>".h($v)." — [$state]</span></div>";
        }
        echo "<div class='sb-row'><span class='sb-k'>Renderer(image)</span><span class='sb-v'>".($has_img?'OK':'missing')."</span></div>";
        echo "<div class='sb-row'><span class='sb-k'>Renderer(video)</span><span class='sb-v'>".($has_vid?'OK':'missing')."</span></div>";
        echo "<div class='sb-row'><span class='sb-k'>Renderer(pdf)</span><span class='sb-v'>".($has_pdf?'OK':'missing')."</span></div>";
        echo "<div class='sb-row'><span class='sb-k'>Probe mode</span><span class='sb-v'>".strtoupper(h($mode))."</span></div>";
        echo "<div class='sb-row'><span class='sb-k'>Type / Slug</span><span class='sb-v'>".h($type)." / ".($slug ?: '—')."</span></div>";
      ?>
      <div class="small">All paths respect SITE_ROOT. No writes performed.</div>
    </div>

    <div class="panel">
      <h3>What this probe checks</h3>
      <ul>
        <li>Exact JSON path and existence</li>
        <li>JSON decode status and items count</li>
        <li>Sample render via your existing renderer</li>
        <li>“Tree-like” view of galleries to find slugs</li>
      </ul>
    </div>
  </div>

  <?php
  $GAL_ROOT  = SITE_ROOT . '/admin/data/galleries';
  if ($mode === 'scan') {
      echo '<div class="panel">';
      echo '<h2>Tree — /admin/data/galleries</h2>';
      echo tree_like($GAL_ROOT, 3);
      echo '</div>';
  } else {
      if ($slug === '') {
          echo '<div class="panel warn">Provide a slug (filename without .json), or switch to <em>scan</em> mode.</div>';
      } else {
          $JSON_PATH = $GAL_ROOT . '/' . $type . '/' . $slug . '.json';
          $exists = is_file($JSON_PATH);
          $readOk = $exists && is_readable($JSON_PATH);
          $json   = $readOk ? @file_get_contents($JSON_PATH) : '';
          $assoc  = $readOk ? json_decode($json ?? '', true) : [];
          $jOk    = is_array($assoc);
          $items  = $jOk && isset($assoc['items']) && is_array($assoc['items']) ? $assoc['items'] : [];

          echo '<div class="panel">';
          echo '<h2>Shortcode Path</h2><div class="code"><div>'.h("/admin/data/galleries/{$type}/{$slug}.json").'</div></div>';

          echo '<div class="row" style="margin-top:12px;">';
          echo '<div class="notice"><strong>File Exists:</strong> '.($exists?'YES':'NO').'<br>';
          echo '<strong>Readable:</strong> '.($readOk?'YES':'NO').'<br>';
          echo '<strong>Filesize:</strong> '.($exists?strlen((string)$json).' bytes':'—').'</div>';

          echo '<div class="notice"><strong>JSON Decode:</strong> '.($jOk?'OK':'FAIL').'<br>';
          echo '<strong>items Count:</strong> '.(is_array($items)?count($items):0).'</div>';
          echo '</div>';

          if ($mode === 'raw') {
              $snippet = $json ? substr($json, 0, 4000) : '';
              if ($json && strlen($json) > 4000) $snippet .= "\n…(truncated)…";
              echo '<h3>Raw JSON (truncated)</h3><pre>'.h($snippet).'</pre>';
          } else {
              echo '<h3>Sample Render (existing renderer)</h3>';
              $rendered = '';
              if ($type==='images' && $has_img) $rendered = @render_image_gallery($slug);
              elseif ($type==='videos' && $has_vid) $rendered = @render_video_gallery($slug);
              elseif ($type==='pdfs' && $has_pdf) $rendered = @render_pdf_gallery($slug);
              else $rendered = "<!-- No renderer available for type {$type} -->";
              $rend_snip = (string)$rendered; $max=6000;
              if (strlen($rend_snip)>$max) $rend_snip=substr($rend_snip,0,$max)."\n…(truncated)…";
              echo '<div class="panel" style="background:#101010;border-color:#2a2a2a">'.$rend_snip ?: '<em>∅ (empty render)</em>'.'</div>';
          }

          if ($DEBUG) {
              echo '<h3>DEBUG</h3><pre>'.h(var_export([
                  'type'=>$type,'slug'=>$slug,'JSON_PATH'=>$JSON_PATH,
                  'exists'=>$exists,'readable'=>$readOk,
                  'json_ok'=>$jOk,'items_cnt'=>is_array($items)?count($items):0,
              ], true)).'</pre>';
          }

          echo '</div>';
      }
  }
  ?>
</div>
</body>
</html>
