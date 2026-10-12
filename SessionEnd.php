<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/config.php');
require_once(__DIR__ . '/Lib/schema.php');
require_once(__DIR__ . '/Lib/sessions.php');
require_once(__DIR__ . '/Lib/observations.php');
require_once(__DIR__ . '/Lib/tempest.php');
require_once(__DIR__ . '/Lib/collection.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Please use the End session button on the session details page.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$submittedToken = $_POST['form_token'] ?? '';
$expectedToken = $_SESSION['archeryweather_end_token'] ?? '';

if (
    !is_string($submittedToken)
    || !is_string($expectedToken)
    || $expectedToken === ''
    || !hash_equals($expectedToken, $submittedToken)
) {
    http_response_code(403);
    exit('This form has expired. Reload the session details page and try again.');
}

$sessionInput = $_POST['session_id'] ?? '';

$sessionId = is_string($sessionInput)
    ? filter_var(
        $sessionInput,
        FILTER_VALIDATE_INT,
        array('options' => array('min_range' => 1))
    )
    : false;

if ($sessionId === false) {
    http_response_code(400);
    exit('Please select a valid weather session.');
}

$session = resultspack_weather_get_session($sessionId);

if (!$session) {
    http_response_code(404);
    exit('Weather session not found.');
}

// Repeated submissions must not change an existing end time.
$endedNow = false;

if ($session['ended_epoch'] === null) {
    $endedEpoch = time();

    if ($endedEpoch < (int) $session['started_epoch']) {
        http_response_code(409);
        exit('The current time is before the session start. Check the server clock.');
    }

    // Save the end time before attempting any network request.
    $result = safe_w_sql(
        "UPDATE CustomArcheryWeatherSessions "
        . "SET CrwsEndedEpoch=" . $endedEpoch
        . " WHERE CrwsId=" . (int) $sessionId
        . " AND CrwsEndedEpoch IS NULL"
    );

    if ($result === false) {
        http_response_code(500);
        exit('The session could not be ended. Reload its details and try again.');
    }

    // Only the request that actually ended the session triggers retrieval.
    global $WRIT_CON;
    $endedNow = mysqli_affected_rows($WRIT_CON) === 1;
}

if ($endedNow) {
    // Keep a useful message even if retrieval is interrupted.
    $_SESSION['archeryweather_collection_messages'][$sessionId] =
        'Session ended. If no retrieval result appears, use '
        . 'Retrieve observations from Tempest to retry.';

    session_write_close();

    $message = 'Session ended. '
        . resultspack_weather_collection_message($sessionId);

    session_start();

    $_SESSION['archeryweather_collection_messages'][$sessionId] =
        $message;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header(
    'Location: SessionDetails.php?session_id=' . (int) $sessionId,
    true,
    303
);
exit;