<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/config.php');

$PAGE_TITLE = 'Archery Weather';

include('Common/Templates/head.php');

echo '<table class="Tabella freeWidth">';
echo '<tr><th class="Main">Archery Weather</th></tr>';
echo '<tr><td>';
echo '<b>Standalone Archery Weather module is running.</b>';
echo '</td></tr>';
echo '</table>';

echo '<br>';

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

include('Common/Templates/tail.php');