<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/config.php');
require_once(__DIR__ . '/Lib/helpers.php');
require_once(__DIR__ . '/Lib/cleanup.php');
require_once(__DIR__ . '/Lib/cleanup_save.php');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$error = '';
$selected = array();

$totals = array(
    'observations' => 0,
    'events' => 0,
    'timing_corrections' => 0,
    'direction_corrections' => 0,
);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new InvalidArgumentException(
            'Select sessions on the cleanup page first.'
        );
    }

    $submitted = $_POST['form_token'] ?? null;
    $expected = $_SESSION['archeryweather_cleanup_token'] ?? '';

    if (
        !is_string($submitted)
        || !is_string($expected)
        || $expected === ''
        || !hash_equals($expected, $submitted)
    ) {
        throw new InvalidArgumentException(
            'The selection form has expired. Return to session cleanup.'
        );
    }

    $inputs = $_POST['session_ids'] ?? array();

    if (!is_array($inputs) || !$inputs || count($inputs) > 200) {
        throw new InvalidArgumentException(
            'Select between 1 and 200 sessions.'
        );
    }

    $ids = array();

    foreach ($inputs as $input) {
        $id = is_string($input)
            ? filter_var(
                $input,
                FILTER_VALIDATE_INT,
                array('options' => array('min_range' => 1))
            )
            : false;

        if ($id === false) {
            throw new InvalidArgumentException(
                'The selection contains an invalid session ID.'
            );
        }

        $ids[$id] = $id;
    }

    $eligible = array();

    foreach (resultspack_weather_cleanup_candidates() as $candidate) {
        $eligible[$candidate['id']] = $candidate;
    }

    foreach ($ids as $id) {
        if (!isset($eligible[$id])) {
            throw new InvalidArgumentException(
                'Session #' . $id . ' is no longer eligible for cleanup. '
                . 'Return to the cleanup page and review your selection.'
            );
        }

        $candidate = $eligible[$id];
        $selected[] = $candidate;

        foreach ($totals as $key => $total) {
            $totals[$key] += $candidate[$key];
        }
    }

    $confirmationToken = bin2hex(random_bytes(32));

    // The deletion handler will use this server-side selection.
    $_SESSION['archeryweather_pending_cleanup'] = array(
        'token' => $confirmationToken,
        'created' => time(),
        'sessions' => $selected,
    );
} catch (InvalidArgumentException $e) {
    $error = $e->getMessage();
} catch (Throwable $e) {
    $error = 'The selection could not be reviewed. Nothing has been deleted.';
}

session_write_close();

$escape = function ($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
};

$PAGE_TITLE = 'Review Weather Session Cleanup';

include('Common/Templates/head.php');

echo '<p><a href="SessionCleanup.php">'
    . '&larr; Back to session cleanup</a></p>';

if ($error !== '') {
    echo '<div role="alert" style="color:#a12622;'
        . 'border:2px solid #a12622;padding:12px;">'
        . $escape($error) . '</div>';
} else {
    echo '<h2>Review selected sessions</h2>';

    echo '<p><strong>' . count($selected)
        . ' session(s) selected. Nothing has been deleted.</strong></p>';

    echo '<div style="overflow-x:auto;">';
    echo '<table class="Tabella freeWidth">';

    echo '<tr>'
        . '<th class="Title">Session</th>'
        . '<th class="Title">Station</th>'
        . '<th class="Title">Started</th>'
        . '<th class="Title">Status</th>'
        . '<th class="Title">All observations</th>'
        . '<th class="Title">Judge events</th>'
        . '<th class="Title">Timing corrections</th>'
        . '<th class="Title">Direction corrections</th>'
        . '</tr>';

    foreach ($selected as $candidate) {
        echo '<tr>';

        echo '<td><a href="SessionView.php?session_id='
            . $candidate['id'] . '">#'
            . $candidate['id'] . ' &nearr;</a></td>';

        echo '<td>' . $escape($candidate['station_name']) . '</td>';

        echo '<td>' . $escape(
            resultspack_weather_format_timestamp(
                $candidate['started_epoch'],
                $candidate['timezone'],
                'd/m/Y H:i:s T'
            )
        ) . '</td>';

        echo '<td>' . $escape(ucfirst($candidate['research_status']))
            . '</td>';

        foreach ($totals as $key => $total) {
            echo '<td>' . $candidate[$key] . '</td>';
        }

        echo '</tr>';
    }

    echo '<tr><th colspan="4">Total linked records</th>';

    foreach ($totals as $total) {
        echo '<td><strong>' . $total . '</strong></td>';
    }

    echo '</tr></table></div>';

    echo '<p>Deleting these sessions will also remove all their stored '
        . 'observations, judge events and correction histories. '
        . 'The IANSEO competitions themselves will remain.</p>';

    echo '<p>Keep a JSON export first if you may need these records again. '
        . '<a href="WeatherTransfer.php">Open Import / Export &nearr;</a></p>';

    echo '<form method="post" action="SessionDelete.php">';

    echo '<input type="hidden" name="confirmation_token" value="'
        . $escape($confirmationToken) . '">';

    echo '<p style="color:#a12622"><strong>'
        . 'This will permanently delete '
        . count($selected)
        . ' selected session(s) and all the linked records listed above.'
        . '</strong></p>';

    echo '<p><label for="confirmation-text">'
        . 'Type DELETE to confirm:'
        . '</label> '
        . '<input type="text" id="confirmation-text" '
        . 'name="confirmation_text" required pattern="DELETE" '
        . 'maxlength="6" autocomplete="off" spellcheck="false">'
        . '</p>';

    echo '<p><button type="submit" '
        . 'style="background:#a12622;color:white;padding:8px 14px;">'
        . 'Permanently delete ' . count($selected) . ' session(s)'
        . '</button></p>';

    echo '<p>This confirmation expires after 30 minutes. '
        . 'Reviewing another selection replaces it.</p>';

    echo '</form>';
}

include('Common/Templates/tail.php');