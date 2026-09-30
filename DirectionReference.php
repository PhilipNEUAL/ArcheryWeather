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

// New direction correction form.
    echo '<br>';

    echo '<form method="post">';

    echo '<input type="hidden" name="session_id" value="'
        . (int) $session['id']
        . '">';

    echo '<table class="Tabella freeWidth">';

    echo '<tr>';
    echo '<th class="Main" colspan="2">';
    echo 'New Direction Correction';
    echo '</th>';
    echo '</tr>';

    echo '<tr>';
    echo '<td class="Bold">Verified shooting bearing</td>';
    echo '<td>';
    echo '<input type="number" ';
    echo 'name="verified_bearing" ';
    echo 'min="0" max="359.99" step="0.01" ';
    echo 'required> °';
    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td class="Bold">Verification method</td>';
    echo '<td>';

    echo '<select name="verification" required>';

    echo '<option value="">Choose verification method...</option>';
    echo '<option value="map_satellite">Map / satellite</option>';
    echo '<option value="second_compass">Second compass</option>';
    echo '<option value="known_site_alignment">Known site alignment</option>';
    echo '<option value="surveyed_bearing">Surveyed bearing</option>';
    echo '<option value="other">Other independent method</option>';

    echo '</select>';

    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td class="Bold">Reason for correction</td>';
    echo '<td>';

    echo '<textarea ';
    echo 'name="correction_reason" ';
    echo 'rows="3" ';
    echo 'required ';
    echo 'placeholder="Explain why this correction is necessary">';
    echo '</textarea>';

    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td colspan="2">';

    echo '<input type="submit" ';
    echo 'value="Save direction correction" ';
    echo 'disabled>';

    echo '<br><small>';
    echo 'Saving is not yet enabled.';
    echo '</small>';

    echo '</td>';
    echo '</tr>';

    echo '</table>';
    echo '</form>';

include('Common/Templates/tail.php');