/**
 * Luminal CMS — Shared Mountain (area) Chart component
 * ----------------------------------------------------------------------------
 * One visual language for time-series traffic across the fleet. Used by the
 * per-site Dashboard (DashboardStatsOG) and the hub StatsCaptainOG dashboard.
 *
 * Usage:
 *   <canvas class="lum-mountain"
 *           data-series='[{"t":"Jun 1","visitors":12,"page_views":40,"hits":80,"mp3":3}, ...]'
 *           data-has-mp3="1"></canvas>
 *   window.LuminalMountain.renderAll(containerEl);   // after injecting markup
 *
 * Chart.js is lazy-loaded from the same CDN the rest of the admin uses; calling
 * renderAll() before it arrives is safe (the render is queued).
 *
 * File:    admin/shared/charts/mountain-chart.js
 * Version: 1.0.0
 */
(function () {
  'use strict';

  var CDN = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';

  // Series metric → display config (order = draw order, last on top)
  var METRICS = [
    { key: 'visitors',   label: 'Unique Visitors', color: '#5bff00', fill: true,  area: 'rgba(91,255,0,0.16)'  },
    { key: 'page_views', label: 'Page Views',      color: '#60a5fa', fill: true,  area: 'rgba(96,165,250,0.12)' },
    { key: 'mp3',        label: 'Downloads',        color: '#f5ff08', fill: true,  area: 'rgba(245,255,8,0.14)', podcastOnly: true }
  ];

  function ensureChartJs(cb) {
    if (window.Chart) { cb(); return; }
    if (window.__lumChartQueue) { window.__lumChartQueue.push(cb); return; }
    window.__lumChartQueue = [cb];
    var s = document.createElement('script');
    s.src = CDN;
    s.async = true;
    s.onload = function () {
      var q = window.__lumChartQueue || [];
      window.__lumChartQueue = null;
      q.forEach(function (f) { try { f(); } catch (e) { /* noop */ } });
    };
    s.onerror = function () { window.__lumChartQueue = null; };
    document.head.appendChild(s);
  }

  function renderOne(canvas) {
    if (!canvas || canvas.__lumRendered) return;
    var series;
    try { series = JSON.parse(canvas.getAttribute('data-series') || '[]'); }
    catch (e) { return; }
    if (!Array.isArray(series) || series.length < 2) return;

    var hasMp3 = canvas.getAttribute('data-has-mp3') === '1';
    var labels = series.map(function (p) { return p.t; });

    var datasets = METRICS.filter(function (m) {
      if (m.podcastOnly && !hasMp3) return false;
      // hide a metric that is entirely zero (keeps the legend honest)
      return series.some(function (p) { return (+p[m.key] || 0) > 0; });
    }).map(function (m) {
      return {
        label: m.label,
        data: series.map(function (p) { return +p[m.key] || 0; }),
        borderColor: m.color,
        backgroundColor: m.area,
        fill: m.fill ? 'origin' : false,
        borderWidth: 2,
        tension: 0.35,
        pointRadius: 0,
        pointHoverRadius: 4,
        pointHoverBackgroundColor: m.color,
        pointHitRadius: 12
      };
    });

    if (!datasets.length) return;

    canvas.__lumRendered = true;
    new window.Chart(canvas, {
      type: 'line',
      data: { labels: labels, datasets: datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: {
            display: true,
            position: 'top',
            align: 'end',
            labels: {
              color: '#9aa4b2', boxWidth: 10, boxHeight: 10, usePointStyle: true,
              pointStyle: 'rectRounded', font: { size: 11 }, padding: 14
            }
          },
          tooltip: {
            backgroundColor: 'rgba(10,12,20,0.95)',
            borderColor: 'rgba(255,255,255,0.1)', borderWidth: 1,
            titleColor: '#e2e8f0', bodyColor: '#cbd5e1', padding: 10,
            displayColors: true, usePointStyle: true,
            callbacks: {
              // Spike attribution: show the top page / download that drove this day
              // (present only when the series carries per-day top_page/top_dl).
              afterBody: function (items) {
                if (!items || !items.length) return '';
                var p = series[items[0].dataIndex];
                if (!p) return '';
                var out = [];
                if (p.top_page) out.push('▲ Top page: ' + p.top_page + (p.top_page_n ? ' (' + p.top_page_n + ')' : ''));
                if (p.top_dl)   out.push('▲ Top download: ' + p.top_dl + (p.top_dl_n ? ' (' + p.top_dl_n + ')' : ''));
                return out;
              }
            }
          }
        },
        scales: {
          x: {
            grid: { color: 'rgba(255,255,255,0.04)', drawTicks: false },
            ticks: { color: '#5b6472', maxRotation: 0, autoSkipPadding: 18, font: { size: 10 } },
            border: { color: 'rgba(255,255,255,0.08)' }
          },
          y: {
            beginAtZero: true,
            grid: { color: 'rgba(255,255,255,0.04)', drawTicks: false },
            ticks: { color: '#5b6472', precision: 0, maxTicksLimit: 5, font: { size: 10 } },
            border: { display: false }
          }
        }
      }
    });
  }

  function renderAll(root) {
    var scope = root || document;
    var canvases = scope.querySelectorAll ? scope.querySelectorAll('canvas.lum-mountain') : [];
    if (!canvases.length) return;
    ensureChartJs(function () {
      canvases.forEach(function (c) { renderOne(c); });
    });
  }

  window.LuminalMountain = { renderAll: renderAll, renderOne: renderOne };
})();
