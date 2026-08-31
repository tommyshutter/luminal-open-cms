/**
 * AudienceBuilder — Frontend Form Interceptor
 *
 * Auto-detects lead capture forms by CSS class or data attribute and wires
 * them to the AudienceBuilder submit endpoint. Auto-captures UTM params
 * and source URL.
 *
 * Detected forms: [data-ab-form], .ca-form, .fyh-form, .avr-form
 * Any future form can opt in with the data-ab-form attribute.
 */
(function() {
'use strict';

document.addEventListener('DOMContentLoaded', function() {
    var forms = document.querySelectorAll(
        '[data-ab-form], .ca-form, .fyh-form, .avr-form'
    );

    if (!forms.length) return;

    // Parse UTM params once
    var params = new URLSearchParams(window.location.search);
    var utmSource   = params.get('utm_source') || '';
    var utmMedium   = params.get('utm_medium') || '';
    var utmCampaign = params.get('utm_campaign') || '';
    var sourceUrl   = window.location.pathname + window.location.search;

    forms.forEach(function(form) {
        // Remove existing inline onsubmit handler
        form.onsubmit = null;
        form.removeAttribute('onsubmit');

        form.addEventListener('submit', function(e) {
            e.preventDefault();

            var btn = form.querySelector('button[type="submit"], input[type="submit"]');
            var origText = btn ? btn.innerHTML : '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sending\u2026';
            }

            // Collect all form fields
            var data = {};
            var fd = new FormData(form);
            fd.forEach(function(value, key) {
                data[key] = value;
            });
            data.action = 'submit_lead';
            data.source_url = sourceUrl;
            if (utmSource)   data.utm_source   = utmSource;
            if (utmMedium)   data.utm_medium   = utmMedium;
            if (utmCampaign) data.utm_campaign = utmCampaign;

            fetch('/panels/ab-submit.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            })
            .then(function(res) { return res.json(); })
            .then(function(j) {
                if (j.ok) {
                    // Hide the form, show success message
                    form.style.display = 'none';

                    // Try form-id specific success div first (built by ab_form.renderer.php)
                    var formId = form.getAttribute('data-form-id');
                    var successEl = formId ? document.getElementById('ab-form-' + formId + '-success') : null;

                    // Fallback: look for generic success divs near the form
                    if (!successEl && form.parentElement) {
                        successEl = form.parentElement.querySelector('[id$="-form-success"], .form-success, [class$="-form-success"]');
                    }

                    if (successEl) {
                        successEl.style.display = 'block';
                    } else {
                        // Last resort: insert a generic success message
                        var msg = document.createElement('div');
                        msg.style.cssText = 'text-align:center;padding:32px;color:#4caf50;font-size:18px;font-weight:600;';
                        msg.innerHTML = '<i class="fa-solid fa-circle-check"></i> Thank you! We\'ll be in touch soon.';
                        form.parentElement.appendChild(msg);
                    }

                    // Pixel tracking hook (future integration point)
                    if (window.ABTracking && typeof window.ABTracking.onConversion === 'function') {
                        window.ABTracking.onConversion(form, j);
                    }
                } else {
                    // Re-enable form on failure
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = origText;
                    }
                    console.warn('AB form submit failed:', j.error);
                }
            })
            .catch(function(err) {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = origText;
                }
                console.error('AB form submit error:', err);
            });
        });
    });

    // Form view tracking hook (future — IntersectionObserver integration point)
    // if (window.ABTracking && typeof window.ABTracking.onFormView === 'function') { ... }
});

/* ── Lightbox display mode (display_mode:lightbox) ──────────────────────────
   Trigger button opens the form in a modal overlay; submit stays AJAX (the
   form inside is a normal [data-ab-form], already wired above). Delegated so
   it works no matter when the markup lands. */
(function () {
    function openLb(ov) {
        if (!ov) return;
        ov.hidden = false;
        requestAnimationFrame(function () { ov.classList.add('ab-lightbox-open'); });
        document.documentElement.style.overflow = 'hidden';
    }
    function closeLb(ov) {
        if (!ov) return;
        ov.classList.remove('ab-lightbox-open');
        document.documentElement.style.overflow = '';
        setTimeout(function () { ov.hidden = true; }, 250);
    }
    document.addEventListener('click', function (e) {
        var openBtn = e.target.closest('[data-ab-lightbox-open]');
        if (openBtn) { openLb(document.getElementById(openBtn.getAttribute('data-ab-lightbox-open'))); return; }
        if (e.target.closest('[data-ab-lightbox-close]')) { closeLb(e.target.closest('[data-ab-lightbox]')); return; }
        var ov = e.target.closest('[data-ab-lightbox]');
        if (ov && e.target === ov) closeLb(ov);   // backdrop click
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var open = document.querySelector('[data-ab-lightbox].ab-lightbox-open');
            if (open) closeLb(open);
        }
    });
})();
})();
