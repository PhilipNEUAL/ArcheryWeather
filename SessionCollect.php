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
    exit('Please use the retrieval button on the session details page.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$submittedToken = $_POST['form_token'] ?? '';
$expectedToken = $_SESSION['archeryweather_collect_token'] ?? '';

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

try {
    $result = resultspack_weather_import_session_observations(
        $sessionId
    );
} catch (Exception $exception) {
    $result = array(
        'ok' => false,
        'error' => 'Retrieval did not finish. Some readings may have '
            . 'been saved; retrying is safe.',
    );
}

if (!empty($result['ok'])) {
    $received = (int) $result['received'];
    $added = (int) $result['added'];
    $skipped = (int) $result['skipped'];

    if ($received === 0) {
        $message = 'Tempest returned no observations for this session. '
            . 'Any observations already stored have been kept.';
    } else {
        $message = 'Tempest returned ' . $received . ' readings. '
            . $added . ' new observations added; '
            . $skipped . ' readings skipped. '
            . 'Readings already stored were kept unchanged.';
    }
} else {
    $message = 'Retrieval failed: '
        . ($result['error'] ?? 'An unexpected error occurred.');
}

// Keep messages separate if different sessions are open in different tabs.
$_SESSION['archeryweather_collection_messages'][$sessionId] =
    $message;

session_write_close();

header(
    'Location: SessionDetails.php?session_id=' . $sessionId,
    true,
    303
);
exit;