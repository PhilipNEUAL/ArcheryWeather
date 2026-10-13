'use strict';

(() => {
    const form = document.getElementById('live-collection-form');
    const output = document.getElementById('live-collection-status');
    if (!form || !output) return;

    const button = form.querySelector('button');
    const sessionId = form.elements['session_id'].value;
    const storageKey = `archeryweather-live-collection-${sessionId}`;
    const interval = 60000;
    let collecting = false;
    let stopped = false;
    let timer = null;
    let lastCheck = 0;
    let refreshNeeded = false;

    function capturePosition() {
        const panels = [];

        document.querySelectorAll('body *').forEach(element => {
            if (
                element.scrollTop <= 0
                || element.scrollHeight <= element.clientHeight
            ) return;

            let selector;

            if (element.id) {
                selector = '#' + CSS.escape(element.id);
            } else {
                const parts = [];
                let node = element;

                while (node && node !== document.body) {
                    const parent = node.parentElement;
                    if (!parent) break;

                    const index =
                        Array.from(parent.children).indexOf(node) + 1;

                    parts.unshift(
                        node.tagName.toLowerCase()
                        + ':nth-child(' + index + ')'
                    );

                    node = parent;
                }

                selector = 'body > ' + parts.join(' > ');
            }

            panels.push({
                selector,
                top: element.scrollTop,
                left: element.scrollLeft
            });
        });

        return {
            x: window.scrollX,
            y: window.scrollY,
            panels
        };
    }

    function restorePosition(position) {
        if (!position) return;

        window.scrollTo(position.x || 0, position.y || 0);

        (position.panels || []).forEach(panel => {
            const element = document.querySelector(panel.selector);

            if (element) {
                element.scrollTop = panel.top;
                element.scrollLeft = panel.left;
            }
        });
    }

    function show(text, colour = '#245b2a') {
        output.textContent = text;
        output.style.color = colour;
    }

    function remember(text, colour) {
        lastCheck = Date.now();
        try {
            sessionStorage.setItem(storageKey, JSON.stringify({
                lastCheck, text, colour,
                position: capturePosition()
            }));
            return true;
        } catch (error) {
            return false;
        }
    }

    try {
        const previous = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
        if (previous && Number.isFinite(previous.lastCheck)) {
            // Do not allow a future browser-clock value to postpone collection.
            lastCheck = Math.min(Date.now(), previous.lastCheck);
            if (typeof previous.text === 'string') {
                show(previous.text, previous.colour || '#245b2a');
            }
            if (previous.position) {
                let interrupted = false;

                const stopRestoring = () => {
                    interrupted = true;
                };

                ['wheel', 'touchstart', 'pointerdown', 'keydown']
                    .forEach(name => {
                        window.addEventListener(
                            name,
                            stopRestoring,
                            { once: true, passive: true }
                        );
                    });

                const restore = () => {
                    if (!interrupted) {
                        restorePosition(previous.position);
                    }
                };

                const afterLoad = () => {
                    window.requestAnimationFrame(() => {
                        window.requestAnimationFrame(restore);
                    });

                    // Allow late layout adjustments to settle.
                    window.setTimeout(restore, 150);
                    window.setTimeout(restore, 400);
                };

                if (document.readyState === 'complete') {
                    afterLoad();
                } else {
                    window.addEventListener(
                        'load',
                        afterLoad,
                        { once: true }
                    );
                }
            }
        }
    } catch (error) {
        // Collection can still work when browser storage is unavailable.
    }

    function schedule() {
        window.clearTimeout(timer);
        if (stopped || collecting || document.hidden) return;
        const wait = Math.max(0, interval - (Date.now() - lastCheck));
        timer = window.setTimeout(() => collect(), wait);
    }

    async function collect() {
        if (stopped || collecting || document.hidden) return;
        window.clearTimeout(timer);
        collecting = true;
        button.disabled = true;
        show('Retrieving observations from Tempest…', '#555');
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 45000);

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                body: new FormData(form),
                signal: controller.signal
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                if ([400, 403, 404, 405].includes(response.status)) stopped = true;
                throw new Error(data.error || 'Live retrieval failed.');
            }

            if (data.session_closed) {
                stopped = true;
                show(data.message + ' Reload the viewer.', '#555');
                return;
            }

            const time = new Date().toLocaleTimeString();
            if (data.busy) {
                const text = `${data.message} Checked at ${time}. Another check will run automatically.`;
                show(text, '#555');
                remember(text, '#555');
                return;
            }

            if (!['received', 'added', 'skipped', 'stored'].every(
                key => Number.isInteger(data[key]) && data[key] >= 0
            )) {
                throw new Error('The server returned unexpected collection counts.');
            }

            const text = `${data.added} new observations added; `
                + `${data.stored} observations stored; ${data.skipped} readings skipped. `
                + `Checked at ${time}. Automatic collection runs while this tab is visible.`;
            show(text);
            const remembered = remember(text, '#245b2a');

            if (data.added > 0) {
                if (remembered) {
                    // The remembered timestamp prevents an immediate request after reload.
                    refreshNeeded = true;
                    if (!document.hidden) window.location.reload();
                } else {
                    show(text + ' Reload manually to update the summary and graphs; browser storage is unavailable.');
                }
            }
        } catch (error) {
            const explanation = error.name === 'AbortError'
                ? 'The request timed out.'
                : error instanceof SyntaxError
                    ? 'The server returned an unexpected response.'
                    : error.message;
            const text = explanation + ' Collection could not be confirmed. '
                + (stopped
                    ? 'Reload the viewer before collecting again.'
                    : 'Another check will run automatically. Retrying preserves existing readings.');
            show(text, '#a12622');
            remember(text, '#a12622');
        } finally {
            window.clearTimeout(timeout);
            collecting = false;
            button.disabled = stopped;
            schedule();
        }
    }

    form.addEventListener('submit', event => {
        event.preventDefault();
        collect();
    });

    document.addEventListener('visibilitychange', () => {
        window.clearTimeout(timer);
        if (document.hidden) return;
        if (refreshNeeded) {
            window.location.reload();
            return;
        }
        schedule();
    });

    schedule();
})();
