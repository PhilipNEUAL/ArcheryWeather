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

$weatherSessions =
    resultspack_weather_get_sessions();

$tournaments =
    resultspack_weather_fetch_tournament_list();

$tournamentNames = array();

foreach ($tournaments as $tournament) {
    $tournamentNames[$tournament['id']] =
        ($tournament['code'] !== ''
            ? $tournament['code'] . ' — '
            : '')
        . $tournament['name'];
}

echo '<table class="Tabella freeWidth">';

echo '<tr>';
echo '<th class="Main" colspan="7">';
echo 'Archery Weather Sessions';
echo '</th>';
echo '</tr>';

echo '<tr>';
echo '<th class="Title">Session</th>';
echo '<th class="Title">Competition</th>';
echo '<th class="Title">Started</th>';
echo '<th class="Title">Duration</th>';
echo '<th class="Title">Status</th>';
echo '<th class="Title">Observations</th>';
echo '<th class="Title">Events</th>';
echo '</tr>';

if (!$weatherSessions) {
    echo '<tr>';
    echo '<td colspan="7">';
    echo 'No weather sessions have been recorded.';
    echo '</td>';
    echo '</tr>';
} else {
    foreach ($weatherSessions as $session) {
        $competitionName =
            $tournamentNames[$session['tournament_id']]
            ?? (
                'Competition '
                . $session['tournament_id']
            );

        $startedLabel =
            resultspack_weather_format_timestamp(
                $session['started_epoch'],
                $session['timezone'],
                'd/m/Y H:i:s'
            );

        if ($session['ended_epoch'] !== null) {
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

            $sessionState = 'Completed';
        } else {
            $durationLabel = 'In progress';
            $sessionState = 'Active';
        }

        $observations =
            resultspack_weather_count_observations(
                $session['id']
            );

        $events =
            resultspack_weather_get_events(
                $session['id']
            );

        echo '<tr>';

        echo '<td>'
            . (int) $session['id']
            . '</td>';

        echo '<td>'
            . htmlspecialchars($competitionName)
            . '</td>';

        echo '<td>'
            . htmlspecialchars($startedLabel)
            . '</td>';

        echo '<td>'
            . htmlspecialchars($durationLabel)
            . '</td>';

        echo '<td>'
            . htmlspecialchars(
                $sessionState
                . ' — '
                . ucfirst(
                    $session['research_status']
                )
            )
            . '</td>';

        echo '<td>'
            . (int) $observations
            . '</td>';

        echo '<td>'
            . count($events)
            . '</td>';

        echo '</tr>';
    }
}

echo '</table>';

include('Common/Templates/tail.php');