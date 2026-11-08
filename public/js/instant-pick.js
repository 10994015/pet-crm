/*
 * Instant feedback for choice buttons that save through Livewire.
 *
 * Without this, a picked button only lights up once the server has answered (a few
 * hundred milliseconds). Here it lights up on the click itself; when the server's
 * reply arrives, Livewire redraws from the real state, so a refused choice still
 * corrects itself.
 *
 *   data-pick           one choice in its [data-pick-group]
 *   data-pick="toggle"  turns on and off by itself (add-ons, multi-select)
 *   data-pick="switch"  one choice in its group, and clicking it again turns it off
 *   data-pick-class     the "selected" class, default "is-on"
 */
document.addEventListener('click', (event) => {
    const el = event.target.closest('[data-pick]');

    if (!el || el.disabled || el.getAttribute('aria-disabled') === 'true') {
        return;
    }

    const on = el.dataset.pickClass || 'is-on';
    const mark = (node, value) => {
        node.classList.toggle(on, value);
        if (node.hasAttribute('aria-pressed')) {
            node.setAttribute('aria-pressed', value ? 'true' : 'false');
        }
    };

    if (el.dataset.pick === 'toggle') {
        mark(el, !el.classList.contains(on));
        return;
    }

    if (el.dataset.pick === 'switch' && el.classList.contains(on)) {
        mark(el, false);
        return;
    }

    const group = el.closest('[data-pick-group]');
    // Only the choices of the same kind give way: picking a main service leaves add-ons alone.
    group?.querySelectorAll('[data-pick]').forEach((node) => {
        if (node !== el && node.dataset.pick === el.dataset.pick) {
            mark(node, false);
        }
    });
    mark(el, true);
}, true);
