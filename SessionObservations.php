<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/config.php');
require_once(__DIR__ . '/Lib/helpers.php');
require_once(__DIR__ . '/Lib/schema.php');
require_once(__DIR__ . '/Lib/sessions.php');
require_once(__DIR__ . '/Lib/observations.php');

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
    ? 'Observations — Session #' . $session['id']
    : 'Weather Session Not Found';

include('Common/Templates/head.php');

if (!$session) {
    echo '<p>Weather session not found.</p>';
    echo '<p><a href="Sessions.php">'
        . '&larr; Back to weather sessions</a></p>';

    include('Common/Templates/tail.php');
    exit;
}

$observations = resultspack_weather_get_observations(
    $session['id']
);

$backLink = '<p><a href="SessionDetails.php?session_id='
    . (int) $session['id']
    . '">&larr; Back to session details and data quality</a></p>';

echo $backLink;

echo '<table class="Tabella freeWidth">';
echo '<tr><th class="Main">Minute-by-minute observations — Session '
    . (int) $session['id']
    . '</th></tr>';

echo '<tr><td>'
    . count($observations)
    . ' locally stored observations. '
    . 'Times include the date and UTC offset.'
    . 'Wind speeds are displayed in m/s. '
    . 'Wind direction is the direction the wind comes from, '
    . 'with the session direction correction applied. '
    . 'Stored observations remain unchanged.'
    . '</td></tr>';

echo '<tr><td>'
    . '— means a reading is unavailable; zero means a recorded zero. '
    . 'Rain is the amount recorded during each observation interval.'
    . '</td></tr>';
echo '</table>';

echo '<br>';

// Field name, heading, decimal places, display conversion factor.
$columns = array(
    array('air_temp', 'Temperature (°C)', 1, 1),
    array('humidity', 'Humidity (%)', 0, 1),
    array('wind_lull', 'Wind lull (m/s)', 1, 0.44704),
    array('wind_avg', 'Wind average (m/s)', 1, 0.44704),
    array('wind_gust', 'Wind gust (m/s)', 1, 0.44704),
    array('wind_dir', 'Wind from (corrected)', 1, 1),
    array('station_pressure', 'Station pressure (hPa)', 1, 1),
    array('sea_level_pressure', 'Sea-level pressure (hPa)', 1, 1),
    array('solar_radiation', 'Solar radiation (W/m²)', 1, 1),
    array('illuminance', 'Illuminance (lux)', 0, 1),
    array('uv', 'UV index', 2, 1),
    array('precip_accumulation', 'Rain (mm)', 2, 1),
    array('strike_count', 'Lightning strikes', 0, 1),
);

// Keep wide tables within the page, with headings visible when scrolling.
echo '<div tabindex="0" role="region" '
    . 'aria-label="Minute-by-minute observations" '
    . 'style="overflow:auto;max-width:100%;max-height:70vh;">';

echo '<table class="Tabella freeWidth" '
    . 'style="min-width:1800px;">';

echo '<thead><tr>';

echo '<th class="Title" scope="col" '
    . 'style="position:sticky;top:0;z-index:1;">'
    . 'Local date and time (UTC offset)</th>';

foreach ($columns as $column) {
    echo '<th class="Title" scope="col" '
        . 'style="position:sticky;top:0;z-index:1;">'
        . htmlspecialchars($column[1], ENT_QUOTES, 'UTF-8')
        . '</th>';
}

echo '</tr></thead>';
echo '<tbody>';

if (!$observations) {
    echo '<tr><td colspan="' . (count($columns) + 1) . '">'
        . 'No locally stored observations are available for this session.'
        . '</td></tr>';
} else {
    foreach ($observations as $index => $observation) {
        echo '<tr'
            . ($index % 2 === 1
                ? ' style="background-color:#f3f6f8;"'
                : '')
            . '>';

        echo '<td style="white-space:nowrap;">'
            . htmlspecialchars(
                resultspack_weather_format_timestamp(
                    $observation['timestamp'],
                    $session['timezone'],
                    'd/m/Y H:i:s P'
                ),
                ENT_QUOTES,
                'UTF-8'
            )
            . '</td>';

        foreach ($columns as $column) {
            $field = $column[0];
            $value = $observation[$field] ?? null;
            $text = '—';

            if ($value !== null && is_numeric($value)) {
                if ($field === 'wind_dir') {
                    $direction =
                        resultspack_weather_effective_wind_direction(
                            $value,
                            $session
                        );

                    if ($direction !== null) {
                        $text =
                            resultspack_weather_compass_direction(
                                $direction
                            )
                            . ' '
                            . resultspack_weather_format_number(
                                $direction,
                                1
                            )
                            . '°';
                    }
                } else {
                    $text = resultspack_weather_format_number(
                        (float) $value * $column[3],
                        $column[2]
                    );
                }
            }

            echo '<td style="text-align:right;white-space:nowrap;">'
                . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
                . '</td>';
        }

        echo '</tr>';
    }
}

echo '</tbody>';
echo '</table>';
echo '</div>';

echo $backLink;

include('Common/Templates/tail.php');