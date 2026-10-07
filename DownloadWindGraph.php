<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/config.php');
require_once(__DIR__ . '/Lib/helpers.php');
require_once(__DIR__ . '/Lib/schema.php');
require_once(__DIR__ . '/Lib/sessions.php');
require_once(__DIR__ . '/Lib/observations.php');
require_once(__DIR__ . '/Lib/events.php');
require_once(__DIR__ . '/Lib/ianseo.php');
require_once(__DIR__ . '/Lib/graphs.php');

// Validate the requested session.
$input = $_GET['session_id'] ?? '';

$sessionId = is_string($input)
    ? filter_var(
        $input,
        FILTER_VALIDATE_INT,
        array('options' => array('min_range' => 1))
    )
    : false;

if ($sessionId === false) {
    http_response_code(400);
    exit('Please select a valid weather session.');
}

$session = resultspack_weather_get_session($sessionId);

if (!$session) {
    http_response_code(404);
    exit('Weather session not found.');
}

if (!class_exists('DOMDocument')) {
    http_response_code(500);
    exit('SVG downloads require the PHP DOM extension.');
}

$observations = resultspack_weather_get_observations($sessionId);
$events = resultspack_weather_get_events($sessionId);

// Capture the existing renderer so the download uses the same plot.
ob_start();

resultspack_weather_render_wind_graph(
    $observations,
    $events,
    $session
);

$rendered = ob_get_clean();

if (!preg_match('/<svg\b[^>]*>.*?<\/svg>/s', $rendered, $matches)) {
    http_response_code(422);
    exit('There are not enough wind observations to export a graph.');
}

// Give the captured SVG its standalone XML namespace.
$plotXml = $matches[0];

if (strpos($plotXml, 'xmlns=') === false) {
    $plotXml = preg_replace(
        '/<svg\b/',
        '<svg xmlns="http://www.w3.org/2000/svg"',
        $plotXml,
        1
    );
}

$previousXmlErrors = libxml_use_internal_errors(true);
$plotDocument = new DOMDocument();

$loaded = $plotDocument->loadXML($plotXml, LIBXML_NONET);

libxml_clear_errors();
libxml_use_internal_errors($previousXmlErrors);

if (!$loaded) {
    http_response_code(500);
    exit('The graph could not be prepared for download.');
}

// Find the competition name.
$competitionName = 'Competition #' . $session['tournament_id'];

foreach (resultspack_weather_fetch_tournament_list() as $tournament) {
    if ((int) $tournament['id'] === (int) $session['tournament_id']) {
        $competitionName =
            ($tournament['code'] !== ''
                ? $tournament['code'] . ' — '
                : '')
            . $tournament['name'];

        break;
    }
}

// Describe the session, including its timezone.
$started = resultspack_weather_format_timestamp(
    $session['started_epoch'],
    $session['timezone'],
    'd/m/Y H:i:s T'
);

$ended = $session['ended_epoch'] === null
    ? 'open'
    : resultspack_weather_format_timestamp(
        $session['ended_epoch'],
        $session['timezone'],
        'd/m/Y H:i:s T'
    );

$headingLines = array('Wind across the session');

// Wrap ordinary competition titles across multiple lines.
foreach (explode("\n", wordwrap($competitionName, 90, "\n")) as $line) {
    $headingLines[] = $line;
}

$headingLines[] =
    'Session #' . $sessionId
    . ' | Research status: ' . ucfirst($session['research_status']);

$headingLines[] = 'Started: ' . $started . ' | Ended: ' . $ended;
$headingLines[] = 'Timezone: ' . $session['timezone'];

$plotTop = 25 + count($headingLines) * 25;
$plotHeight = 360;
$footerTop = $plotTop + $plotHeight + 25;
$exportHeight = $footerTop + 100;

// Build the standalone image.
$svgNamespace = 'http://www.w3.org/2000/svg';
$document = new DOMDocument('1.0', 'UTF-8');

$root = $document->createElementNS($svgNamespace, 'svg');
$root->setAttribute('width', '1000');
$root->setAttribute('height', (string) $exportHeight);
$root->setAttribute('viewBox', '0 0 1000 ' . $exportHeight);
$document->appendChild($root);

$title = $document->createElementNS($svgNamespace, 'title');
$title->appendChild(
    $document->createTextNode('Wind across weather session #' . $sessionId)
);
$root->appendChild($title);

$background = $document->createElementNS($svgNamespace, 'rect');
$background->setAttribute('width', '100%');
$background->setAttribute('height', '100%');
$background->setAttribute('fill', 'white');
$root->appendChild($background);

// Add text using XML text nodes so names are escaped correctly.
$addText = function ($text, $x, $y, $size = 15, $bold = false) use (
    $document,
    $root,
    $svgNamespace
) {
    $node = $document->createElementNS($svgNamespace, 'text');
    $node->setAttribute('x', (string) $x);
    $node->setAttribute('y', (string) $y);
    $node->setAttribute('font-family', 'Arial, sans-serif');
    $node->setAttribute('font-size', (string) $size);
    $node->setAttribute('fill', '#222222');

    if ($bold) {
        $node->setAttribute('font-weight', 'bold');
    }

    $node->appendChild($document->createTextNode($text));
    $root->appendChild($node);
};

foreach ($headingLines as $index => $line) {
    $addText(
        $line,
        25,
        30 + $index * 25,
        $index === 0 ? 22 : 15,
        $index === 0
    );
}

// Insert the existing plot below the heading.
$plot = $document->importNode($plotDocument->documentElement, true);
$plot->removeAttribute('style');
$plot->setAttribute('x', '0');
$plot->setAttribute('y', (string) $plotTop);
$plot->setAttribute('width', '1000');
$plot->setAttribute('height', (string) $plotHeight);
$plot->setAttribute('font-family', 'Arial, sans-serif');
$root->appendChild($plot);

// Include the legend and interpretation outside the plot area.
$addText(
    'Shaded band: lull to gust | Red boundary: gust | Grey boundary: lull',
    25,
    $footerTop
);

$addText(
    'Blue line: centred average of up to five observations within each continuous section.',
    25,
    $footerTop + 22
);

$addText(
    'Missing readings or gaps over 90 seconds break the affected lines and shading.',
    25,
    $footerTop + 44
);

$addText(
    'Wind speed: m/s | Dashed vertical markers: recorded judge decisions | ArcheryWeather',
    25,
    $footerTop + 66
);

$svg = $document->saveXML();

if ($svg === false) {
    http_response_code(500);
    exit('The SVG image could not be generated.');
}

$filename = 'ArcheryWeather-session-' . $sessionId . '-wind.svg';

header('Content-Type: image/svg+xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

echo $svg;
exit;