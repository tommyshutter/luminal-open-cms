<?php
/**
 * /article.php — Single article page (Luminal CMS)
 *
 * Renders a published article. Checks two sources:
 *   1. ArticlesManager store: admin/data/ArticlesManager/articles.json (canonical)
 *   2. Legacy per-file: admin/data/articles/{slug}.json (fallback)
 *
 * Routes: /blog/{slug} or /articles/{slug} → article.php?slug={slug}  (via .htaccess)
 *
 * Includes the ArticlesManager social share rack if available.
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__) ?: __DIR__);
}

/* Helpers */
function art_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function art_json(string $path): array {
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    $j = json_decode($raw ?? '', true);
    return (json_last_error() === JSON_ERROR_NONE && is_array($j)) ? $j : [];
}

/* Resolve slug */
$slug = isset($_GET['slug']) ? preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_GET['slug']))) : '';

/* Load article — try ArticlesManager store first, then legacy per-file */
$article = [];
if ($slug) {
    $amStoreFile = SITE_ROOT . '/admin/modules/ArticlesManager/lib/ArticleStore.php';
    if (is_file($amStoreFile)) {
        require_once $amStoreFile;
        $amStore = new \ArticlesManager\ArticleStore(SITE_ROOT);
        $article = $amStore->get($slug) ?? [];
    }
    if (empty($article)) {
        $articleFile = SITE_ROOT . '/admin/data/articles/' . $slug . '.json';
        $article = art_json($articleFile);
    }
}

/* 404 early when the article is missing, unpublished, or has no body content.
 * The empty-body case catches half-finished pipeline runs (e.g. Gemini rate-limited
 * mid-generation) so the URL doesn't render a content-less shell to visitors. */
$artIsPublished = !empty($article['is_published']) || !empty($article['published']);
$artHasBody     = trim((string)($article['body_html'] ?? '')) !== '';
$artMissing     = empty($article) || !$artIsPublished || !$artHasBody;
if ($artMissing) {
    http_response_code(404);
}

/* Load site header core (provides $siteTitle, $artistName, etc.) */
@include_once SITE_ROOT . '/header/header_core.php';

/* Site identity — dynamic, never hardcoded */
$siteHost   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$siteTitle  = isset($GLOBALS['siteTitle'])  ? (string)$GLOBALS['siteTitle']  : $siteHost;
$artistName = isset($GLOBALS['artistName']) ? (string)$GLOBALS['artistName'] : $siteTitle;

$ssFile = SITE_ROOT . '/admin/data/site-settings.json';
$ss = is_file($ssFile) ? (json_decode((string)@file_get_contents($ssFile), true) ?: []) : [];

/* Right-column injection — unified with the /?p= route (page_renderer) via the
 * content_injection resolver: Affiliate + UCS providers, site default per content-type
 * with per-item overrides. inject_* live in the PAGE JSON (not the ArticleStore), same as
 * page_renderer reads them, so both routes render identically. */
@include_once SITE_ROOT . '/includes/content_injection.inc.php';
@include_once SITE_ROOT . '/includes/shortcodes.php';
$ciHtml = '';
if (function_exists('ci_resolve') && function_exists('ci_build_html')) {
    $pjFile = SITE_ROOT . '/admin/data/pages/' . $slug . '/' . $slug . '.json';
    $pjData = is_file($pjFile) ? (json_decode((string)@file_get_contents($pjFile), true) ?: []) : [];
    $ciHtml = ci_build_html(ci_resolve($pjData, true));   // always an article on this route
    if ($ciHtml !== '' && function_exists('apply_shortcodes')) {
        $ciHtml = apply_shortcodes($ciHtml);
    }
}
$showAside = trim($ciHtml) !== '';

/* Article meta */
$artTitle   = !empty($article['title']) ? $article['title'] : 'Article Not Found';
$artDesc    = $article['meta_description'] ?? ($article['excerpt'] ?? '');
$artImage   = $article['hero_image_url'] ?? ($article['hero_image'] ?? '');
$artDate    = $article['published_date'] ?? ($article['date'] ?? '');
$fullTitle  = $artTitle . ' — ' . $siteTitle;

/* Inject OG for document_head_assets.php */
$GLOBALS['PAGE_OG_TITLE']       = $fullTitle;
$GLOBALS['PAGE_OG_DESCRIPTION'] = $artDesc;
$GLOBALS['PAGE_OG_IMAGE']       = $artImage ?: null;

/* Partials */
$HEAD_ASSETS = SITE_ROOT . '/header/document_head_assets.php';
$BODY_HEADER = SITE_ROOT . '/header/document_header_body.php';
$FOOTER_FILE = SITE_ROOT . '/footer.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?php echo art_h($fullTitle); ?></title>
  <?php if (is_file($HEAD_ASSETS)) include $HEAD_ASSETS; ?>
  <?php if ($artDesc): ?>
  <meta name="description" content="<?php echo art_h($artDesc); ?>">
  <?php endif; ?>
  <?php if (!empty($article['slug'])): ?>
  <link rel="canonical" href="https://<?php echo art_h($siteHost); ?>/blog/<?php echo art_h($article['slug']); ?>">
  <?php endif; ?>
</head>
<body class="ri-site">

<?php if (is_file($BODY_HEADER)) include $BODY_HEADER; ?>

<main id="content" class="content-wrapper">
<div class="content-inner">

<?php
if ($artMissing) {
    echo '<div style="max-width:680px;margin:60px auto;padding:0 20px;text-align:center;color:#888">';
    echo '<h2 style="color:#ccc">Article not found</h2>';
    echo '<p><a href="/" style="color:#888;text-decoration:none">← Home</a></p>';
    echo '</div>';
} else {
    $cat      = $article['category'] ?? '';
    $catLabel = ucwords(str_replace(['-', '_'], ' ', $cat));
    $hero     = $artImage;
    $dateFmt  = $artDate ? date('F j, Y', strtotime($artDate)) : '';
    $readTime = (int)($article['read_time'] ?? 4);
    $bodyHtml = $article['body_html'] ?? '';
    // Article bodies bypass page.php, so run them through the shortcode engine here too —
    // otherwise [[...]] (podcast-feed, stacks, galleries, ads) render as literal text.
    @include_once SITE_ROOT . '/includes/shortcodes.php';
    if ($bodyHtml !== '' && function_exists('apply_shortcodes')) {
        try { $bodyHtml = apply_shortcodes($bodyHtml); } catch (\Throwable $e) {}
    }
    // Right-column injection ($ciHtml + $showAside) resolved above via the content_injection
    // resolver — same providers/config as the /?p= route. No per-route affiliate logic here.
    $author   = $article['author'] ?? $artistName;
    $tags     = $article['tags'] ?? [];
?>
<style>
.blog-single { max-width: 780px; margin: 0 auto; padding: 32px 36px 60px; background: rgba(10,12,18,0.55); backdrop-filter: blur(20px) saturate(1.3); -webkit-backdrop-filter: blur(20px) saturate(1.3); border: 1px solid rgba(255,255,255,0.07); border-radius: 16px; box-shadow: 0 8px 40px rgba(0,0,0,0.35); }
@media(max-width:600px){ .blog-single { padding: 20px 16px 40px; border-radius: 10px; } }
.blog-single__back { margin-bottom: 24px; }
.blog-single__back a { color: #888; font-size: 0.85rem; text-decoration: none; }
.blog-single__back a:hover { color: #ccc; }
.blog-single__hero { border-radius: 12px; overflow: hidden; margin-bottom: 32px; max-height: 720px; display: flex; justify-content: center; align-items: center; background: rgba(0,0,0,0.35); }
.blog-single__hero-img { max-width: 100%; max-height: 720px; width: auto; height: auto; object-fit: contain; display: block; }
.blog-single__cat { display: inline-block; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: rgba(255,255,255,0.6); background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); border-radius: 4px; padding: 2px 8px; margin-bottom: 10px; }
.blog-single__title { font-size: clamp(1.6rem,4vw,2.4rem); line-height: 1.25; margin: 10px 0 16px; color: #fff; }
.blog-single__meta { display: flex; flex-wrap: wrap; gap: 16px; font-size: 0.8rem; color: #777; margin-bottom: 32px; }
.blog-single__body { font-size: 1.02rem; line-height: 1.75; color: #c8d0dc; }
.blog-single__body h2 { font-size: 1.45rem; color: #fff; margin: 40px 0 14px; }
.blog-single__body h3 { font-size: 1.15rem; color: #e0e0e0; margin: 30px 0 10px; }
.blog-single__body p { margin: 0 0 18px; }
.blog-single__body ul, .blog-single__body ol { margin: 0 0 18px 22px; }
.blog-single__body li { margin-bottom: 6px; }
.blog-single__body a { color: rgba(255,255,255,0.65); }
.blog-single__body a:hover { color: #fff; }
.blog-single__body strong { color: #e8e8e8; }
.blog-single__body img { max-width: 100%; height: auto; max-height: 70vh; object-fit: contain; border-radius: 8px; display: block; }
.blog-single__body figure { margin: 24px 0; max-width: 100%; }
.blog-single__body figcaption { font-size: 0.78rem; color: #777; margin-top: 6px; line-height: 1.4; }
.blog-single__body iframe { max-width: 100%; border-radius: 8px; }
.blog-single__body .cd-glass { overflow: hidden; }
.blog-single__body .cd-hero-fig,
.blog-single__body .cd-secondary-fig { margin: 24px 0; max-width: 100%; }
.blog-single__body .cd-hero-img,
.blog-single__body .cd-secondary-img { width: 100%; height: auto; border-radius: 10px; display: block; object-fit: cover; }
.blog-single__body .cd-trailer-wrap { max-width: 100%; overflow: hidden; margin: 24px 0; }
.blog-single__body .cd-trailer { width: 100%; aspect-ratio: 16/9; border-radius: 8px; }
.blog-single__body .cinema-article-hero-fig,
.blog-single__body .cinema-article-hero-img,
.blog-single__body .miami-article-hero-fig,
.blog-single__body .miami-article-hero-img { max-width: 100%; }
.blog-single__body .cinema-article-hero-img,
.blog-single__body .miami-article-hero-img { width: 100%; height: auto; border-radius: 10px; display: block; object-fit: cover; }
.blog-single__body .cinema-article-figcaption,
.blog-single__body .miami-article-figcaption,
.blog-single__body .cd-figcaption { font-size: 0.78rem; color: #777; margin-top: 6px; line-height: 1.4; }
.blog-single__body .miami-article-glass,
.blog-single__body .cinema-article-glass,
.blog-single__body .cd-glass { overflow: hidden; max-width: 100%; }

.blog-single__body .cinema-article-hero-fig,
.blog-single__body .miami-article-hero-fig,
.blog-single__body .cd-hero-fig:first-of-type { display: none !important; }
.blog-single__body blockquote { border-left: 3px solid rgba(255,255,255,0.15); padding-left: 16px; color: #aaa; font-style: italic; margin: 24px 0; }
.blog-single__afp { margin-top: 48px; padding-top: 32px; border-top: 1px solid rgba(255,255,255,0.08); }
.blog-single__afp-heading { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.12em; color: rgba(255,255,255,0.5); margin-bottom: 18px; }
.blog-single__tags { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 32px; }
.blog-single__tag { font-size: 0.72rem; color: #666; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 4px; padding: 3px 10px; }

.blog-layout { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 32px; max-width: var(--page-max-width, 1200px); margin: 0 auto; padding: 0 16px; align-items: start; }
.blog-layout--no-aside { grid-template-columns: minmax(0, 1fr); max-width: var(--page-max-width, 880px); }
.blog-layout .blog-single { max-width: none; margin: 0; }
.blog-aside { position: sticky; top: 20px; padding-top: 32px; align-self: start; }
.blog-single__gstack { margin: 30px 0; }
.blog-single__gstack--top { margin-top: 0; }
.blog-aside__gstack { margin-bottom: 28px; }
.blog-aside__afp-heading { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.12em; color: rgba(255,255,255,0.5); margin: 0 0 16px; }
.blog-aside .afp-grid { display: grid; grid-template-columns: 1fr; gap: 12px; }
.blog-aside .afp-card { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 10px; }
.blog-aside .ci-inject { margin-bottom: 24px; }
.blog-aside .ci-inject__heading { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.12em; color: rgba(255,255,255,0.5); margin: 0 0 16px; }
@media (max-width: 980px) {
  .blog-layout { grid-template-columns: 1fr; }
  .blog-aside { position: static; max-height: none; margin-top: 32px; padding-top: 0; border-top: 1px solid rgba(255,255,255,0.08); }
}

.blog-single__footer { margin-top: 48px; padding-top: 24px; border-top: 1px solid rgba(255,255,255,0.08); }
</style>

<div class="blog-layout<?php echo $showAside ? '' : ' blog-layout--no-aside'; ?>">
<article class="blog-single" itemscope itemtype="https://schema.org/Article">
  <div class="blog-single__back"><a href="/">← Home</a></div>

  <?php if ($hero): ?>
  <div class="blog-single__hero">
    <img src="<?php echo art_h($hero); ?>" alt="<?php echo art_h($artTitle); ?>" class="blog-single__hero-img" itemprop="image" onerror="this.parentNode&&this.parentNode.remove()">
  </div>
  <?php endif; ?>

  <header>
    <?php if ($cat): ?>
    <span class="blog-single__cat"><?php echo art_h($catLabel); ?></span>
    <?php endif; ?>
    <h1 class="blog-single__title" itemprop="headline"><?php echo art_h($artTitle); ?></h1>
    <div class="blog-single__meta">
      <?php if ($dateFmt): ?>
      <span itemprop="datePublished" content="<?php echo art_h($artDate); ?>"><?php echo $dateFmt; ?></span>
      <?php endif; ?>
      <span><?php echo $readTime; ?> min read</span>
      <?php if ($author): ?>
      <span itemprop="author">By <?php echo art_h($author); ?></span>
      <?php endif; ?>
    </div>
  </header>

  <div class="blog-single__body" itemprop="articleBody">
    <?php echo $bodyHtml; ?>
  </div>

  <?php
  /* ── ArticlesManager social share rack ──────────────────────────── */
  $amRenderer = SITE_ROOT . '/admin/modules/ArticlesManager/lib/renderers.php';
  if (is_file($amRenderer)) {
      require_once $amRenderer;
      if (function_exists('am_render_share_rack')) {
          echo am_render_share_rack($article);
      }
  }
  ?>

  <?php if (!empty($tags)): ?>
  <div class="blog-single__tags">
    <?php foreach ($tags as $tag): ?>
    <span class="blog-single__tag"><?php echo art_h($tag); ?></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="blog-single__footer">
    <a href="/" style="color:rgba(255,255,255,0.5);font-weight:700;text-decoration:none;font-size:0.85rem">← Home</a>
  </div>
</article>
<?php if ($showAside): ?>
<aside class="blog-aside">
  <?php echo $ciHtml;   // UCS + Affiliate providers, resolved by content_injection (same as /?p=) ?>
</aside>
<?php endif; ?>
</div><!-- /blog-layout -->

<script type="application/ld+json">
<?php echo json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => $article['title'] ?? '',
    'description' => $artDesc,
    'image' => $artImage ? 'https://' . $siteHost . $artImage : '',
    'datePublished' => $artDate ?: date('Y-m-d'),
    'author' => ['@type' => 'Organization', 'name' => $siteTitle],
    'publisher' => ['@type' => 'Organization', 'name' => $siteTitle, 'url' => 'https://' . $siteHost],
    'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => 'https://' . $siteHost . '/blog/' . ($article['slug'] ?? '')],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>
</script>

<?php } // end article check ?>

</div>
</main>

<?php if (is_file($FOOTER_FILE)) include $FOOTER_FILE; ?>
</body>
</html>
