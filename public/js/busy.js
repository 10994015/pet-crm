/*
 * A loading cue for anything that waits on the server.
 *
 * When a Livewire request (or a plain form post) takes longer than DELAY ms, a thin
 * bar runs along the top of the page and the button that started it shows a small
 * spinner. Quick replies show nothing, so the page doesn't flicker on every click.
 */
(() => {
    const DELAY = 200;
    const root = document.documentElement;
    let pending = 0;
    let timer = null;
    let lastClicked = null;

    const bar = document.createElement('div');
    bar.className = 'busy-bar';
    bar.setAttribute('role', 'progressbar');
    bar.setAttribute('aria-label', '讀取中');
    bar.hidden = true;

    const show = () => {
        if (!bar.isConnected) document.body.appendChild(bar);
        bar.hidden = false;
        root.classList.add('is-busy');
        lastClicked?.setAttribute('data-busy', '');
    };

    const start = () => {
        pending++;
        if (pending === 1) timer = setTimeout(show, DELAY);
    };

    const stop = () => {
        pending = Math.max(0, pending - 1);
        if (pending > 0) return;
        clearTimeout(timer);
        bar.hidden = true;
        root.classList.remove('is-busy');
        document.querySelectorAll('[data-busy]').forEach((el) => el.removeAttribute('data-busy'));
        lastClicked = null;
    };

    // Remember what was pressed, so its spinner can show if the reply is slow.
    document.addEventListener('pointerdown', (event) => {
        lastClicked = event.target.closest('button, [data-pick], a, label') ?? null;
    }, true);

    const hook = (Livewire) => {
        Livewire.interceptRequest(({ onSend, onFinish, onCancel, onFailure, onError }) => {
            let done = false;
            const end = () => { if (!done) { done = true; stop(); } };
            onSend(start);
            onFinish(end);
            onCancel(end);
            onFailure(end);
            onError(end);
        });
    };

    if (window.Livewire?.interceptRequest) {
        hook(window.Livewire);
    } else {
        document.addEventListener('livewire:init', () => hook(window.Livewire));
    }

    // Ordinary form posts (sign-in, booking lookup) load a new page; show the bar until it does.
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (event.defaultPrevented || form.hasAttribute('wire:submit') || form.closest('[wire\\:id]')) return;
        lastClicked = event.submitter ?? lastClicked;
        start();
    });

    // Coming back with the browser's back button must not leave the bar running.
    window.addEventListener('pageshow', () => { pending = 1; stop(); });
})();
