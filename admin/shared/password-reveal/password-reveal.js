/**
 * Luminal CMS — Password Reveal (shared)
 *
 * Auto-attaches an 👁 show/hide toggle to every <input type="password"> in the
 * admin. Zero per-field wiring: include this once (admin_footer.php loads it
 * globally; login.php includes it directly since it has no footer). A
 * MutationObserver catches password fields added later (e.g. modal forms).
 *
 * Accessible: the toggle is a real <button> with aria-pressed + aria-label,
 * tabindex=-1 so it doesn't interrupt form tabbing.
 */
(function () {
    'use strict';
    if (window.__lumPwReveal) return;
    window.__lumPwReveal = true;

    var EYE =
        '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    var EYE_OFF =
        '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>' +
        '<line x1="1" y1="1" x2="23" y2="23"/></svg>';

    function attach(input) {
        if (!input || input.dataset.pwReveal === '1') return;
        if ((input.getAttribute('type') || '').toLowerCase() !== 'password') return;
        // Skip if the field opts out.
        if (input.hasAttribute('data-no-reveal')) return;
        input.dataset.pwReveal = '1';

        var wrap = document.createElement('span');
        wrap.className = 'lum-pw-wrap';
        var parent = input.parentNode;
        if (!parent) return;
        parent.insertBefore(wrap, input);
        wrap.appendChild(input);

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'lum-pw-toggle';
        btn.setAttribute('tabindex', '-1');
        btn.setAttribute('aria-pressed', 'false');
        btn.setAttribute('aria-label', 'Show password');
        btn.setAttribute('title', 'Show password');
        btn.innerHTML = EYE;
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var revealing = input.getAttribute('type') !== 'text';
            input.setAttribute('type', revealing ? 'text' : 'password');
            btn.innerHTML = revealing ? EYE_OFF : EYE;
            btn.classList.toggle('on', revealing);
            btn.setAttribute('aria-pressed', revealing ? 'true' : 'false');
            var label = revealing ? 'Hide password' : 'Show password';
            btn.setAttribute('aria-label', label);
            btn.setAttribute('title', label);
            try { input.focus(); } catch (_) {}
        });
        wrap.appendChild(btn);
    }

    function scan(root) {
        try {
            (root || document).querySelectorAll('input[type="password"]').forEach(attach);
        } catch (_) {}
    }

    function init() {
        scan(document);
        if (!window.MutationObserver || !document.body) return;
        var mo = new MutationObserver(function (muts) {
            for (var i = 0; i < muts.length; i++) {
                var nodes = muts[i].addedNodes;
                if (!nodes) continue;
                for (var j = 0; j < nodes.length; j++) {
                    var n = nodes[j];
                    if (n.nodeType !== 1) continue;
                    if (n.matches && n.matches('input[type="password"]')) attach(n);
                    else scan(n);
                }
            }
        });
        mo.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
