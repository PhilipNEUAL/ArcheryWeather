<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/cleanup_save.php');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $receipt = array(
        'error' => '',
        'deleted' => null,
    );

    try {
        $pending = $_SESSION['archeryweather_pending_cleanup'] ?? null;
        $submittedToken = $_POST['confirmation_token'] ?? null;

        if (
            !is_array($pending)
            || !is_string($submittedToken)
            || !isset($pending['token'])
            || !is_string($pending['token'])
            || !hash_equals($pending['token'], $submittedToken)
        ) {
            throw new InvalidArgumentException(
                'This review has expired, was replaced, or has already been submitted. '
                . 'Return to session cleanup and review your selection again.'
            );
        }

        if (
            !isset($pending['created'])
            || !is_int($pending['created'])
            || $pending['created'] > time()
            || time() - $pending['created'] > 1800
        ) {
            unset($_SESSION['archeryweather_pending_cleanup']);

            throw new InvalidArgumentException(
                'This review is more than 30 minutes old. Review your selection again.'
            );
        }

        $confirmation = $_POST['confirmation_text'] ?? null;

        if (!is_string($confirmation) || $confirmation !== 'DELETE') {
            throw new InvalidArgumentException(
                'Deletion was not confirmed. Nothing has been deleted. '
                . 'Review your selection again and type DELETE exactly.'
            );
        }

        if (
            empty($pending['sessions'])
            || !is_array($pending['sessions'])
        ) {
            throw new InvalidArgumentException(
                'The retained selection is unavailable. Nothing has been deleted.'
            );
        }

        // Consume the confirmation before starting database work.
        // Repeated submissions cannot reuse this selection.
        unset($_SESSION['archeryweather_pending_cleanup']);

        if (!session_write_close()) {
            throw new RuntimeException('Could not save confirmation state.');
        }

        $receipt['deleted'] =
            resultspack_weather_cleanup_delete_batch($pending['sessions']);
    } catch (InvalidArgumentException $e) {
        $receipt['error'] = $e->getMessage();
    } catch (Throwable $e) {
        $receipt['error'] =
            'Cleanup could not be confirmed. Check the sessions list before retrying.';
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $receiptToken = bin2hex(random_bytes(16));

    $_SESSION['archeryweather_cleanup_receipt'] = array(
        'token' => $receiptToken,
        'receipt' => $receipt,
    );

    session_write_close();

    header(
        'Location: SessionDelete.php?result=' . $receiptToken,
        true,
        303
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$saved = $_SESSION['archeryweather_cleanup_receipt'] ?? null;
$resultToken = $_GET['result'] ?? null;
$receipt = null;

if (
    is_array($saved)
    && is_string($resultToken)
    && isset($saved['token'])
    && is_string($saved['token'])
    && hash_equals($saved['token'], $resultToken)
) {
    $receipt = $saved['receipt'];
}

session_write_close();

$escape = function ($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
};

$PAGE_TITLE = 'Weather Session Cleanup Result';

include('Common/Templates/head.php');

echo '<p><a href="SessionCleanup.php">'
    . '&larr; Back to session cleanup</a></p>';

echo '<p><a href="Sessions.php">View weather sessions &nearr;</a></p>';

if ($receipt === null) {
    echo '<p>No current cleanup result is available. '
        . 'Start from the session cleanup page.</p>';
} elseif ($receipt['error'] !== '') {
    echo '<div role="alert" style="color:#a12622;'
        . 'border:2px solid #a12622;padding:12px;">'
        . $escape($receipt['error']) . '</div>';
} else {
    $deleted = $receipt['deleted'];

    echo '<p><strong>'
        . (int) $deleted['sessions']
        . ' session(s) deleted.</strong></p>';

    echo '<table class="Tabella freeWidth">';
    echo '<tr><th class="Main" colspan="2">Removed records</th></tr>';

    $labels = array(
        'observations' => 'Observations',
        'events' => 'Judge events',
        'timing_corrections' => 'Timing corrections',
        'direction_corrections' => 'Direction corrections',
    );

    foreach ($labels as $key => $label) {
        echo '<tr><th>' . $escape($label) . '</th>'
            . '<td>' . (int) $deleted[$key] . '</td></tr>';
    }

    echo '</table>';

    echo '<p>The selected sessions and their linked records were removed. '
        . 'IANSEO competitions were not deleted.</p>';
}

include('Common/Templates/tail.php');