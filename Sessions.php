<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/config.php');
require_once(__DIR__ . '/Lib/helpers.php');
require_once(__DIR__ . '/Lib/ianseo.php');
require_once(__DIR__ . '/Lib/schema.php');
require_once(__DIR__ . '/Lib/sessions.php');
require_once(__DIR__ . '/Lib/observations.php');
require_once(__DIR__ . '/Lib/events.php');
require_once(__DIR__ . '/Lib/corrections.php');

$PAGE_TITLE = 'Archery Weather Sessions';

include('Common/Templates/head.php');

echo '<table class="Tabella">';
echo '<tr>';
echo '<th class="Main">Archery Weather Sessions</th>';
echo '</tr>';

echo '<tr>';
echo '<td>';
echo 'Standalone session browser is working.';
echo '</td>';
echo '</tr>';

echo '</table>';

include('Common/Templates/tail.php');