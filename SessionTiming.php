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

$PAGE_TITLE = 'Session Timing';

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
    echo 'Session Timing';
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

$timingCorrections =
    resultspack_weather_get_timing_corrections(
        $session['id']
    );

$startedLabel =
    resultspack_weather_format_timestamp(
        $session['started_epoch'],
        $session['timezone'],
        'd/m/Y H:i:s T'
    );

$endedLabel =
    $session['ended_epoch'] !== null
        ? resultspack_weather_format_timestamp(
            $session['ended_epoch'],
            $session['timezone'],
            'd/m/Y H:i:s T'
        )
        : 'In progress';

echo '<table class="Tabella freeWidth">';

echo '<tr>';
echo '<th class="Main" colspan="2">';
echo 'Session Timing — Session '
    . (int) $session['id'];
echo '</th>';
echo '</tr>';

echo '<tr>';
echo '<td class="Bold">Current start</td>';
echo '<td>'
    . htmlspecialchars($startedLabel)
    . '</td>';
echo '</tr>';

echo '<tr>';
echo '<td class="Bold">Current end</td>';
echo '<td>'
    . htmlspecialchars($endedLabel)
    . '</td>';
echo '</tr>';

echo '<tr>';
echo '<td class="Bold">Timezone</td>';
echo '<td>'
    . htmlspecialchars(
        $session['timezone']
    )
    . '</td>';
echo '</tr>';

echo '<tr>';
echo '<td class="Bold">Corrections recorded</td>';
echo '<td>'
    . count($timingCorrections)
    . '</td>';
echo '</tr>';

echo '</table>';

echo '<br>';

echo '<table class="Tabella freeWidth">';

echo '<tr>';
echo '<th class="Main" colspan="6">';
echo 'Timing correction audit trail';
echo ' (' . count($timingCorrections) . ')';
echo '</th>';
echo '</tr>';

echo '<tr>';
echo '<th class="Title">Recorded</th>';
echo '<th class="Title">Previous start</th>';
echo '<th class="Title">Previous end</th>';
echo '<th class="Title">New start</th>';
echo '<th class="Title">New end</th>';
echo '<th class="Title">Reason</th>';
echo '</tr>';

if (!$timingCorrections) {
    echo '<tr>';

    echo '<td colspan="6">';
    echo 'No timing corrections have been recorded for this session.';
    echo '</td>';

    echo '</tr>';
} else {
    foreach ($timingCorrections as $correction) {
        echo '<tr>';

        echo '<td>'
            . htmlspecialchars(
                $correction['created']
            )
            . '</td>';

        echo '<td>'
            . htmlspecialchars(
                resultspack_weather_format_timestamp(
                    $correction['old_started_epoch'],
                    $correction['timezone'],
                    'd/m/Y H:i:s T'
                )
            )
            . '</td>';

        echo '<td>'
            . htmlspecialchars(
                resultspack_weather_format_timestamp(
                    $correction['old_ended_epoch'],
                    $correction['timezone'],
                    'd/m/Y H:i:s T'
                )
            )
            . '</td>';

        echo '<td>'
            . htmlspecialchars(
                resultspack_weather_format_timestamp(
                    $correction['new_started_epoch'],
                    $correction['timezone'],
                    'd/m/Y H:i:s T'
                )
            )
            . '</td>';

        echo '<td>'
            . htmlspecialchars(
                resultspack_weather_format_timestamp(
                    $correction['new_ended_epoch'],
                    $correction['timezone'],
                    'd/m/Y H:i:s T'
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

// New timing correction form.
    echo '<br>';

    echo '<form method="post">';

    echo '<input type="hidden" name="session_id" value="'
        . (int) $session['id']
        . '">';

    echo '<table class="Tabella freeWidth">';

    echo '<tr>';
    echo '<th class="Main" colspan="2">';
    echo 'New Timing Correction';
    echo '</th>';
    echo '</tr>';

    if ($session['ended_epoch'] === null) {

        echo '<tr>';
        echo '<td colspan="2">';
        echo 'Timing corrections can only be made ';
        echo 'to completed weather sessions.';
        echo '</td>';
        echo '</tr>';

    } else {

        $startInputValue =
            resultspack_weather_format_timestamp(
                $session['started_epoch'],
                $session['timezone'],
                'Y-m-d\TH:i:s'
            );

        $endInputValue =
            resultspack_weather_format_timestamp(
                $session['ended_epoch'],
                $session['timezone'],
                'Y-m-d\TH:i:s'
            );

        echo '<tr>';
        echo '<td colspan="2">';
        echo 'Use this form if the recorded start or end of the weather session needs correcting. ';
        echo 'Times are entered in ';
        echo '<b>'
            . htmlspecialchars($session['timezone'])
            . '</b>. ';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<td class="Bold">Corrected start</td>';
        echo '<td>';

        echo '<input type="datetime-local" ';
        echo 'name="started_local" ';
        echo 'step="1" required ';
        echo 'value="'
            . htmlspecialchars($startInputValue)
            . '">';

        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<td class="Bold">Corrected end</td>';
        echo '<td>';

        echo '<input type="datetime-local" ';
        echo 'name="ended_local" ';
        echo 'step="1" required ';
        echo 'value="'
            . htmlspecialchars($endInputValue)
            . '">';

        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<td class="Bold">Reason for correction</td>';
        echo '<td>';

        echo '<textarea ';
        echo 'name="correction_reason" ';
        echo 'rows="3" required ';
        echo 'placeholder="Explain why the session times need correcting">';
        echo '</textarea>';

        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<td colspan="2">';

        echo '<input type="submit" ';
        echo 'value="Save timing correction" ';
        echo 'disabled>';

        echo '<br><small>';
        echo 'Saving is not yet enabled.';
        echo '</small>';

        echo '</td>';
        echo '</tr>';
    }

    echo '</table>';

    echo '</form>';

include('Common/Templates/tail.php');