<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/config.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Please use the judge-decision form.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$input = $_POST['session_id'] ?? '';

$sessionId = is_string($input)
    ? filter_var(
        $input,
        FILTER_VALIDATE_INT,
        array('options' => array('min_range' => 1))
    )
    : false;

if ($sessionId === false) {
    http_response_code(400);
    exit('Please select a valid weather session.');
}

$token = $_POST['form_token'] ?? '';
$expectedToken =
    $_SESSION['archeryweather_event_tokens'][$sessionId] ?? '';

if (
    !is_string($token)
    || !is_string($expectedToken)
    || $expectedToken === ''
    || !hash_equals($expectedToken, $token)
) {
    http_response_code(403);
    exit(
        'This form has expired or was already submitted. '
        . 'Reload the events page and check its history before trying again.'
    );
}

$action = $_POST['event_action'] ?? '';
$reason = $_POST['event_reason'] ?? '';
$note = $_POST['event_note'] ?? '';

$feedback = array(
    'error' => true,
    'message' => '',
    'action' => is_string($action) ? $action : '',
    'reason' => is_string($reason) ? $reason : '',
    'note' => is_string($note) ? $note : '',
);

$transactionStarted = false;
$connection = null;

try {
    if (!in_array($action, array('delay', 'suspend', 'resume', 'abandon'), true)) {
        throw new InvalidArgumentException('Please choose a valid action.');
    }

    $allowedReasons = array(
        '',
        'wind',
        'lightning',
        'rain',
        'heat',
        'cold',
        'visibility',
        'field_conditions',
        'equipment',
        'other',
    );

    if (!in_array($reason, $allowedReasons, true)) {
        throw new InvalidArgumentException('Please choose a valid reason.');
    }

    if (!is_string($note)) {
        throw new InvalidArgumentException('Please enter notes as text.');
    }

    $note = trim($note);

    if (preg_match('/\A.{0,1000}\z/us', $note) !== 1) {
        throw new InvalidArgumentException(
            'Notes must contain valid text of no more than 1000 characters.'
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
            throw new RuntimeException('Event database operation failed.');
        }

        return $result;
    };

    if (!mysqli_begin_transaction($connection)) {
        throw new RuntimeException('Could not start event transaction.');
    }

    $transactionStarted = true;

    // Prevent the session being ended between our check and the insert.
    $result = $query(
        "SELECT CrwsStartedEpoch, CrwsEndedEpoch "
        . "FROM CustomArcheryWeatherSessions "
        . "WHERE CrwsId=" . $sessionId . " FOR UPDATE"
    );

    $session = mysqli_fetch_assoc($result);
    mysqli_free_result($result);

    if (!$session) {
        throw new InvalidArgumentException('Weather session not found.');
    }

    if ($session['CrwsEndedEpoch'] !== null) {
        throw new InvalidArgumentException(
            'This session has ended. No decision was recorded.'
        );
    }

    $timestamp = time();

    if ($timestamp < (int) $session['CrwsStartedEpoch']) {
        throw new InvalidArgumentException(
            'The server time is before the session start. '
            . 'Check the server clock; no decision was recorded.'
        );
    }

    $actionSql = "'" . mysqli_real_escape_string($connection, $action) . "'";
    $reasonSql = "'" . mysqli_real_escape_string($connection, $reason) . "'";
    $noteSql = "'" . mysqli_real_escape_string($connection, $note) . "'";

    $query(
        "INSERT INTO CustomArcheryWeatherEvents ("
        . "CrweSession, CrweTimestamp, CrweAction, "
        . "CrweReason, CrweNote, CrweCreated"
        . ") VALUES ("
        . $sessionId . ", " . $timestamp . ", "
        . $actionSql . ", " . $reasonSql . ", " . $noteSql
        . ", UTC_TIMESTAMP())"
    );

    if (!mysqli_commit($connection)) {
        throw new RuntimeException('Could not commit event transaction.');
    }

    $transactionStarted = false;

    // A successful submission consumes this session's form token.
    unset($_SESSION['archeryweather_event_tokens'][$sessionId]);

    $feedback = array(
        'error' => false,
        'message' => 'Decision recorded: ' . ucfirst($action)
            . '. The weather session remains open.',
    );
} catch (Throwable $exception) {
    if ($transactionStarted) {
        try {
            mysqli_rollback($connection);
        } catch (Throwable $rollbackException) {
            // Report the original failure below.
        }
    }

    $feedback['message'] =
        $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'The decision could not be confirmed as saved. '
                . 'Check the event history before trying again.';
}

$_SESSION['archeryweather_event_feedback'][$sessionId] = $feedback;

session_write_close();

header(
    'Location: SessionEvents.php?session_id=' . $sessionId,
    true,
    303
);
exit;