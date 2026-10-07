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
require_once(__DIR__ . '/Lib/graph_export.php');
require_once(__DIR__ . '/Lib/summary.php');

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

$graphType = $_GET['graph'] ?? 'wind';

$graphTitles = array(
    'wind' => 'Wind across the session',
    'compass' => 'Wind direction compass',
    'air_temp' => 'Temperature across the session',
    'humidity' => 'Relative humidity across the session',
    'station_pressure' => 'Station pressure across the session',
    'solar_radiation' => 'Solar radiation across the session',
);

if (
    !is_string($graphType)
    || !array_key_exists($graphType, $graphTitles)
) {
    http_response_code(400);
    exit('Please select a valid graph.');
}

$graphTitle = $graphTitles[$graphType];
$plotHeight = $graphType === 'compass' ? 680 : 360;

$observations = resultspack_weather_get_observations($sessionId);
$events = resultspack_weather_get_events($sessionId);

// Capture the existing renderer so the download uses the same plot.
ob_start();

if ($graphType === 'wind') {
    resultspack_weather_render_wind_graph(
        $observations,
        $events,
        $session
    );
} elseif ($graphType === 'compass') {
    resultspack_weather_viewer_render_wind_rose(
        $observations,
        $session
    );
} else {
    $graphSettings = array(
        'air_temp' => array(
            'Temperature (°C)', 'Temperature',
            '#ad1457', 1, false, 4, 0.5
        ),
        'humidity' => array(
            'Relative humidity (%)', 'Relative humidity',
            '#00838f', 0, false, 10, 1
        ),
        'station_pressure' => array(
            'Station pressure (hPa)', 'Station pressure',
            '#5d4037', 1, false, 5, 0.5
        ),
        'solar_radiation' => array(
            'Solar radiation (W/m²)', 'Solar radiation',
            '#f9a825', 0, true, 0, 1
        ),
    );

    $settings = $graphSettings[$graphType];

    resultspack_weather_viewer_render_single_graph(
        $graphTitle,
        $graphTitle,
        $observations,
        $graphType,
        $events,
        $session,
        $settings[0],
        $settings[1],
        $settings[2],
        $settings[3],
        $settings[4],
        $settings[5],
        $settings[6]
    );
}

$rendered = ob_get_clean();

if (!preg_match('/<svg\b[^>]*>.*?<\/svg>/s', $rendered, $matches)) {
    http_response_code(422);
    exit('There are not enough valid observations to export this graph.');
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

$headingLines = array($graphTitle);

// Wrap ordinary competition titles across multiple lines.
foreach (explode("\n", wordwrap($competitionName, 90, "\n")) as $line) {
    $headingLines[] = $line;
}

$headingLines[] =
    'Session #' . $sessionId
    . ' | Research status: ' . ucfirst($session['research_status']);

$headingLines[] = 'Started: ' . $started . ' | Ended: ' . $ended;
$headingLines[] = 'Timezone: ' . $session['timezone'];

if ($graphType === 'wind') {
    $footerLines = array(
        'Shaded band: lull to gust | Red boundary: gust | Grey boundary: lull',

        'Blue line: centred average of up to five observations within each continuous section.',

        'Missing readings or gaps over 90 seconds break the affected lines and shading.',

        'Wind speed: m/s | Dashed vertical markers: recorded judge decisions | ArcheryWeather',
    );
} elseif ($graphType === 'compass') {
    $windContext = resultspack_weather_wind_context(
        $observations,
        $session
    );

    $bearing = resultspack_weather_effective_shooting_bearing(
        $session
    );

    $footerLines = array(
        $windContext['prevailing_direction'] !== null
            ? 'Blue arrow: prevailing wind flow, pointing from its source towards its destination.'
            : 'No prevailing wind arrow: recorded directions are highly variable.',

        $bearing !== null
            ? 'Dashed black arrow: towards targets ('
                . resultspack_weather_format_number($bearing, 1)
                . '°). Thin line: shooting line.'
            : 'Shooting bearing not recorded; no shooting-direction arrow is shown.',

        'Wind direction uses a circular mean; the session direction correction is applied.',

        'ArcheryWeather',
    );
} else {
    $footerLines = array(
        $settings[1] . ': recorded readings, without smoothing. Units are shown on the vertical axis.',

        'Missing readings or gaps over 90 seconds break the line.',

        'Dashed vertical markers: recorded judge decisions | ArcheryWeather',
    );
}

try {
    $svg = resultspack_weather_build_graph_export(
        $plotDocument,
        $headingLines,
        $footerLines,
        $plotHeight
    );
} catch (RuntimeException $exception) {
    http_response_code(500);
    exit('The SVG image could not be generated.');
}

$filename = 'ArcheryWeather-session-'
    . $sessionId . '-' . $graphType . '.svg';

header('Content-Type: image/svg+xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

echo $svg;
exit;