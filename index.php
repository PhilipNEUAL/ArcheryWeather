<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/config.php');
require_once(__DIR__ . '/Lib/helpers.php');

$PAGE_TITLE = 'Archery Weather';

include('Common/Templates/head.php');

//Heading table
    echo '<table class="Tabella freeWidth">';
    echo '<tr><th class="Main">Archery Weather</th></tr>';
    echo '<tr><td>';
    echo '<b>Standalone Archery Weather module is running.</b>';
    echo '</td></tr>';
    echo '</table>';

    echo '<br>';

//Configuration table
    echo '<table class="Tabella freeWidth">';
    echo '<tr><th class="Main" colspan="2">Configuration test</th></tr>';

    echo '<tr>';
    echo '<td class="Bold">Configured</td>';
    echo '<td>'
        . (resultspack_weather_is_configured() ? 'Yes' : 'No')
        . '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td class="Bold">Timezone</td>';
    echo '<td>'
        . htmlspecialchars(resultspack_weather_timezone())
        . '</td>';
    echo '</tr>';

    echo '</table>';

    echo '<br>';

//Helpers test table
    echo '<table class="Tabella freeWidth">';
    echo '<tr><th class="Main" colspan="2">Helpers test</th></tr>';

    echo '<tr>';
    echo '<td class="Bold">Number formatting</td>';
    echo '<td>'
        . resultspack_weather_format_number(12.3456, 2)
        . '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td class="Bold">Timestamp formatting</td>';
    echo '<td>'
        . htmlspecialchars(
            resultspack_weather_format_timestamp(
                0,
                'UTC',
                'Y-m-d H:i:s'
            )
        )
        . '</td>';
    echo '</tr>';

    $testRelativeWind =
        resultspack_weather_relative_wind(
            90,
            0
        );

    echo '<tr>';
    echo '<td class="Bold">Compass direction</td>';
    echo '<td>'
        . resultspack_weather_compass_direction(90)
        . '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td class="Bold">Relative wind</td>';
    echo '<td>'
        . htmlspecialchars($testRelativeWind['label'])
        . ' (' . $testRelativeWind['angle'] . '°)'
        . '</td>';
    echo '</tr>';

    echo '</table>';

include('Common/Templates/tail.php');