<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/config.php');
require_once(__DIR__ . '/Lib/corrections.php');
require_once(__DIR__ . '/Lib/display.php');
require_once(__DIR__ . '/Lib/events.php');
require_once(__DIR__ . '/Lib/graphs.php');
require_once(__DIR__ . '/Lib/helpers.php');
require_once(__DIR__ . '/Lib/ianseo.php');
require_once(__DIR__ . '/Lib/observations.php');
require_once(__DIR__ . '/Lib/schema.php');
require_once(__DIR__ . '/Lib/summary.php');
require_once(__DIR__ . '/Lib/sessions.php');

$sessionInput = $_GET['session_id'] ?? '';

$sessionId = is_string($sessionInput)
    ? filter_var(
        $sessionInput,
        FILTER_VALIDATE_INT,
        array('options' => array('min_range' => 1))
    )
    : false;

$session = $sessionId !== false
    ? resultspack_weather_get_session($sessionId)
    : null;

if ($sessionId === false) {
    http_response_code(400);
} elseif (!$session) {
    http_response_code(404);
}

$PAGE_TITLE = $session
    ? 'Weather Session #' . $session['id']
    : 'Weather Session Not Found';

include('Common/Templates/head.php');

echo '<p>';
echo '<a href="Sessions.php">'
    . '&larr; Back to weather sessions'
    . '</a>';
echo '</p>';

if (!$session) {
    echo '<table class="Tabella freeWidth">';

    echo '<tr>';
    echo '<th class="Main">';
    echo 'Weather Session';
    echo '</th>';
    echo '</tr>';

    echo '<tr>';
    echo '<td>';
    echo 'Weather session not found.';
    echo '</td>';
    echo '</tr>';

    echo '</table>';

    include('Common/Templates/tail.php');
    exit;
}

$tournaments =
    resultspack_weather_fetch_tournament_list();

$competitionName =
    'Competition ' . $session['tournament_id'];

foreach ($tournaments as $tournament) {
    if (
        (int) $tournament['id']
        === (int) $session['tournament_id']
    ) {
        $competitionName =
            ($tournament['code'] !== ''
                ? $tournament['code'] . ' — '
                : '')
            . $tournament['name'];

        break;
    }
}

$observations =
    resultspack_weather_get_observations(
        $session['id']
    );

$startedLabel =
    resultspack_weather_format_timestamp(
        $session['started_epoch'],
        $session['timezone'],
        'd/m/Y H:i:s'
    );

if ($session['ended_epoch'] !== null) {
    $endedLabel =
        resultspack_weather_format_timestamp(
            $session['ended_epoch'],
            $session['timezone'],
            'd/m/Y H:i:s'
        );

    $durationSeconds =
        max(
            0,
            $session['ended_epoch']
            - $session['started_epoch']
        );

    $durationMinutes =
        (int) floor(
            $durationSeconds / 60
        );

    $durationHours =
        (int) floor(
            $durationMinutes / 60
        );

    $remainingMinutes =
        $durationMinutes % 60;

    if ($durationHours > 0) {
        $durationLabel =
            $durationHours
            . ' h '
            . $remainingMinutes
            . ' min';
    } else {
        $durationLabel =
            $remainingMinutes
            . ' min';
    }
} else {
    $endedLabel = 'Not ended';
    $durationLabel = 'Open session';
}

echo '<table class="Tabella freeWidth">';

echo '<tr>';
echo '<th class="Main" colspan="2">';
echo 'Weather Session '
    . (int) $session['id'];
echo '</th>';
echo '</tr>';

echo '<th class="Title" colspan="2">';
echo 'Session data';
echo '</th>';

echo '<tr>';
echo '<td class="Bold">Competition</td>';
echo '<td>'
    . htmlspecialchars($competitionName)
    . '</td>';
echo '</tr>';

echo '<tr>';
echo '<td class="Bold">Started</td>';
echo '<td>'
    . htmlspecialchars($startedLabel)
    . '</td>';
echo '</tr>';

echo '<tr>';
echo '<td class="Bold">Duration</td>';
echo '<td>';

echo htmlspecialchars($durationLabel);

echo '<br>';

echo '<a href="SessionTiming.php?session_id='
    . (int) $session['id']
    . '">';

echo 'Session timing and audit ↗';

echo '</a>';

echo '</td>';
echo '</tr>';

$effectiveShootingBearing =
    resultspack_weather_effective_shooting_bearing(
        $session
    );

echo '<tr>';
echo '<td class="Bold">Shooting direction</td>';
echo '<td>';

if ($effectiveShootingBearing !== null) {
    echo resultspack_weather_format_number(
        $effectiveShootingBearing,
        1
    )
        . '°';

    echo ' — '
        . htmlspecialchars(
            resultspack_weather_direction_verification_label(
                $session['direction_verification']
            )
        );
} else {
    echo 'Not recorded';
}

echo '<br>';

echo '<a href="DirectionReference.php?session_id='
    . (int) $session['id']
    . '">';

echo 'Direction reference and audit ↗';

echo '</a>';

echo '</td>';
echo '</tr>';

echo '<tr>';
echo '<td class="Bold">Session records</td>';
echo '<td><a href="SessionDetails.php?session_id='
    . (int) $session['id']
    . '">Session details and data quality ↗</a></td>';
echo '</tr>';

echo '</table>';

resultspack_weather_render_environmental_summary(
    $observations,
    $session
);

$events = resultspack_weather_get_events($session['id']);

resultspack_weather_render_wind_graph(
    $observations,
    $events,
    $session
);

echo '<p><a href="DownloadGraph.php?session_id='
    . (int) $session['id']
    . '&amp;graph=wind">Download wind graph (SVG)</a></p>';

resultspack_weather_viewer_render_wind_rose(
    $observations,
    $session
);

echo '<p><a href="DownloadGraph.php?session_id='
    . (int) $session['id']
    . '&amp;graph=compass">Download wind compass (SVG)</a></p>';

echo '<details style="margin-top:20px">';
echo '<summary style="cursor:pointer;font-weight:bold;padding:10px">';
echo 'More environmental graphs — temperature, humidity, pressure and solar radiation';
echo '</summary>';

$environmentGraphs = array(
    array(
        'Temperature', 'air_temp', 'Temperature (°C)',
        '#ad1457', 1, false, 4, 0.5
    ),
    array(
        'Relative humidity', 'humidity', 'Relative humidity (%)',
        '#00838f', 0, false, 10, 1
    ),
    array(
        'Station pressure', 'station_pressure', 'Station pressure (hPa)',
        '#5d4037', 1, false, 5, 0.5
    ),
    array(
        'Solar radiation', 'solar_radiation', 'Solar radiation (W/m²)',
        '#f9a825', 0, true, 0, 1
    ),
);

foreach ($environmentGraphs as $graph) {
    resultspack_weather_viewer_render_single_graph(
        $graph[0] . ' across the session',
        $graph[0] . ' across the weather session',
        $observations,
        $graph[1],
        $events,
        $session,
        $graph[2],
        $graph[0],
        $graph[3],
        $graph[4],
        $graph[5],
        $graph[6],
        $graph[7]
    );

    echo '<p><a href="DownloadGraph.php?session_id='
    . (int) $session['id']
    . '&amp;graph=' . rawurlencode($graph[1])
    . '">Download '
    . htmlspecialchars($graph[0], ENT_QUOTES, 'UTF-8')
    . ' graph (SVG)</a></p>';
}

echo '</details>';

echo '<script src="Js/graph_download.js" defer></script>';

include('Common/Templates/tail.php');