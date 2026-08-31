<?php
/**
 * EventsManagerPro — Card-grid renderer (with share buttons + detail modal)
 *
 * Shortcode: [[emp-events]] | [[events-cards]] | [[upcoming-events]]
 * Attrs:  limit (default 50), view (grid|list, default grid),
 *         past (true|false, default false),
 *         description (true|false, default false),
 *         modal (true|false, default true),
 *         share (true|false, default true)
 *
 * Reads admin/data/events_master.json (published only) + venues_master.json.
 * Cards open a detail modal on click; small social-share buttons live on each
 * card and inside the modal frame.
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../../../..') ?: dirname(__DIR__, 5));
}

if (!function_exists('render_emp_events')) {

function render_emp_events(array $attrs = []): string
{
    $limit       = max(1, (int)($attrs['limit'] ?? 50));
    $viewMode    = ($attrs['view'] ?? 'grid') === 'list' ? 'list' : 'grid';
    $showPast    = ($attrs['past'] ?? 'false') === 'true';
    $showDesc    = ($attrs['description'] ?? 'false') === 'true';
    $useModal    = ($attrs['modal'] ?? 'true')  !== 'false';
    $useShare    = ($attrs['share'] ?? 'true')  !== 'false';

    $eventsFile = SITE_ROOT . '/admin/data/events_master.json';
    $venuesFile = SITE_ROOT . '/admin/data/venues_master.json';

    if (!is_file($eventsFile)) {
        return '<div class="emp-events-empty">No upcoming events.</div>';
    }

    $events = json_decode((string)file_get_contents($eventsFile), true);
    if (!is_array($events)) {
        return '<div class="emp-events-empty">No upcoming events.</div>';
    }

    $venues = [];
    if (is_file($venuesFile)) {
        $venueData = json_decode((string)file_get_contents($venuesFile), true);
        if (is_array($venueData)) {
            foreach ($venueData as $v) {
                if (!empty($v['id'])) $venues[$v['id']] = $v;
            }
        }
    }

    $today = date('Y-m-d');
    $filtered = [];
    foreach ($events as $evt) {
        if (($evt['status'] ?? '') !== 'published') continue;
        $startDate = $evt['start_date'] ?? '';
        if ($startDate === '') continue;
        if (!$showPast && $startDate < $today) continue;
        if ($showPast && $startDate >= $today) continue;
        $filtered[] = $evt;
    }

    usort($filtered, function ($a, $b) use ($showPast) {
        $ta = strtotime(($a['start_date'] ?? '') . ' ' . ($a['start_time'] ?? ''));
        $tb = strtotime(($b['start_date'] ?? '') . ' ' . ($b['start_time'] ?? ''));
        return $showPast ? ($tb - $ta) : ($ta - $tb);
    });

    $filtered = array_slice($filtered, 0, $limit);

    if (empty($filtered)) {
        $msg = $showPast ? 'No past events to display.' : 'No upcoming events.';
        return '<div class="emp-events-empty">' . htmlspecialchars($msg) . '</div>';
    }

    static $assetsInjected = false;
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    $html = '';
    if (!$assetsInjected) {
        $assetsInjected = true;
        $html .= '<style>' . _emp_events_css() . '</style>';
    }

    $html .= '<div class="emp-events">';
    $html .= '<div class="emp-events-grid emp-events-' . $viewMode . '">';

    foreach ($filtered as $evt) {
        $id    = (string)($evt['id'] ?? '');
        $title = $evt['title'] ?? $evt['event_name'] ?? 'Untitled';
        $img   = $evt['image'] ?? '';
        $imgSrc = $img ? '/' . ltrim($img, '/') : '';

        $dateStr = '';
        $ts = strtotime(($evt['start_date'] ?? '') . ' ' . ($evt['start_time'] ?? ''));
        if ($ts) {
            $dateStr = !empty($evt['start_time'])
                ? date('F j, Y \a\t g:i A', $ts)
                : date('F j, Y', $ts);
        }

        $venueName = '';
        $venueAddr = '';
        $venueId = $evt['venue_id'] ?? '';
        if ($venueId && isset($venues[$venueId])) {
            $venueName = $venues[$venueId]['name'] ?? '';
            $venueAddr = $venues[$venueId]['address'] ?? '';
        }

        $ctaUrl  = (string)($evt['cta_url']  ?? '');
        $fbLink  = (string)($evt['fb_link']  ?? '');
        $mapLink = (string)($evt['map_link'] ?? '');
        $ytLink  = (string)($evt['yt_link']  ?? '');
        $descHtml = (string)($evt['content_html'] ?? '');

        // Fall back to Google Maps search if map_link is missing or not a URL
        if ($mapLink === '' || !preg_match('~^https?://~i', $mapLink)) {
            $mapQuery = trim($venueName . ' ' . $venueAddr);
            if ($mapQuery !== '') {
                $mapLink = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($mapQuery);
            } else {
                $mapLink = '';
            }
        }

        // Direct link used when modal disabled (legacy behavior)
        $directLink = $ctaUrl !== '' ? $ctaUrl : $fbLink;

        // Event payload for the modal — JSON in data attr
        $payload = [
            'id'        => $id,
            'title'     => $title,
            'image'     => $imgSrc,
            'date'      => $dateStr,
            'venue'     => $venueName,
            'address'   => $venueAddr,
            'desc_html' => $descHtml,
            'cta_url'   => $ctaUrl,
            'fb_link'   => $fbLink,
            'map_link'  => $mapLink,
            'yt_link'   => $ytLink,
        ];
        $payloadJson = htmlspecialchars(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

        // Card wrapper: modal mode → div (JS-driven); non-modal → anchor to direct link
        if ($useModal) {
            $html .= '<div class="emp-card emp-card-clickable" data-event="' . $payloadJson . '" tabindex="0" role="button" aria-label="' . $h($title) . '">';
        } else {
            $html .= $directLink
                ? '<a href="' . $h($directLink) . '" target="_blank" rel="noopener" class="emp-card-link"><div class="emp-card">'
                : '<div class="emp-card-link"><div class="emp-card">';
        }

        if ($imgSrc) {
            $html .= '<div class="emp-cover"><img src="' . $h($imgSrc) . '" alt="' . $h($title) . '" loading="lazy"></div>';
        }
        $html .= '<div class="emp-info">';
        $html .= '<div class="emp-title">' . $h($title) . '</div>';
        if ($dateStr)   $html .= '<div class="emp-date">' . $h($dateStr) . '</div>';
        if ($venueName) $html .= '<div class="emp-place">' . $h($venueName) . '</div>';
        if ($showDesc && $descHtml !== '') {
            $descTxt = trim(strip_tags($descHtml));
            if ($descTxt !== '') {
                $html .= '<div class="emp-desc">' . $h(mb_substr($descTxt, 0, 160)) . (mb_strlen($descTxt) > 160 ? '…' : '') . '</div>';
            }
        }
        if ($useShare) {
            $html .= _emp_share_buttons('card', $title, $fbLink);
        }
        $html .= '</div>'; // .emp-info

        if ($useModal) {
            $html .= '</div>'; // .emp-card
        } else {
            $html .= '</div></' . ($directLink ? 'a' : 'div') . '>';
        }
    }

    $html .= '</div>'; // .emp-events-grid

    if ($useModal) {
        $html .= _emp_modal_template($useShare);
    }
    $html .= '</div>'; // .emp-events

    if ($useModal) {
        $html .= '<script>' . _emp_events_js() . '</script>';
    }

    return $html;
}

function _emp_share_buttons(string $scope, string $title, string $fbLink): string
{
    // The modal version reads URLs from the modal's data context at click-time;
    // the card version embeds nothing — JS reads parent card's data-event and
    // composes share URLs on the fly.
    $b = function ($net, $svg) use ($scope) {
        return '<button type="button" class="emp-sb emp-sb-' . $net . '" data-share="' . $net . '" data-scope="' . $scope . '" aria-label="Share on ' . ucfirst($net) . '">' . $svg . '</button>';
    };
    $facebook  = '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M22 12c0-5.523-4.477-10-10-10S2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.879v-6.987h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.892h-2.33v6.987C18.343 21.128 22 16.991 22 12z"/></svg>';
    $x         = '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24h-6.66l-5.214-6.817-5.97 6.817H1.677l7.73-8.835L1.254 2.25h6.83l4.713 6.231zm-1.161 17.52h1.834L7.084 4.126H5.117z"/></svg>';
    $instagram = '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M12 2.2c3.2 0 3.584.012 4.85.07 1.366.062 2.633.336 3.608 1.311.975.975 1.249 2.242 1.311 3.608.058 1.266.07 1.65.07 4.85s-.012 3.584-.07 4.85c-.062 1.366-.336 2.633-1.311 3.608-.975.975-2.242 1.249-3.608 1.311-1.266.058-1.65.07-4.85.07s-3.584-.012-4.85-.07c-1.366-.062-2.633-.336-3.608-1.311-.975-.975-1.249-2.242-1.311-3.608C2.212 15.584 2.2 15.2 2.2 12s.012-3.584.07-4.85c.062-1.366.336-2.633 1.311-3.608.975-.975 2.242-1.249 3.608-1.311C8.416 2.212 8.8 2.2 12 2.2zm0 1.802c-3.146 0-3.504.012-4.74.069-.951.043-1.469.207-1.813.345-.456.177-.781.388-1.123.73-.342.342-.553.667-.73 1.123-.138.344-.302.862-.345 1.813-.057 1.236-.069 1.594-.069 4.74s.012 3.504.069 4.74c.043.951.207 1.469.345 1.813.177.456.388.781.73 1.123.342.342.667.553 1.123.73.344.138.862.302 1.813.345 1.236.057 1.594.069 4.74.069s3.504-.012 4.74-.069c.951-.043 1.469-.207 1.813-.345.456-.177.781-.388 1.123-.73.342-.342.553-.667.73-1.123.138-.344.302-.862.345-1.813.057-1.236.069-1.594.069-4.74s-.012-3.504-.069-4.74c-.043-.951-.207-1.469-.345-1.813-.177-.456-.388-.781-.73-1.123-.342-.342-.667-.553-1.123-.73-.344-.138-.862-.302-1.813-.345C15.504 4.014 15.146 4.002 12 4.002zm0 3.063A4.935 4.935 0 1 1 12 16.935 4.935 4.935 0 0 1 12 7.065zm0 8.135a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4zm5.146-8.336a1.152 1.152 0 1 1 0-2.305 1.152 1.152 0 0 1 0 2.305z"/></svg>';
    $email     = '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>';
    $copy      = '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>';

    return '<div class="emp-share-row emp-share-' . $scope . '" data-fb-link="' . htmlspecialchars($fbLink, ENT_QUOTES) . '">'
        . $b('facebook', $facebook)
        . $b('x', $x)
        . $b('instagram', $instagram)
        . $b('email', $email)
        . $b('copy', $copy)
        . '</div>';
}

function _emp_modal_template(bool $useShare): string
{
    $shareHtml = $useShare ? _emp_share_buttons('modal', '', '') : '';
    return <<<HTML
<div class="emp-modal" id="emp-modal" aria-hidden="true">
  <div class="emp-modal-backdrop" data-close="1"></div>
  <div class="emp-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="emp-modal-title">
    <button type="button" class="emp-modal-close" data-close="1" aria-label="Close">×</button>
    <div class="emp-modal-cover"><img alt="" loading="lazy"></div>
    <div class="emp-modal-body">
      <h3 id="emp-modal-title" class="emp-modal-title"></h3>
      <div class="emp-modal-date"></div>
      <div class="emp-modal-place"></div>
      <div class="emp-modal-desc"></div>
      <div class="emp-modal-actions"></div>
      $shareHtml
      <div class="emp-toast" role="status" aria-live="polite"></div>
    </div>
  </div>
</div>
HTML;
}

function _emp_events_css(): string
{
    return <<<'CSS'
.emp-events { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
.emp-events-empty { color:#888; text-align:center; padding:20px; }
.emp-events-grid { display:grid; gap:16px; }
.emp-events-grid.emp-events-grid { grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); }
.emp-events-grid.emp-events-list { grid-template-columns: 1fr; }
.emp-card-link { text-decoration:none; color:inherit; display:block; }
.emp-card {
    background:rgba(30,30,30,0.9); border-radius:10px; overflow:hidden;
    box-shadow:0 4px 12px rgba(0,0,0,0.4);
    transition:transform 0.2s, box-shadow 0.2s;
    position:relative;
}
.emp-card:hover { transform:translateY(-3px); box-shadow:0 8px 20px rgba(0,0,0,0.5); }
.emp-card-clickable { cursor:pointer; }
.emp-card-clickable:focus-visible { outline:2px solid #4a9eff; outline-offset:2px; }
.emp-cover img { width:100%; height:180px; object-fit:cover; display:block; }
.emp-info { padding:14px; }
.emp-title { font-size:1.05rem; font-weight:700; color:#f0f0f0; margin-bottom:6px; }
.emp-date  { font-size:0.85rem; color:#4a9eff; font-weight:500; margin-bottom:4px; }
.emp-place { font-size:0.82rem; color:#888; margin-bottom:6px; }
.emp-desc  { font-size:0.82rem; color:#aaa; line-height:1.5; }

/* Share buttons */
.emp-share-row { display:flex; gap:6px; margin-top:10px; flex-wrap:wrap; }
.emp-sb {
    width:28px; height:28px; border:0; border-radius:6px;
    display:inline-flex; align-items:center; justify-content:center;
    cursor:pointer; padding:0; color:#fff;
    transition:transform .15s ease, filter .15s ease;
}
.emp-sb:hover { transform:translateY(-1px); filter:brightness(1.1); }
.emp-sb-facebook  { background:#1877F2; }
.emp-sb-x         { background:#000; }
.emp-sb-instagram { background:linear-gradient(45deg,#f09433,#e6683c,#dc2743,#cc2366,#bf1f7a); }
.emp-sb-email     { background:#4b5563; }
.emp-sb-copy      { background:#374151; }

/* Modal */
.emp-modal {
    position:fixed; inset:0; z-index:9999;
    display:none; align-items:center; justify-content:center;
    padding:16px;
}
.emp-modal.is-open { display:flex; }
.emp-modal-backdrop {
    position:absolute; inset:0; background:rgba(0,0,0,.78);
    backdrop-filter:blur(2px);
}
.emp-modal-dialog {
    position:relative; max-width:680px; width:100%;
    max-height:90vh; overflow:auto;
    background:#111; color:#f0f0f0; border-radius:12px;
    box-shadow:0 24px 60px rgba(0,0,0,.6);
}
.emp-modal-close {
    position:absolute; top:8px; right:10px; z-index:2;
    background:rgba(0,0,0,.5); color:#fff; border:0;
    width:34px; height:34px; border-radius:50%;
    font-size:22px; line-height:1; cursor:pointer;
}
.emp-modal-cover img { width:100%; max-height:360px; object-fit:cover; display:block; }
.emp-modal-cover:empty, .emp-modal-cover img:not([src]) { display:none; }
.emp-modal-body { padding:18px 22px 22px; }
.emp-modal-title { margin:0 0 8px; font-size:1.4rem; font-weight:700; color:#fff; }
.emp-modal-date  { color:#4a9eff; font-weight:500; margin-bottom:4px; }
.emp-modal-place { color:#aaa; margin-bottom:14px; }
.emp-modal-desc  { color:#ddd; line-height:1.55; margin-bottom:16px; font-size:.95rem; }
.emp-modal-desc img, .emp-modal-desc iframe { max-width:100%; }
.emp-modal-actions { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
.emp-modal-actions a {
    text-decoration:none; color:#fff; padding:9px 14px; border-radius:6px;
    font-weight:600; font-size:.9rem; display:inline-block;
}
.emp-modal-actions .emp-btn-cta { background:#4a9eff; }
.emp-modal-actions .emp-btn-fb  { background:#1877F2; }
.emp-modal-actions .emp-btn-map { background:#16a34a; }
.emp-modal-actions .emp-btn-yt  { background:#dc2626; }
.emp-toast {
    margin-top:10px; min-height:20px; font-size:.85rem; color:#7ee389;
    opacity:0; transition:opacity .2s;
}
.emp-toast.is-show { opacity:1; }
@media (max-width:768px) {
    .emp-events-grid.emp-events-grid { grid-template-columns:1fr; }
    .emp-modal-cover img { max-height:240px; }
    .emp-modal-title { font-size:1.2rem; }
}
CSS;
}

function _emp_events_js(): string
{
    // Single global init — idempotent. JS runs once even if shortcode appears twice.
    return <<<'JS'
(function(){
  if (window.__empEventsInit) return;
  window.__empEventsInit = true;

  function shareUrlFor(net, title, url, imageUrl, dateStr) {
    var t = encodeURIComponent(title || '');
    var u = encodeURIComponent(url || '');
    if (net === 'facebook')  return 'https://www.facebook.com/sharer/sharer.php?u=' + u;
    if (net === 'x')         return 'https://twitter.com/intent/tweet?text=' + t + '&url=' + u;
    if (net === 'email') {
      var body = (title || '') + '\n';
      if (dateStr) body += dateStr + '\n';
      body += '\n' + (url || '');
      if (imageUrl) body += '\n\n' + imageUrl;
      return 'mailto:?subject=' + t + '&body=' + encodeURIComponent(body);
    }
    return '';
  }

  function eventShareUrl(evtId) {
    if (!evtId) return window.location.href;
    return window.location.origin + '/event.php?id=' + encodeURIComponent(evtId);
  }

  function absoluteUrl(path) {
    if (!path) return '';
    if (/^https?:/i.test(path)) return path;
    return window.location.origin + (path.charAt(0) === '/' ? '' : '/') + path;
  }

  function showToast(modal, msg) {
    var t = modal && modal.querySelector ? modal.querySelector('.emp-toast') : null;
    if (!t) {
      // card-context toast — just briefly tint the button (no toast outside modal)
      return;
    }
    t.textContent = msg;
    t.classList.add('is-show');
    setTimeout(function(){ t.classList.remove('is-show'); }, 2200);
  }

  function copyToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.position='fixed'; ta.style.opacity='0';
    document.body.appendChild(ta); ta.focus(); ta.select();
    try { document.execCommand('copy'); } catch(e) {}
    document.body.removeChild(ta);
    return Promise.resolve();
  }

  function handleShare(btn) {
    var scope = btn.getAttribute('data-scope');
    var net   = btn.getAttribute('data-share');
    var evt = {};

    if (scope === 'card') {
      var card = btn.closest('.emp-card');
      if (!card) return;
      try { evt = JSON.parse(card.getAttribute('data-event') || '{}'); } catch(e){}
    } else {
      var modal = document.getElementById('emp-modal');
      if (modal && modal.__currentEvent) evt = modal.__currentEvent;
    }

    var title    = evt.title || '';
    var dateStr  = evt.date || '';
    var imageAbs = absoluteUrl(evt.image || '');
    var url      = eventShareUrl(evt.id); // hits /event.php?id=… with per-event og:image

    if (net === 'copy' || net === 'instagram') {
      copyToClipboard(url).then(function(){
        var modal = document.getElementById('emp-modal');
        var msg = net === 'instagram'
          ? 'Link copied — paste it into your Instagram story or bio.'
          : 'Link copied to clipboard.';
        showToast(modal, msg);
      });
      return;
    }

    var u = shareUrlFor(net, title, url, imageAbs, dateStr);
    if (u) {
      if (net === 'email') window.location.href = u;
      else window.open(u, '_blank', 'noopener,width=600,height=600');
    }
  }

  function openModal(card) {
    var evt = {};
    try { evt = JSON.parse(card.getAttribute('data-event') || '{}'); } catch(e){}
    var modal = document.getElementById('emp-modal');
    if (!modal) return;

    var coverImg = modal.querySelector('.emp-modal-cover img');
    if (evt.image) {
      coverImg.src = evt.image;
      coverImg.alt = evt.title || '';
      coverImg.style.display = '';
    } else {
      coverImg.removeAttribute('src');
      coverImg.style.display = 'none';
    }

    modal.querySelector('.emp-modal-title').textContent = evt.title || '';
    modal.querySelector('.emp-modal-date').textContent  = evt.date  || '';
    var placeEl = modal.querySelector('.emp-modal-place');
    placeEl.textContent = '';
    if (evt.venue)   placeEl.appendChild(document.createTextNode(evt.venue));
    if (evt.venue && evt.address) placeEl.appendChild(document.createTextNode(' — '));
    if (evt.address) placeEl.appendChild(document.createTextNode(evt.address));

    // Description — content_html is admin-authored. Inject as HTML.
    modal.querySelector('.emp-modal-desc').innerHTML = evt.desc_html || '';

    // Action buttons
    var actions = modal.querySelector('.emp-modal-actions');
    actions.innerHTML = '';
    function addAction(href, cls, label){
      if (!href) return;
      var a = document.createElement('a');
      a.href = href; a.target = '_blank'; a.rel = 'noopener';
      a.className = cls; a.textContent = label;
      actions.appendChild(a);
    }
    addAction(evt.cta_url,  'emp-btn-cta', 'Get Tickets / RSVP');
    addAction(evt.fb_link,  'emp-btn-fb',  'View on Facebook');
    addAction(evt.map_link, 'emp-btn-map', 'Map / Directions');
    addAction(evt.yt_link,  'emp-btn-yt',  'Watch Video');

    // Stash the active event on the modal so share buttons in modal scope
    // can read it without re-parsing data attributes.
    modal.__currentEvent = evt;

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    var modal = document.getElementById('emp-modal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  document.addEventListener('click', function(e){
    var shareBtn = e.target.closest('.emp-sb');
    if (shareBtn) { e.preventDefault(); e.stopPropagation(); handleShare(shareBtn); return; }

    if (e.target.closest('[data-close="1"]')) { closeModal(); return; }

    var card = e.target.closest('.emp-card-clickable');
    if (card) { openModal(card); }
  });

  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') closeModal();
    if (e.key === 'Enter' || e.key === ' ') {
      var card = document.activeElement && document.activeElement.classList && document.activeElement.classList.contains('emp-card-clickable')
        ? document.activeElement : null;
      if (card) { e.preventDefault(); openModal(card); }
    }
  });
})();
JS;
}

} // end function_exists guard
