'use strict';

(() => {
    // Add a PNG button beside each existing SVG download link.
    const links = document.querySelectorAll(
        'a[href^="DownloadGraph.php?"]'
    );

    links.forEach((link) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = 'Download PNG';
        button.style.marginLeft = '12px';
        button.setAttribute(
            'aria-label',
            link.textContent.replace('(SVG)', '(PNG)')
        );

        link.after(button);

        button.addEventListener('click', async () => {
            button.disabled = true;
            button.textContent = 'Preparing PNG…';

            let svgUrl = null;

            try {
                // Fetch the complete export, including headings and notes.
                const response = await fetch(link.href, {
                    credentials: 'same-origin',
                    cache: 'no-store'
                });

                if (!response.ok) {
                    throw new Error(
                        response.status === 422
                            ? 'There are not enough valid observations to export this graph.'
                            : 'The graph could not be downloaded. Please try its SVG link.'
                    );
                }

                const contentType =
                    response.headers.get('Content-Type') || '';

                if (!contentType.includes('image/svg+xml')) {
                    throw new Error(
                        'The server did not return an SVG image. Please try its SVG link.'
                    );
                }

                const svgBlob = await response.blob();
                svgUrl = URL.createObjectURL(svgBlob);

                const image = new Image();

                await new Promise((resolve, reject) => {
                    image.onload = resolve;
                    image.onerror = () => reject(new Error(
                        'The exported SVG could not be opened as an image.'
                    ));
                    image.src = svgUrl;
                });

                // Double both dimensions for a sharper shared image.
                const canvas = document.createElement('canvas');
                canvas.width = image.naturalWidth * 2;
                canvas.height = image.naturalHeight * 2;

                const context = canvas.getContext('2d');

                if (!context || !canvas.width || !canvas.height) {
                    throw new Error(
                        'The browser could not prepare the PNG image.'
                    );
                }

                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                context.drawImage(
                    image, 0, 0, canvas.width, canvas.height
                );

                const pngBlob = await new Promise((resolve, reject) => {
                    canvas.toBlob((blob) => {
                        if (blob) {
                            resolve(blob);
                        } else {
                            reject(new Error(
                                'The browser could not create the PNG file.'
                            ));
                        }
                    }, 'image/png');
                });

                const sourceUrl = new URL(link.href);
                const sessionId = sourceUrl.searchParams.get('session_id');
                const graph = sourceUrl.searchParams.get('graph');

                const pngUrl = URL.createObjectURL(pngBlob);
                const download = document.createElement('a');

                download.href = pngUrl;
                download.download =
                    `ArcheryWeather-session-${sessionId}-${graph}.png`;

                document.body.appendChild(download);
                download.click();
                download.remove();

                // Allow the browser time to begin downloading.
                window.setTimeout(() => {
                    URL.revokeObjectURL(pngUrl);
                }, 60000);
            } catch (error) {
                window.alert(
                    'PNG download failed: ' + error.message
                );
            } finally {
                if (svgUrl !== null) {
                    URL.revokeObjectURL(svgUrl);
                }

                button.disabled = false;
                button.textContent = 'Download PNG';
            }
        });
    });
})();