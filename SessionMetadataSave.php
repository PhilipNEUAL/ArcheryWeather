<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/metadata.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Use the metadata form on the session details page.');
}

$idInput = $_POST['session_id'] ?? null;

$sessionId = is_string($idInput)
    ? filter_var(
        $idInput,
        FILTER_VALIDATE_INT,
        array('options' => array('min_range' => 1))
    )
    : false;

if ($sessionId === false) {
    http_response_code(400);
    exit('Invalid session ID.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$columns = array(
    'sensor_height' => 'CrwsSensorHeight',
    'forward_offset' => 'CrwsForwardOffset',
    'lateral_offset' => 'CrwsLateralOffset',
    'ground_surface' => 'CrwsGroundSurface',
    'exposure' => 'CrwsExposure',
    'position_notes' => 'CrwsPositionNotes',
);

$inputs = array();
$retainedInputs = array();

foreach ($columns as $field => $column) {
    $inputs[$field] = $_POST[$field] ?? null;

    if (is_string($inputs[$field])) {
        $retainedInputs[$field] = $inputs[$field];
    }
}

$connection = null;
$transactionOpen = false;
$validToken = false;

try {
    $submittedToken = $_POST['form_token'] ?? null;
    $expectedToken =
        $_SESSION['archeryweather_metadata_tokens'][$sessionId] ?? '';

    if (
        !is_string($submittedToken)
        || !is_string($expectedToken)
        || $expectedToken === ''
        || !hash_equals($expectedToken, $submittedToken)
    ) {
        throw new InvalidArgumentException(
            'This form has expired or has already been used. '
            . 'Review the current metadata before trying again.'
        );
    }

    $validToken = true;

    $expectedFingerprint = $_POST['expected_fingerprint'] ?? null;

    if (
        !is_string($expectedFingerprint)
        || preg_match('/\A[a-f0-9]{64}\z/', $expectedFingerprint) !== 1
    ) {
        throw new InvalidArgumentException(
            'The form reference is invalid. Reload the session details page.'
        );
    }

    global $WRIT_CON;

    if (!$WRIT_CON) {
        $WRIT_CON = safe_w_con();
    }

    $connection = $WRIT_CON;

    $query = function ($sql) use ($connection) {
        $result = mysqli_query($connection, $sql);

        if ($result === false) {
            throw new RuntimeException('A metadata database operation failed.');
        }

        return $result;
    };

    if (!mysqli_begin_transaction($connection)) {
        throw new RuntimeException('Could not start metadata transaction.');
    }

    $transactionOpen = true;

    $result = $query(
        'SELECT ' . implode(', ', array_values($columns))
        . ' FROM CustomArcheryWeatherSessions'
        . ' WHERE CrwsId = ' . (int) $sessionId
        . ' FOR UPDATE'
    );

    $row = mysqli_fetch_assoc($result);
    mysqli_free_result($result);

    if (!$row) {
        throw new InvalidArgumentException(
            'This session no longer exists. No metadata was saved.'
        );
    }

    $current = array();

    foreach ($columns as $field => $column) {
        $current[$field] = $row[$column];
    }

    if (
        !hash_equals(
            resultspack_weather_metadata_fingerprint($current),
            $expectedFingerprint
        )
    ) {
        throw new InvalidArgumentException(
            'The station setup changed since you opened this form. '
            . 'Nothing was saved. Your entries are retained below; compare '
            . 'them with the current details table before saving again.'
        );
    }

    $clean = resultspack_weather_metadata_validate($inputs, $current);

    $changed =
        $clean !== resultspack_weather_metadata_snapshot($current);

    if ($changed) {
        $assignments = array();

        foreach ($columns as $field => $column) {
            $value = $clean[$field];

            $sqlValue = $value === null
                ? 'NULL'
                : "'" . mysqli_real_escape_string($connection, $value) . "'";

            $assignments[] = $column . ' = ' . $sqlValue;
        }

        $query(
            'UPDATE CustomArcheryWeatherSessions SET '
            . implode(', ', $assignments)
            . ' WHERE CrwsId = ' . (int) $sessionId
        );

        if (mysqli_affected_rows($connection) !== 1) {
            throw new RuntimeException(
                'The metadata update could not be confirmed.'
            );
        }
    }

    if (!mysqli_commit($connection)) {
        throw new RuntimeException('The metadata commit could not be confirmed.');
    }

    $transactionOpen = false;

    unset($_SESSION['archeryweather_metadata_tokens'][$sessionId]);

    $_SESSION['archeryweather_metadata_feedback'][$sessionId] = array(
        'ok' => true,
        'message' => $changed
            ? 'Station setup metadata saved.'
            : 'Station setup metadata unchanged.',
    );
} catch (Throwable $e) {
    if ($transactionOpen && $connection) {
        try {
            mysqli_rollback($connection);
        } catch (Throwable $rollbackError) {
            // Preserve the original failure.
        }
    }

    $feedback = array(
        'ok' => false,
        'message' => $e instanceof InvalidArgumentException
            ? $e->getMessage()
            : 'The metadata change could not be confirmed. '
                . 'Check the current details table before retrying.',
    );

    if ($validToken) {
        $feedback['inputs'] = $retainedInputs;
    }

    $_SESSION['archeryweather_metadata_feedback'][$sessionId] = $feedback;
}

session_write_close();

header(
    'Location: SessionDetails.php?session_id=' . (int) $sessionId,
    true,
    303
);
exit;