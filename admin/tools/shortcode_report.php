<?php
/**
 * @appname   Luminal Open CMS
 * @file      /admin/probes/shortcode_report.php
 * @version   2025.09.22.1912.10-EST
 * @author    ChatGPT
 * @license   Business Source License
 * @description
 *   One-click, READ-ONLY diagnostics report for shortcode → renderer → JSON paths.
 *   IMPROVED: robust SITE_ROOT autodetect from /admin/probes, path existence checks.
 *   Outputs text/plain that captures:
 *     - Env paths + existence flags + PHP info
 *     - Presence of apply_shortcodes() & render_*_gallery() (with file exists? and include status)
 *     - Panel whitelist vs /panels/*.php diffs
 *     - Tree + schema checks for /admin/data/galleries/{images|videos|pdfs}
 *     - Recursive scan of /admin/data/pages with shortcode grep
 *   SAFE: No writes. Delete this file to remove.
 */

declare(strict_types=1);

/* ── Robust SITE_ROOT detection from /admin/probes ───────────────────────── */
$here = realpath(__DIR__) ?: __DIR__;
$candidateA = realpath($here . '/../../');     // expected site root
$candidateB = realpath($here . '/..');         // admin as root (fallback)
$pick = $candidateA ?: $candidateB ?: $here;

// Validate: prefer a root where /admin and /includes exist
$scoreA = (int) (is_dir($candidateA . '/admin')) + (int) (is_dir($candidateA . '/includes'));
$scoreB = (int) (is_dir($candidateB . '/admin')) + (int) (is_dir($candidateB . '/includes'));
$SITE_ROOT = ($scoreA >= $scoreB) ? $candidateA : $candidateB;
if (!$SITE_ROOT) { $SITE_ROOT = $here; }

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', $SITE_ROOT);
}

$NOW = new DateTime('now', new DateTimeZone('America/New_York'));
$STAMP = $NOW->format('Ymd-His');

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="shortcode_report-' . $STAMP . '.txt"');

function format_bytes(int $bytes): string {
    $u = ['B','KB','MB','GB','TB']; $i=0; $v=(float)$bytes;
    while ($v>=1024 && $i<count($u)-1) { $v/=1024; $i++; }
    return $i===0 ? $bytes.' '.$u[$i] : number_format($v,2).' '.$u[$i];
}
function json_read(string $path): array {
    if (!is_file($path)) return ['ok'=>false,'err'=>'ENOENT','data'=>null,'size'=>0,'mtime'=>null,'err_msg'=>null];
    $raw = @file_get_contents($path);
    $size = $raw===false ? 0 : strlen($raw);
    $data = json_decode($raw ?? '', true);
    $ok = is_array($data); $err_msg = $ok ? null : json_last_error_msg();
    $mtime = @filemtime($path);
    return ['ok'=>$ok,'err'=>$ok?null:'JSON','data'=>$data,'size'=>$size,'mtime'=>$mtime,'err_msg'=>$err_msg];
}
function tree_like(string $root, int $maxDepth = 4): array {
    $lines=[]; $root=rtrim($root,'/'); if (!is_dir($root)) { $lines[]="∅  (not a directory)"; return $lines; }
    $lines[] = basename($root) . '/';
    $walk=function(string $dir,string $prefix,int $depth) use (&$walk,&$lines,$maxDepth){
        if ($depth>$maxDepth) return;
        $entries=@scandir($dir); if ($entries===false) return;
        $entries=array_values(array_filter($entries,fn($e)=>$e!=='.' && $e!=='..'));
        $n=count($entries);
        foreach($entries as $i=>$name){
            $path=$dir.'/'.$name; $last=($i===$n-1); $con=$last?'└── ':'├── ';
            $lines[]=$prefix.$con.$name.(is_dir($path)?'/':'');
            if (is_dir($path)) $walk($path,$prefix.($last?'    ':'│   '),$depth+1);
        }
    };
    $walk($root,'',1); return $lines;
}
function grep_shortcodes(string $text): array {
    $hits=[]; $rx=[
        'panel'         => '/\[\[panel:([^\]]+)\]\]/i',
        'image-gallery' => '/\[\[image-gallery:([^\]]+)\]\]/i',
        'video-gallery' => '/\[\[video-gallery:([^\]]+)\]\]/i',
        'pdf-gallery'   => '/\[\[pdf-gallery:([^\]]+)\]\]/i',
    ];
    foreach($rx as $k=>$r){ if(preg_match_all($r,$text,$m)) $hits[$k]=$m[1]; }
    return $hits;
}
function list_json_files(string $dir): array {
    if (!is_dir($dir)) return []; $out=glob($dir.'/*.json') ?: []; sort($out,SORT_NATURAL|SORT_FLAG_CASE); return $out;
}

/* ── Paths (post-detect) ─────────────────────────────────────────────────── */
$ENV = [
    'SITE_ROOT'         => SITE_ROOT,
    'ADMIN_ROOT'        => SITE_ROOT . '/admin',
    'DATA_ROOT'         => SITE_ROOT . '/admin/data',
    'GALLERIES_ROOT'    => SITE_ROOT . '/admin/data/galleries',
    'PAGES_ROOT'        => SITE_ROOT . '/admin/data/pages',
    'PANELS_ROOT'       => SITE_ROOT . '/panels',
    'INCLUDES_ROOT'     => SITE_ROOT . '/includes',
    'SHORTCODES'        => SITE_ROOT . '/includes/shortcodes.php',
    'RENDERER_IMG'      => SITE_ROOT . '/admin/modules/GalleryManager/includes/renderers/image_gallery.renderer.php',
    'RENDERER_VID'      => SITE_ROOT . '/admin/modules/GalleryManager/includes/renderers/video_gallery.renderer.php',
    'RENDERER_PDF'      => SITE_ROOT . '/admin/modules/GalleryManager/includes/renderers/pdf_gallery.renderer.php',
    'PANEL_WHITELIST'   => SITE_ROOT . '/admin/data/panel-whitelist.json',
];
$EXISTS = array_map(fn($p)=> is_dir($p) || is_file($p), $ENV);

/* ── Wire includes (report existence + include result) ───────────────────── */
$INCLUDE_STATUS = [];
foreach (['SHORTCODES','RENDERER_IMG','RENDERER_VID','RENDERER_PDF'] as $k) {
    $f = $ENV[$k];
    $exists = is_file($f);
    $ok = false;
    if ($exists) { $ok = (bool) @include_once $f; }
    $INCLUDE_STATUS[$k] = ['path'=>$f,'exists'=>$exists?'YES':'NO','included'=>$ok?'YES':'NO'];
}

$WIRING = [
    'apply_shortcodes()'     => function_exists('apply_shortcodes') ? 'OK' : 'MISSING',
    'render_image_gallery()' => function_exists('render_image_gallery') ? 'OK' : 'MISSING',
    'render_video_gallery()' => function_exists('render_video_gallery') ? 'OK' : 'MISSING',
    'render_pdf_gallery()'   => function_exists('render_pdf_gallery') ? 'OK' : 'MISSING',
];

/* ── Panels whitelist diff ───────────────────────────────────────────────── */
$whitelist = json_read($ENV['PANEL_WHITELIST']);
$whitelist_list = [];
if ($whitelist['ok'] && is_array($whitelist['data'])) {
    foreach ($whitelist['data'] as $x) { if (is_string($x)) $whitelist_list[] = $x; }
}
sort($whitelist_list);
$panel_files = is_dir($ENV['PANELS_ROOT']) ? array_map('basename', glob($ENV['PANELS_ROOT'].'/*.php') ?: []) : [];
sort($panel_files);
$panels_only_on_disk = array_values(array_diff($panel_files, $whitelist_list));
$panels_only_in_whitelist = array_values(array_diff($whitelist_list, $panel_files));

/* ── Galleries scan ──────────────────────────────────────────────────────── */
$gallery_types = ['images','videos','pdfs'];
$GALLERIES = [];
foreach ($gallery_types as $t) {
    $root = $ENV['GALLERIES_ROOT'].'/'.$t;
    $files = list_json_files($root);
    $items = [];
    foreach ($files as $f) {
        $jr = json_read($f);
        $rec = [
            'file'=> $f,
            'exists'=> is_file($f) ? 'YES':'NO',
            'size'=> $jr['size'],
            'mtime'=> $jr['mtime'] ? date('Y-m-d H:i:s',$jr['mtime']) : null,
            'json_ok'=> $jr['ok'] ? 'OK' : ('ERR:'.($jr['err_msg'] ?? $jr['err'])),
            'items_n'=> 0, 'legacy_n'=> 0, 'schema'=> 'unknown', 'sample'=> null,
        ];
        if ($jr['ok'] && is_array($jr['data'])) {
            $d=$jr['data']; $legacy_key = $t==='images'?'images':($t==='videos'?'videos':'pdfs');
            if (isset($d['items']) && is_array($d['items'])) { $rec['items_n']=count($d['items']); $rec['schema']='modern(items)'; $rec['sample']=$d['items'][0]??null; }
            if (isset($d[$legacy_key]) && is_array($d[$legacy_key])) { $rec['legacy_n']=count($d[$legacy_key]); if($rec['schema']==='unknown'){ $rec['schema']='legacy('.$legacy_key.')'; $rec['sample']=$d[$legacy_key][0]??null; } }
            if ($rec['schema']==='unknown') $rec['schema']='other';
        }
        $items[]=$rec;
    }
    $GALLERIES[$t] = ['root'=>$root,'count'=>count($files),'files'=>$items,'tree'=>tree_like($root,3)];
}

/* ── Pages scan ──────────────────────────────────────────────────────────── */
$PAGES = ['root'=>$ENV['PAGES_ROOT'],'count'=>0,'files'=>[]];
$pages_list = [];
if (is_dir($ENV['PAGES_ROOT'])) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ENV['PAGES_ROOT'], FilesystemIterator::SKIP_DOTS));
    foreach ($it as $node) if ($node->isFile() && strtolower($node->getExtension())==='json') $pages_list[]=$node->getPathname();
    sort($pages_list,SORT_NATURAL|SORT_FLAG_CASE);
}
$PAGES['count']=count($pages_list);
foreach ($pages_list as $f) {
    $jr = json_read($f);
    $rec = ['file'=>$f,'exists'=> is_file($f)?'YES':'NO','size'=>$jr['size'],'mtime'=>$jr['mtime']?date('Y-m-d H:i:s',$jr['mtime']):null,'json_ok'=>$jr['ok']?'OK':('ERR:'.($jr['err_msg']??$jr['err'])),'has_main'=>'no','has_right'=>'no','shortcodes'=>[]];
    if ($jr['ok'] && is_array($jr['data'])) {
        $d=$jr['data']; $main=''; $right='';
        if (isset($d['components']['main_content']['content']) && is_string($d['components']['main_content']['content'])) { $main=$d['components']['main_content']['content']; $rec['has_main']='yes'; }
        if (isset($d['components']['right_column']['content']) && is_string($d['components']['right_column']['content'])) { $right=$d['components']['right_column']['content']; $rec['has_right']='yes'; }
        $sc=[];
        if ($main!=='') foreach (grep_shortcodes($main) as $k=>$arr) $sc[$k]=array_values(array_unique(array_map('strval',$arr)));
        if ($right!=='') {
            $scr=grep_shortcodes($right);
            foreach ($scr as $k=>$arr) $sc[$k]=isset($sc[$k])?array_values(array_unique(array_merge($sc[$k],array_map('strval',$arr)))):array_values(array_unique(array_map('strval',$arr)));
        }
        $rec['shortcodes']=$sc;
    }
    $PAGES['files'][]=$rec;
}

/* ── Output report ───────────────────────────────────────────────────────── */
echo "=== Luminal Open CMS — Shortcode Diagnostics Report ===\n";
echo "Generated: ".$NOW->format('Y-m-d H:i:s T')."\n";
echo "Version:   /admin/probes/shortcode_report.php @ 2025.09.22.1912.10-EST\n";
echo "=====================================================================\n\n";

echo "[ENVIRONMENT]\n";
echo "PHP:        ".PHP_VERSION."\n";
echo "OS:         ".PHP_OS_FAMILY."\n";
foreach ($ENV as $k=>$v) echo str_pad($k.':',18).$v.(isset($EXISTS[$k]) ? ('   ['.(is_dir($v)?'dir':(is_file($v)?'file':'missing')).']') : '')."\n";
echo "\n";

echo "[INCLUDES]\n";
foreach ($INCLUDE_STATUS as $k=>$st) {
    echo str_pad($k,16)." path: ".$st['path']."\n";
    echo "   exists: ".$st['exists']."   included: ".$st['included']."\n";
}
echo "\n";

echo "[WIRING]\n";
foreach ($WIRING as $k=>$v) echo str_pad($k,28)." : ".$v."\n";
echo "\n";

echo "[PANELS]\n";
echo "Whitelist file: ".$ENV['PANEL_WHITELIST']."  [".(is_file($ENV['PANEL_WHITELIST'])?'file':'missing')."]\n";
echo "Whitelist entries: ".count($whitelist_list)."\n";
echo "Panels on disk: ".count($panel_files)."\n";
echo "- On disk but NOT in whitelist (".count($panels_only_on_disk)."):\n"; foreach ($panels_only_on_disk as $p) echo "  • $p\n";
echo "- In whitelist but NOT on disk (".count($panels_only_in_whitelist)."):\n"; foreach ($panels_only_in_whitelist as $p) echo "  • $p\n";
echo "\n";

foreach ($gallery_types as $t) {
    $g=$GALLERIES[$t];
    echo "[GALLERIES: $t]\n";
    echo "Root:       ".$g['root']."\n";
    echo "JSON files: ".$g['count']."\n";
    echo "Tree (depth≤3):\n"; foreach ($g['tree'] as $line) echo "  $line\n";
    echo "— Files —\n";
    foreach ($g['files'] as $rec) {
        echo "  - ".basename($rec['file'])."\n";
        echo "      exists:   ".$rec['exists']."   size: ".format_bytes((int)$rec['size'])."   mtime: ".($rec['mtime'] ?? '—')."\n";
        echo "      json:     ".$rec['json_ok']."   schema: ".$rec['schema']."\n";
        echo "      items_n:  ".$rec['items_n']."   legacy_n: ".$rec['legacy_n']."\n";
        if ($rec['sample'] !== null) {
            $snip=json_encode($rec['sample'], JSON_UNESCAPED_SLASHES);
            if (strlen($snip)>200) $snip=substr($snip,0,200).'…';
            echo "      sample:   ".$snip."\n";
        }
    }
    echo "\n";
}

echo "[PAGES]\n";
echo "Root:   ".$PAGES['root']."\n";
echo "Files:  ".$PAGES['count']."\n";
foreach ($PAGES['files'] as $rec) {
    echo "  - ".str_replace($PAGES['root'].'/','',$rec['file'])."\n";
    echo "      exists:   ".$rec['exists']."   size: ".format_bytes((int)$rec['size'])."   mtime: ".($rec['mtime'] ?? '—')."\n";
    echo "      json:     ".$rec['json_ok']."   has_main: ".$rec['has_main']."   has_right: ".$rec['has_right']."\n";
    if (!empty($rec['shortcodes'])) {
        foreach ($rec['shortcodes'] as $kind=>$arr) {
            $arr = array_slice(array_values($arr), 0, 8);
            echo "      sc[$kind]: ".implode(', ',$arr).(count($arr)===8?' …':'')."\n";
        }
    } else {
        echo "      sc:       —\n";
    }
}
echo "\n";

echo "[INTERPRETATION HINTS]\n";
echo "- If any INCLUDE 'exists:NO', the file path is wrong for this deployment.\n";
echo "- If 'included:NO' but exists:YES, a fatal/require within that file may be bailing.\n";
echo "- If galleries schema shows 'legacy' but items_n=0, modern renderers show nothing.\n";
echo "- Pages with sc[...] referencing non-existent gallery slugs will render blank blocks.\n";
echo "- Whitelist mismatches will block panel rendering.\n";
echo "\n=== End of report ===\n";
?>