/* Admin Buddy - Checklist panel toggle.
 *
 * Opens / closes the canonical .ab-slide-panel + .ab-backdrop pair using
 * the same double-rAF pattern used elsewhere in the plugin (activity log,
 * snippets, custom pages) so the slide and fade animations match.
 *
 * - Open: set display:block, then toggle .is-open in the next frame so
 *         the CSS transform transition actually runs.
 * - Close: remove .is-open, wait for the CSS transition to finish, then
 *          set display:none so the element is fully inert.
 * - Body scroll is locked via body.ab-modal-open while open.
 */
(function () {
    'use strict';

    /* Must match --ab-duration-panel in tokens.css (300ms). */
    var TRANSITION_MS = 300;

    function els() {
        return {
            backdrop: document.getElementById('ab-checklist-backdrop'),
            panel:    document.getElementById('ab-checklist-panel'),
        };
    }

    function open() {
        var r = els();
        if (!r.panel || !r.backdrop) { return; }
        r.backdrop.style.display = 'block';
        r.panel.style.display    = 'block';
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                r.backdrop.classList.add('is-open');
                r.panel.classList.add('is-open');
                r.panel.setAttribute('aria-hidden', 'false');
                r.backdrop.setAttribute('aria-hidden', 'false');
                document.body.classList.add('ab-modal-open');
            });
        });
        document.addEventListener('keydown', onKeydown);
    }

    function close() {
        var r = els();
        if (!r.panel || !r.backdrop) { return; }
        r.backdrop.classList.remove('is-open');
        r.panel.classList.remove('is-open');
        r.panel.setAttribute('aria-hidden', 'true');
        r.backdrop.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('ab-modal-open');
        document.removeEventListener('keydown', onKeydown);
        setTimeout(function () {
            if (!r.panel.classList.contains('is-open')) {
                r.backdrop.style.display = 'none';
                r.panel.style.display    = 'none';
            }
        }, TRANSITION_MS);
    }

    function onKeydown(e) {
        if (e.key === 'Escape') { close(); }
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-ab-checklist-open]'))  { e.preventDefault(); open();  return; }
        if (e.target.closest('[data-ab-checklist-close]')) { e.preventDefault(); close(); }
    });
}());
