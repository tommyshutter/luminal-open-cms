<?php
/**
 * @appname   Luminal Open CMS
 * @file      /includes/pdf-gallery-manager-skin.inc.php
 * @version   2025.09.25.2039.00-EST
 * @author    ChatGPT
 * @license   Business Source License
 * @description
 *   Public bridge to reuse the Admin PDF Gallery Manager’s CSS verbatim.
 *   Safe + additive. Loads admin CSS and maps public classes to manager look.
 */
declare(strict_types=1);

// 1) Reuse the exact admin CSS (code reuse = cheat code)
echo '<link rel="stylesheet" href="/admin/css/pdf-galleries.css">';
echo '<link rel="stylesheet" href="/admin/css/pdf-gallery-manager-browser.css">';
echo '<link rel="stylesheet" href="/admin/css/pdf-gallery-manager-editor.css">';
echo '<link rel="stylesheet" href="/admin/css/pdf-gallery-manager-layout.css">';

// 2) Tiny bridge: map public classes to manager tile look without touching admin files.
//    If your admin uses a different icon path, override --pdf-icon-url below.
?>
<style>
  /* Limit admin CSS effect to our gallery wrapper only */
  .gallery-pdfs.manager-look { all: initial; font-family: inherit; }
  .gallery-pdfs.manager-look, .gallery-pdfs.manager-look * { all: unset; box-sizing: border-box; }

  /* Grid similar to admin “browser” layout */
  .gallery-pdfs.manager-look{
    display:grid; gap:18px; grid-template-columns:repeat(auto-fill,minmax(210px,1fr));
    margin:16px 0;
  }
  .gallery-pdfs.manager-look.single{ grid-template-columns:1fr; }

  /* Card → use admin visual language */
  .gallery-pdfs.manager-look figure{ margin:0; padding:0; }
  .gallery-pdfs.manager-look a.pdf-item{ display:block; text-decoration:none; color:inherit; height:100%; }

  .gallery-pdfs.manager-look .pdf-card{
    background: linear-gradient(180deg,#1f2836,#1b2330);
    border:1px solid rgba(255,255,255,.10);
    border-radius:12px; padding:12px;
    height:100%; display:flex; flex-direction:column; justify-content:flex-start;
    box-shadow:0 24px 48px rgba(0,0,0,.42), 0 2px 0 rgba(255,255,255,.04) inset;
    transition:transform .16s ease, box-shadow .16s ease, border-color .16s ease;
  }
  .gallery-pdfs.manager-look .pdf-card:hover{
    transform:translateY(-2px);
    border-color:rgba(255,255,255,.18);
    box-shadow:0 34px 68px rgba(0,0,0,.48), 0 2px 0 rgba(255,255,255,.06) inset;
  }
  .gallery-pdfs.manager-look .pdf-card{ min-height:260px; }

  /* Adobe icon (same placement/size as admin tiles) */
  .gallery-pdfs.manager-look .pdf-icon{
    width:54px; height:64px; margin:6px 0 12px 6px;
    background-repeat:no-repeat; background-size:contain; background-position:left top;
    filter:drop-shadow(0 2px 3px rgba(0,0,0,.35));
    /* Prefer the admin icon. Change the path if yours differs. */
    background-image: var(--pdf-icon-url, url("/admin/css/img/pdf-card.svg"));
  }

  /* Filename on face (exact vibe) */
  .gallery-pdfs.manager-look .pt{
    margin:4px 10px 0; color:#eef4ff; font-weight:800; font-size:14px; line-height:1.25;
    text-align:center; letter-spacing:.2px; text-shadow:0 1px 1px rgba(0,0,0,.35);
    display:-webkit-box; -webkit-box-orient:vertical; -webkit-line-clamp:3; overflow:hidden;
    min-height:calc(1.25em * 2);
  }

  /* Focus ring */
  .gallery-pdfs.manager-look a.pdf-item:focus-visible .pdf-card{
    outline:2px solid #7aa2ff; outline-offset:2px;
  }

  @media (min-width:1200px){
    .gallery-pdfs.manager-look{ grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); }
    .gallery-pdfs.manager-look .pdf-card{ min-height:280px; }
  }
</style>
