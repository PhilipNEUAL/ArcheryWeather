<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/config.php');
require_once(__DIR__ . '/Lib/schema.php');
require_once(__DIR__ . '/Lib/sessions.php');
require_once(__DIR__ . '/Lib/corrections.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Please use the direction correction form.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$token = $_POST['form_token'] ?? '';
$expectedToken = $_SESSION['archeryweather_direction_token'] ?? '';

if (
    !is_string($token)
    || !is_string($expectedToken)
    || $expectedToken === ''
    || !hash_equals($expectedToken, $token)
) {
    http_response_code(403);
    exit('This form has expired. Reload the direction page and try again.');
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

$session = resultspack_weather_get_session($sessionId);

if (!$session) {
    http_response_code(404);
    exit('Weather session not found.');
}

$bearing = $_POST['verified_bearing'] ?? '';
$verification = $_POST['verification'] ?? '';
$reason = $_POST['correction_reason'] ?? '';

$feedback = array(
    'error' => true,
    'message' => '',
    'bearing' => is_string($bearing) ? $bearing : '',
    'verification' => is_string($verification) ? $verification : '',
    'reason' => is_string($reason) ? $reason : '',
);

try {
    $changed = resultspack_weather_save_direction_correction(
        $sessionId,
        $bearing,
        $verification,
        $reason,
        $_POST['expected_correction'] ?? '',
        $_POST['expected_verification'] ?? '',
        $_POST['expected_audit_id'] ?? ''
    );

    $feedback = array(
        'error' => false,
        'message' => $changed
            ? 'Direction correction saved and recorded in the audit trail. '
                . 'Raw observations and the original shooting bearing were kept unchanged.'
            : 'The correction and verification method were unchanged. '
                . 'No audit entry was added.',
    );
} catch (InvalidArgumentException $exception) {
    $feedback['message'] = $exception->getMessage();
} catch (RuntimeException $exception) {
    $feedback['message'] = $exception->getMessage();
}

$_SESSION['archeryweather_direction_feedback'][$sessionId] =
    $feedback;

session_write_close();

header(
    'Location: DirectionReference.php?session_id=' . $sessionId,
    true,
    303
);
exit;