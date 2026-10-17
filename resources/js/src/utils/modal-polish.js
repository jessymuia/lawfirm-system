/**
 * Every dialog in the app behaves the same way:
 *  - it always has a visible close button (added if a screen forgot one);
 *  - clicking the dimmed area does nothing (a stray click can no longer throw away a half-filled form, or pop a
 *    "discard changes?" question out of nowhere); close with the × button, Cancel, or Esc;
 *  - a dimmed backdrop can never be left behind after a page change.
 */
const CLOSE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';

export function installModalPolish(router) {
    document.addEventListener('show.bs.modal', (event) => {
        const el = event.target;
        if (!(el instanceof HTMLElement) || !el.classList.contains('modal')) return;

        // static backdrop: a click outside shakes the dialog instead of closing it
        const instance = window.bootstrap?.Modal.getInstance(el);
        if (instance && instance._config) instance._config.backdrop = 'static';

        const header = el.querySelector('.modal-header');
        if (header && !header.querySelector('[data-bs-dismiss="modal"], [data-dismiss="modal"], .btn-close, .btn-close-modern')) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn-close-modern btn-close-injected';
            btn.setAttribute('data-bs-dismiss', 'modal');
            btn.setAttribute('aria-label', 'Close');
            btn.innerHTML = CLOSE_SVG;
            header.appendChild(btn);
        }
    }, true);

    // no stray dimming left after navigating
    const cleanup = () => {
        if (document.querySelector('.modal.show')) return;
        document.querySelectorAll('.modal-backdrop').forEach((n) => n.remove());
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    };
    router?.afterEach(() => setTimeout(cleanup, 50));
}
