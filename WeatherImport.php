<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/transfer.php');
require_once(__DIR__ . '/Lib/import.php');
require_once(__DIR__ . '/Lib/import_save.php');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $receipt = array(
        'error' => '',
        'outcome' => null,
    );

    try {
        $pending = $_SESSION['archeryweather_pending_import'] ?? null;
        $submittedToken = $_POST['batch_token'] ?? null;

        if (
            !is_array($pending)
            || !is_string($submittedToken)
            || !isset($pending['token'])
            || !is_string($pending['token'])
            || !hash_equals($pending['token'], $submittedToken)
        ) {
            throw new InvalidArgumentException(
                'This preview has expired, was replaced, or has already been submitted. '
                . 'Return to Import / Export and preview the file again.'
            );
        }

        if (
            !isset($pending['created'])
            || !is_int($pending['created'])
            || $pending['created'] > time()
            || time() - $pending['created'] > 1800
        ) {
            unset($_SESSION['archeryweather_pending_import']);

            throw new InvalidArgumentException(
                'This preview is more than 30 minutes old. Please preview the file again.'
            );
        }

        if (
            empty($pending['packages'])
            || !is_array($pending['packages'])
        ) {
            throw new InvalidArgumentException(
                'The retained upload is unavailable. Please preview the file again.'
            );
        }

        $tournamentIds = $_POST['tournament_ids'] ?? array();

        if (!is_array($tournamentIds) || count($tournamentIds) > 20) {
            throw new InvalidArgumentException(
                'The competition selections are invalid.'
            );
        }

        foreach ($tournamentIds as $index => $value) {
            if (
                !is_int($index)
                || !array_key_exists($index, $pending['packages'])
                || !is_string($value)
                || filter_var(
                    $value,
                    FILTER_VALIDATE_INT,
                    array('options' => array('min_range' => 1))
                ) === false
            ) {
                throw new InvalidArgumentException(
                    'Choose a valid local competition for each new session.'
                );
            }
        }

        // Consume and persist the token before starting database work.
        // A repeated submission cannot reuse this batch.
        unset($_SESSION['archeryweather_pending_import']);

        if (!session_write_close()) {
            throw new RuntimeException('Could not save the submission state.');
        }

        $receipt['outcome'] =
            resultspack_weather_import_save_batch(
                $pending['packages'],
                $tournamentIds
            );
    } catch (InvalidArgumentException $e) {
        $receipt['error'] = $e->getMessage();
    } catch (Throwable $e) {
        $receipt['error'] =
            'The import could not be confirmed. Check the sessions list '
            . 'before uploading the file again.';
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $receiptToken = bin2hex(random_bytes(16));

    $_SESSION['archeryweather_import_receipt'] = array(
        'token' => $receiptToken,
        'receipt' => $receipt,
    );

    session_write_close();

    // Refreshing the result page will not repeat the import.
    header(
        'Location: WeatherImport.php?result=' . $receiptToken,
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

$savedReceipt = $_SESSION['archeryweather_import_receipt'] ?? null;
$resultToken = $_GET['result'] ?? null;

$receipt = null;

if (
    is_array($savedReceipt)
    && is_string($resultToken)
    && isset($savedReceipt['token'])
    && is_string($savedReceipt['token'])
    && hash_equals($savedReceipt['token'], $resultToken)
) {
    $receipt = $savedReceipt['receipt'];
}

session_write_close();

$escape = function ($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
};

$PAGE_TITLE = 'Weather Import Result';

include('Common/Templates/head.php');

echo '<p><a href="WeatherTransfer.php">'
    . '&larr; Back to Import / Export</a></p>';

echo '<p><a href="Sessions.php">View weather sessions &nearr;</a></p>';

if ($receipt === null) {
    echo '<p>No current import result is available. '
        . 'Start from the Import / Export page.</p>';
} elseif ($receipt['error'] !== '') {
    echo '<div role="alert" style="color:#a12622;'
        . 'border:2px solid #a12622;padding:12px;">'
        . $escape($receipt['error'])
        . '</div>';
} else {
    $outcome = $receipt['outcome'];

    echo '<p><strong>'
        . count($outcome['imported']) . ' new session(s) imported; '
        . count($outcome['skipped']) . ' package(s) skipped.'
        . '</strong></p>';

    foreach ($outcome['imported'] as $item) {
        $id = (int) $item['local_id'];

        echo '<p><a href="SessionView.php?session_id=' . $id . '">'
            . 'Open imported session #' . $id . ' &nearr;</a>'
            . ' — '
            . (int) $item['observations'] . ' observations, '
            . (int) $item['events'] . ' judge events, '
            . (int) $item['timing_corrections'] . ' timing corrections, '
            . (int) $item['direction_corrections'] . ' direction corrections.'
            . '</p>';
    }

    foreach ($outcome['skipped'] as $item) {
        $reason = $item['reason'] === 'existing'
            ? 'an existing session has the same fingerprint'
            : 'another session at this station overlaps its times';

        echo '<p>Package ' . ((int) $item['package_index'] + 1)
            . ' skipped because ' . $escape($reason) . '. ';

        foreach ($item['local_ids'] as $id) {
            $id = (int) $id;

            echo '<a href="SessionView.php?session_id=' . $id . '">'
                . 'Session #' . $id . ' &nearr;</a> ';
        }

        echo '</p>';
    }

    echo '<p>Existing sessions were not modified.</p>';
}

include('Common/Templates/tail.php');