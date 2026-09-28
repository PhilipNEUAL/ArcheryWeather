<?php

require_once(__DIR__ . '/Lib/bootstrap.php');

$PAGE_TITLE = 'Archery Weather';

include('Common/Templates/head.php');

echo '<table class="Tabella freeWidth">';
echo '<tr><th class="Main">Archery Weather</th></tr>';
echo '<tr><td>';
echo '<b>Weather module is running.</b>';
echo '</td></tr>';
echo '</table>';

include('Common/Templates/tail.php');