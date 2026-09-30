<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/config.php');
require_once(__DIR__ . '/Lib/helpers.php');
require_once(__DIR__ . '/Lib/schema.php');
require_once(__DIR__ . '/Lib/sessions.php');
require_once(__DIR__ . '/Lib/corrections.php');

$sessionId =
    isset($_GET['session_id'])
        ? (int) $_GET['session_id']
        : 0;

$session =
    resultspack_weather_get_session(
        $sessionId
    );

$PAGE_TITLE = 'Direction Reference';

include('Common/Templates/head.php');

echo '<p>';

echo '<a href="SessionView.php?session_id='
    . (int) $sessionId
    . '">';

echo '&larr; Back to weather session';

echo '</a>';

echo '</p>';

if (!$session) {
    echo '<table class="Tabella freeWidth">';

    echo '<tr>';
    echo '<th class="Main">';
    echo 'Direction Reference';
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

$effectiveBearing =
    resultspack_weather_effective_shooting_bearing(
        $session
    );

$directionCorrections =
    resultspack_weather_get_direction_corrections(
        $session['id']
    );

echo '<table class="Tabella freeWidth">';

echo '<tr>';
echo '<th class="Main" colspan="2">';
echo 'Direction Reference — Session '
    . (int) $session['id'];
echo '</th>';
echo '</tr>';

echo '<tr>';
echo '<td class="Bold">Recorded shooting bearing</td>';
echo '<td>'
    . (
        $session['shooting_bearing'] !== null
            ? resultspack_weather_format_number(
                $session['shooting_bearing'],
                1
            ) . '°'
            : 'Not recorded'
    )
    . '</td>';
echo '</tr>';

echo '<tr>';
echo '<td class="Bold">Effective shooting bearing</td>';
echo '<td>'
    . (
        $effectiveBearing !== null
            ? resultspack_weather_format_number(
                $effectiveBearing,
                1
            ) . '°'
            : 'Not available'
    )
    . '</td>';
echo '</tr>';

echo '<tr>';
echo '<td class="Bold">Verification method</td>';
echo '<td>'
    . htmlspecialchars(
        resultspack_weather_direction_verification_label(
            $session['direction_verification']
        )
    )
    . '</td>';
echo '</tr>';

echo '<tr>';
echo '<td class="Bold">Current correction</td>';
echo '<td>'
    . resultspack_weather_format_number(
        $session['direction_correction'],
        1
    )
    . '°</td>';
echo '</tr>';

echo '<tr>';
echo '<td class="Bold">Corrections recorded</td>';
echo '<td>'
    . count($directionCorrections)
    . '</td>';
echo '</tr>';

echo '</table>';

echo '<br>';

echo '<table class="Tabella freeWidth">';

echo '<tr>';
echo '<th class="Main" colspan="7">';
echo 'Direction correction audit trail';
echo ' (' . count($directionCorrections) . ')';
echo '</th>';
echo '</tr>';

echo '<tr>';
echo '<th class="Title">Recorded</th>';
echo '<th class="Title">Recorded bearing</th>';
echo '<th class="Title">Verified bearing</th>';
echo '<th class="Title">Previous correction</th>';
echo '<th class="Title">New correction</th>';
echo '<th class="Title">Verification</th>';
echo '<th class="Title">Reason</th>';
echo '</tr>';

if (!$directionCorrections) {
    echo '<tr>';

    echo '<td colspan="7">';
    echo 'No direction corrections have been recorded for this session.';
    echo '</td>';

    echo '</tr>';
} else {
    foreach ($directionCorrections as $correction) {
        echo '<tr>';

        echo '<td>'
            . htmlspecialchars(
                $correction['created']
            )
            . '</td>';

        echo '<td>'
            . (
                $correction['recorded_bearing'] !== null
                    ? resultspack_weather_format_number(
                        $correction['recorded_bearing'],
                        1
                    ) . '°'
                    : 'Not recorded'
            )
            . '</td>';

        echo '<td>'
            . resultspack_weather_format_number(
                $correction['verified_bearing'],
                1
            )
            . '°</td>';

        echo '<td>'
            . resultspack_weather_format_number(
                $correction['old_correction'],
                1
            )
            . '°</td>';

        echo '<td>'
            . resultspack_weather_format_number(
                $correction['new_correction'],
                1
            )
            . '°</td>';

        echo '<td>'
            . htmlspecialchars(
                resultspack_weather_direction_verification_label(
                    $correction['verification']
                )
            )
            . '</td>';

        echo '<td>'
            . nl2br(
                htmlspecialchars(
                    $correction['reason']
                )
            )
            . '</td>';

        echo '</tr>';
    }
}

echo '</table>';

include('Common/Templates/tail.php');