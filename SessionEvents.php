<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/config.php');
require_once(__DIR__ . '/Lib/helpers.php');
require_once(__DIR__ . '/Lib/schema.php');
require_once(__DIR__ . '/Lib/sessions.php');
require_once(__DIR__ . '/Lib/events.php');
require_once(__DIR__ . '/Lib/event_time.php');

$input = $_GET['session_id'] ?? '';

$sessionId = is_string($input)
    ? filter_var(
        $input,
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

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (
    $session
    && empty($_SESSION['archeryweather_event_tokens'][$sessionId])
) {
    $_SESSION['archeryweather_event_tokens'][$sessionId] =
        bin2hex(random_bytes(32));
}

$eventFeedback =
    $_SESSION['archeryweather_event_feedback'][$sessionId]
    ?? null;

unset($_SESSION['archeryweather_event_feedback'][$sessionId]);

$eventActionInput = '';
$eventReasonInput = '';
$eventNoteInput = '';
$eventTimeModeInput =
    $session && $session['ended_epoch'] === null
        ? 'now'
        : 'specified';

$eventLocalTimeInput = '';

if (!empty($eventFeedback['error'])) {
    $eventActionInput = $eventFeedback['action'];
    $eventReasonInput = $eventFeedback['reason'];
    $eventNoteInput = $eventFeedback['note'];
    $eventTimeModeInput =
        $eventFeedback['time_mode'] ?? $eventTimeModeInput;

    $eventLocalTimeInput =
        $eventFeedback['local_time'] ?? '';
}

$PAGE_TITLE = $session
    ? 'Judge decisions — Session #' . $session['id']
    : 'Weather Session Not Found';

include('Common/Templates/head.php');

if (!$session) {
    echo '<p>Weather session not found.</p>';
    echo '<p><a href="Sessions.php">'
        . '&larr; Back to weather sessions</a></p>';

    include('Common/Templates/tail.php');
    exit;
}

$events = resultspack_weather_get_events($session['id']);

$actionLabels = array(
    'delay' => 'Delay',
    'suspend' => 'Suspend',
    'resume' => 'Resume',
    'abandon' => 'Abandon',
);

$reasonLabels = array(
    '' => 'Not specified',
    'wind' => 'Wind',
    'lightning' => 'Lightning',
    'rain' => 'Rain',
    'heat' => 'Heat',
    'cold' => 'Cold',
    'visibility' => 'Visibility',
    'field_conditions' => 'Field conditions',
    'equipment' => 'Equipment',
    'other' => 'Other',
);

$backLink = '<p><a href="SessionView.php?session_id='
    . (int) $session['id']
    . '">&larr; Back to weather session</a></p>';

echo $backLink;

echo '<table class="Tabella freeWidth">';
echo '<tr><th class="Main" colspan="2">'
    . 'Judge decisions and weather events — Session '
    . (int) $session['id']
    . '</th></tr>';

echo '<tr><td class="Bold">Session status</td><td>'
    . ($session['ended_epoch'] === null ? 'Open' : 'Completed')
    . '</td></tr>';

echo '<tr><td class="Bold">Timezone</td><td>'
    . htmlspecialchars($session['timezone'], ENT_QUOTES, 'UTF-8')
    . '</td></tr>';

echo '<tr><td class="Bold">Events recorded</td><td>'
    . count($events)
    . '</td></tr>';

echo '</table>';

echo '<div style="overflow-x:auto;max-width:100%;">';
echo '<table class="Tabella freeWidth">';

echo '<tr>';
echo '<th class="Title" scope="col">Event time</th>';
echo '<th class="Title" scope="col">Recorded at (as stored)</th>';
echo '<th class="Title" scope="col">Action</th>';
echo '<th class="Title" scope="col">Reason</th>';
echo '<th class="Title" scope="col">Notes</th>';
echo '<th class="Title" scope="col">Session window</th>';
echo '</tr>';

if (!$events) {
    echo '<tr><td colspan="6">'
        . 'No judge decisions or weather events have been recorded for this session.'
        . '</td></tr>';
} else {
    foreach ($events as $event) {
        $timestamp = (int) $event['timestamp'];

        $outsideWindow =
            $timestamp < (int) $session['started_epoch']
            || (
                $session['ended_epoch'] !== null
                && $timestamp > (int) $session['ended_epoch']
            );

        $actionLabel = $actionLabels[$event['action']]
            ?? $event['action'];

        $reasonLabel = $reasonLabels[$event['reason']]
            ?? $event['reason'];

        echo '<tr>';

        echo '<td style="white-space:nowrap;">'
            . htmlspecialchars(
                resultspack_weather_format_timestamp(
                    $timestamp,
                    $session['timezone'],
                    'd/m/Y H:i:s P'
                ),
                ENT_QUOTES,
                'UTF-8'
            )
            . '</td>';

        $recordedAt = trim((string) ($event['created_at'] ?? ''));

        echo '<td style="white-space:nowrap;">'
            . htmlspecialchars(
                $recordedAt !== '' && $recordedAt !== '0000-00-00 00:00:00'
                    ? $recordedAt
                    : 'Not recorded',
                ENT_QUOTES,
                'UTF-8'
            )
            . '</td>';

        echo '<td>'
            . htmlspecialchars($actionLabel, ENT_QUOTES, 'UTF-8')
            . '</td>';

        echo '<td>'
            . htmlspecialchars($reasonLabel, ENT_QUOTES, 'UTF-8')
            . '</td>';

        echo '<td style="min-width:220px;overflow-wrap:anywhere;">'
            . (
                $event['note'] !== ''
                    ? nl2br(
                        htmlspecialchars(
                            $event['note'],
                            ENT_QUOTES,
                            'UTF-8'
                        )
                    )
                    : '—'
            )
            . '</td>';

        echo '<td>'
            . ($outsideWindow ? 'Outside current window' : 'Within window')
            . '</td>';

        echo '</tr>';
    }
}

echo '</table>';
echo '</div>';

echo '<p style="max-width:1000px;">'
    . 'Event time shows when the decision happened, in the session timezone. '
    . 'Recorded at shows when the entry was originally saved, '
    . 'in YYYY-MM-DD HH:MM:SS format. '
    . 'New ArcheryWeather entries use UTC; older imported entries '
    . 'may use their original server timezone. '
    . 'Importing an entry preserves its original recorded timestamp.'
    . '</p>';

{
    echo '<br>';

    echo '<form id="session-event-form" method="post" '
        . 'action="SessionEventSave.php">';

    echo '<input type="hidden" name="session_id" value="'
        . (int) $session['id']
        . '">';

    echo '<input type="hidden" name="form_token" value="'
        . htmlspecialchars(
            $_SESSION['archeryweather_event_tokens'][$sessionId],
            ENT_QUOTES,
            'UTF-8'
        )
        . '">';

    echo '<table class="Tabella freeWidth">';

    echo '<tr><th class="Main" colspan="2">'
        . 'Record a judge decision'
        . '</th></tr>';

    echo '<tr><td colspan="2">'
        . 'Choose when the decision happened. '
        . 'Its entry time is recorded separately. '
        . 'Recording a decision does not change the session’s start or end.'
        . '</td></tr>';

    echo '<tr><td><label for="event_time_mode">'
        . 'Decision time</label></td><td>';

    echo '<select id="event_time_mode" name="event_time_mode">';

    if ($session['ended_epoch'] === null) {
        echo '<option value="now"'
            . ($eventTimeModeInput === 'now' ? ' selected' : '')
            . '>Now — when submitted</option>';
    }

    echo '<option value="specified"'
        . (
            $eventTimeModeInput === 'specified'
            || $session['ended_epoch'] !== null
                ? ' selected'
                : ''
        )
        . '>Specify event time</option>';

    echo '</select></td></tr>';

    echo '<tr id="event-local-time-row">'
        . '<td><label for="event_local_time">'
        . 'Event date and time</label></td><td>';

    echo '<input type="datetime-local" '
        . 'id="event_local_time" name="event_local_time" step="1" '
        . 'value="'
        . htmlspecialchars($eventLocalTimeInput, ENT_QUOTES, 'UTF-8')
        . '">';

    echo '<p>Use the session timezone: <strong>'
        . htmlspecialchars($session['timezone'], ENT_QUOTES, 'UTF-8')
        . '</strong>.</p>';

    echo '<p>Session started: '
        . htmlspecialchars(
            resultspack_weather_format_timestamp(
                $session['started_epoch'],
                $session['timezone'],
                'd/m/Y H:i:s P'
            ),
            ENT_QUOTES,
            'UTF-8'
        );

    if ($session['ended_epoch'] !== null) {
        echo '<br>Session ended: '
            . htmlspecialchars(
                resultspack_weather_format_timestamp(
                    $session['ended_epoch'],
                    $session['timezone'],
                    'd/m/Y H:i:s P'
                ),
                ENT_QUOTES,
                'UTF-8'
            );
    }

    echo '</p></td></tr>';

    echo '<tr>';
    echo '<td><label for="event_action">Action</label></td>';
    echo '<td><select id="event_action" name="event_action" required>';
    echo '<option value="">Choose an action...</option>';

    foreach ($actionLabels as $value => $label) {
        echo '<option value="'
            . htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
            . '"'
            . ($eventActionInput === $value ? ' selected' : '')
            . '>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</option>';
    }

    echo '</select></td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td><label for="event_reason">Reason</label></td>';
    echo '<td><select id="event_reason" name="event_reason">';

    foreach ($reasonLabels as $value => $label) {
        echo '<option value="'
            . htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
            . '"'
            . ($eventReasonInput === $value ? ' selected' : '')
            . '>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</option>';
    }

    echo '</select></td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td><label for="event_note">Notes</label></td>';
    echo '<td><textarea '
        . 'id="event_note" name="event_note" '
        . 'rows="4" cols="55" maxlength="1000" '
        . 'style="max-width:100%;" '
        . 'placeholder="Optional details about the decision">'
        . htmlspecialchars($eventNoteInput, ENT_QUOTES, 'UTF-8')
        . '</textarea>';

    echo '<p>Maximum 1000 characters.</p>';
    echo '</td>';
    echo '</tr>';

    echo '<tr><td colspan="2">';
    echo '<button type="submit">'
        . 'Record decision'
        . '</button>';
    echo '</td></tr>';

    echo '</table>';
    echo '</form>';
}

echo <<<'HTML'
<p id="event-save-progress" role="status" hidden>
    Recording the decision…
</p>

<script>
(() => {
    const form = document.getElementById('session-event-form');

    if (!form) {
        return;
    }

    const button = form.querySelector('button[type="submit"]');
    const progress = document.getElementById('event-save-progress');

    const timeMode = document.getElementById('event_time_mode');
    const timeInput = document.getElementById('event_local_time');
    const timeRow = document.getElementById('event-local-time-row');

    function updateTimeChoice() {
        const specified = timeMode.value === 'specified';
        timeRow.hidden = !specified;
        timeInput.disabled = !specified;
        timeInput.required = specified;
    }

    timeMode.addEventListener('change', updateTimeChoice);
    window.addEventListener('pageshow', updateTimeChoice);
    updateTimeChoice();

    form.addEventListener('submit', (event) => {
        if (form.dataset.saving === 'yes') {
            event.preventDefault();
            return;
        }

        form.dataset.saving = 'yes';
        button.disabled = true;
        button.textContent = 'Recording…';
        progress.hidden = false;
    });

    window.addEventListener('pageshow', () => {
        delete form.dataset.saving;
        button.disabled = false;
        button.textContent = 'Record decision';
        progress.hidden = true;
    });
})();
</script>
HTML;

if ($eventFeedback) {
    $colour = !empty($eventFeedback['error'])
        ? '#a12622'
        : '#245b2a';

    echo '<div id="event-feedback" tabindex="-1" role="status" '
        . 'style="padding:12px;margin:12px 0;border:2px solid '
        . $colour . ';color:' . $colour . ';">'
        . htmlspecialchars(
            $eventFeedback['message'],
            ENT_QUOTES,
            'UTF-8'
        )
        . '</div>';

    echo '<script>'
        . 'document.getElementById("event-feedback").focus();'
        . 'document.getElementById("event-feedback").scrollIntoView({block:"center"});'
        . '</script>';
}

//echo $backLink;

include('Common/Templates/tail.php');