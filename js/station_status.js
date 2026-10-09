'use strict';

(() => {
    const station = document.getElementById('station_id');
    const output = document.getElementById('station-status');

    if (!station || !output) {
        return;
    }

    let controller = null;
    let requestNumber = 0;

    function show(message, colour) {
        output.textContent = message;
        output.style.color = colour;
    }

    function ageText(seconds) {
        if (seconds < 60) {
            return `${seconds} seconds`;
        }

        const minutes = Math.floor(seconds / 60);

        if (minutes < 60) {
            return `${minutes} minutes`;
        }

        const hours = Math.floor(minutes / 60);

        if (hours < 24) {
            return `${hours} h ${minutes % 60} min`;
        }

        return `${Math.floor(hours / 24)} days ${hours % 24} h`;
    }

    function formatTime(timestamp) {
        return new Date(timestamp * 1000).toLocaleString();
    }

    async function checkStatus() {
        if (document.hidden || controller !== null) {
            return;
        }

        if (!station.value) {
            show('Select a station to check its latest observation.', '#555');
            return;
        }

        const thisRequest = ++requestNumber;
        const activeController = new AbortController();
        controller = activeController;

        show('Checking the latest observation…', '#555');

        const timeout = window.setTimeout(() => {
            activeController.abort();
        }, 30000);

        try {
            const url = new URL('StationStatus.php', window.location.href);
            url.searchParams.set('station_id', station.value);

            const response = await fetch(url, {
                credentials: 'same-origin',
                cache: 'no-store',
                signal: activeController.signal
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || 'The station check failed.');
            }

            const colours = {
                live: '#246b2b',
                delayed: '#805500',
                stale: '#a12622',
                unknown: '#555'
            };

            if (
                !Object.prototype.hasOwnProperty.call(colours, data.status)
                || !Number.isFinite(data.checked_at)
            ) {
                throw new Error('The server returned an unexpected status.');
            }

            // Ignore replies belonging to a previously selected station.
            if (thisRequest !== requestNumber) {
                return;
            }

            const parts = [
                `${data.label}: ${data.message}`
            ];

            if (Number.isFinite(data.timestamp)) {
                parts.push(
                    `Last observation: ${formatTime(data.timestamp)}.`
                );
            }

            if (Number.isFinite(data.age_seconds)) {
                parts.push(
                    `Age when checked: ${ageText(data.age_seconds)}.`
                );
            }

            parts.push(
                `Checked: ${formatTime(data.checked_at)}.`,
                'Times shown in your browser’s local timezone.'
            );

            show(parts.join(' '), colours[data.status]);
        } catch (error) {
            if (thisRequest !== requestNumber) {
                return;
            }

            const message = error.name === 'AbortError'
                ? 'The request timed out.'
                : 'Could not obtain a valid station status.';

            show(
                `UNKNOWN: Latest check failed. ${message} `
                + 'Another check will run automatically.',
                '#a12622'
            );
        } finally {
            window.clearTimeout(timeout);

            if (thisRequest === requestNumber) {
                controller = null;
            }
        }
    }

    function restartCheck() {
        requestNumber++;

        if (controller !== null) {
            controller.abort();
            controller = null;
        }

        if (document.hidden) {
            show('Checks paused while this page is hidden.', '#555');
            return;
        }

        checkStatus();
    }

    station.addEventListener('change', restartCheck);
    document.addEventListener('visibilitychange', restartCheck);

    window.setInterval(checkStatus, 60000);
    checkStatus();
})();