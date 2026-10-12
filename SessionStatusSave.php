<?php

require_once(__DIR__ . '/Lib/bootstrap.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Use the research-status form on the session details page.');
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

$transactionOpen = false;
$connection = null;

try {
    $submittedToken = $_POST['form_token'] ?? null;
    $expectedToken =
        $_SESSION['archeryweather_status_tokens'][$sessionId] ?? '';

    if (
        !is_string($submittedToken)
        || !is_string($expectedToken)
        || $expectedToken === ''
        || !hash_equals($expectedToken, $submittedToken)
    ) {
        throw new InvalidArgumentException(
            'This form has expired or has already been used. '
            . 'Check the current status before trying again.'
        );
    }

    $newStatus = $_POST['research_status'] ?? null;
    $expectedStatus = $_POST['expected_status'] ?? null;
    $allowed = array('real', 'test', 'excluded');

    if (
        !is_string($newStatus)
        || !in_array($newStatus, $allowed, true)
        || !is_string($expectedStatus)
        || !in_array($expectedStatus, $allowed, true)
    ) {
        throw new InvalidArgumentException(
            'Choose Real, Test or Excluded.'
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
            throw new RuntimeException('A status database operation failed.');
        }

        return $result;
    };

    if (!mysqli_begin_transaction($connection)) {
        throw new RuntimeException('Could not start status transaction.');
    }

    $transactionOpen = true;

    $result = $query(
        'SELECT CrwsResearchStatus FROM CustomArcheryWeatherSessions '
        . 'WHERE CrwsId = ' . (int) $sessionId . ' FOR UPDATE'
    );

    $row = mysqli_fetch_assoc($result);
    mysqli_free_result($result);

    if (!$row) {
        throw new InvalidArgumentException(
            'This session no longer exists. Its status was not changed.'
        );
    }

    $currentStatus = (string) $row['CrwsResearchStatus'];

    if ($currentStatus !== $expectedStatus) {
        throw new InvalidArgumentException(
            'The status has changed since you opened this page. '
            . 'Your change was not saved. Review the current status and try again.'
        );
    }

    $changed = $newStatus !== $currentStatus;

    if (
        $changed
        && $currentStatus === 'real'
        && ($_POST['confirm_unprotect'] ?? null) !== '1'
    ) {
        throw new InvalidArgumentException(
            'Tick the confirmation checkbox before changing a Real session '
            . 'to Test or Excluded. Its status has not changed.'
        );
    }

    if ($changed) {
        // The value has already passed the strict status allowlist.
        $query(
            "UPDATE CustomArcheryWeatherSessions "
            . "SET CrwsResearchStatus = '"
            . mysqli_real_escape_string($connection, $newStatus)
            . "' WHERE CrwsId = " . (int) $sessionId
        );

        if (mysqli_affected_rows($connection) !== 1) {
            throw new RuntimeException('The status update could not be confirmed.');
        }
    }

    if (!mysqli_commit($connection)) {
        throw new RuntimeException('The status commit could not be confirmed.');
    }

    $transactionOpen = false;

    // Invalidate older copies of this form in the same browser session.
    unset($_SESSION['archeryweather_status_tokens'][$sessionId]);

    $_SESSION['archeryweather_status_feedback'][$sessionId] = array(
        'ok' => true,
        'message' => $changed
            ? 'Research status changed to ' . ucfirst($newStatus) . '.'
            : 'Research status unchanged.',
    );
} catch (Throwable $e) {
    if ($transactionOpen && $connection) {
        try {
            mysqli_rollback($connection);
        } catch (Throwable $rollbackError) {
            // Preserve the original failure.
        }
    }

    $_SESSION['archeryweather_status_feedback'][$sessionId] = array(
        'ok' => false,
        'message' => $e instanceof InvalidArgumentException
            ? $e->getMessage()
            : 'The status change could not be confirmed. '
                . 'Check the current status before retrying.',
    );
}

session_write_close();

header(
    'Location: SessionDetails.php?session_id=' . (int) $sessionId,
    true,
    303
);
exit;