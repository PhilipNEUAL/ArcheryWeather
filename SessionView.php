<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/config.php');
require_once(__DIR__ . '/Lib/helpers.php');
require_once(__DIR__ . '/Lib/ianseo.php');
require_once(__DIR__ . '/Lib/schema.php');
require_once(__DIR__ . '/Lib/sessions.php');
require_once(__DIR__ . '/Lib/observations.php');
require_once(__DIR__ . '/Lib/quality.php');
require_once(__DIR__ . '/Lib/events.php');
require_once(__DIR__ . '/Lib/corrections.php');

$sessionId =
    isset($_GET['session_id'])
        ? (int) $_GET['session_id']
        : 0;

$session =
    resultspack_weather_get_session(
        $sessionId
    );

$PAGE_TITLE = 'Archery Weather Session';

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

echo '<table class="Tabella freeWidth">';

echo '<tr>';
echo '<th class="Main" colspan="2">';
echo 'Weather Session '
    . (int) $session['id'];
echo '</th>';
echo '</tr>';

echo '<tr>';
echo '<td class="Bold">Session ID</td>';
echo '<td>'
    . (int) $session['id']
    . '</td>';
echo '</tr>';

echo '<tr>';
echo '<td class="Bold">Started</td>';
echo '<td>'
    . htmlspecialchars(
        resultspack_weather_format_timestamp(
            $session['started_epoch'],
            $session['timezone'],
            'd/m/Y H:i:s'
        )
    )
    . '</td>';
echo '</tr>';

echo '<tr>';
echo '<td class="Bold">Research status</td>';
echo '<td>'
    . htmlspecialchars(
        ucfirst(
            $session['research_status']
        )
    )
    . '</td>';
echo '</tr>';

echo '</table>';

include('Common/Templates/tail.php');