<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/config.php');
require_once(__DIR__ . '/Lib/schema.php');
require_once(__DIR__ . '/Lib/sessions.php');
require_once(__DIR__ . '/Lib/corrections.php');
require_once(__DIR__ . '/Lib/observations.php');
require_once(__DIR__ . '/Lib/tempest.php');
require_once(__DIR__ . '/Lib/collection.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Please use the timing correction form.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$token = $_POST['form_token'] ?? '';
$expectedToken = $_SESSION['archeryweather_timing_token'] ?? '';

if (
    !is_string($token)
    || !is_string($expectedToken)
    || $expectedToken === ''
    || !hash_equals($expectedToken, $token)
) {
    http_response_code(403);
    exit('This form has expired. Reload the timing page and try again.');
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

$session = resultspack_weather_get_completed_session($sessionId);

if (!$session) {
    http_response_code(409);
    exit('Timing corrections require an existing completed session.');
}

$startLocal = $_POST['started_local'] ?? '';
$endLocal = $_POST['ended_local'] ?? '';
$reason = $_POST['correction_reason'] ?? '';

// Retain ordinary text entries if validation fails.
$feedback = array(
    'error' => true,
    'message' => '',
    'started_local' => is_string($startLocal) ? $startLocal : '',
    'ended_local' => is_string($endLocal) ? $endLocal : '',
    'reason' => is_string($reason) ? $reason : '',
);

// Parse local clock times strictly instead of silently normalising them.
$parseLocal = function ($value, DateTimeZone $timezone) {
    if (
        !is_string($value)
        || !preg_match(
            '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?\z/',
            $value
        )
    ) {
        throw new InvalidArgumentException(
            'Please enter a valid start and end date and time.'
        );
    }

    if (strlen($value) === 16) {
        $value .= ':00';
    }

    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i:s',
        $value,
        $timezone
    );

    if (!$date || $date->format('Y-m-d\TH:i:s') !== $value) {
        throw new InvalidArgumentException(
            'One of the dates is invalid or falls in a clock-change gap.'
        );
    }

    // Detect repeated local times when clocks go backwards.
    // The present form has no UTC-offset selector, so do not guess.
    $timestamp = $date->getTimestamp();
    $transitions = $timezone->getTransitions(
        $timestamp - 172800,
        $timestamp + 172800
    );

    $offsets = array($timezone->getOffset($date));

    if (is_array($transitions)) {
        foreach ($transitions as $transition) {
            $offsets[] = (int) $transition['offset'];
        }
    }

    $wallTime = DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i:s',
        $value,
        new DateTimeZone('UTC')
    )->getTimestamp();

    $matches = array();

    foreach (array_unique($offsets) as $offset) {
        $candidate = $wallTime - $offset;
        $local = (new DateTimeImmutable('@' . $candidate))
            ->setTimezone($timezone);

        if ($local->format('Y-m-d\TH:i:s') === $value) {
            $matches[$candidate] = true;
        }
    }

    if (count($matches) !== 1) {
        throw new InvalidArgumentException(
            'This local time occurs twice during a clock change. '
            . 'The current form cannot distinguish those two occurrences; '
            . 'no correction has been saved.'
        );
    }

    return $timestamp;
};

try {
    try {
        $timezone = new DateTimeZone(
            $session['timezone'] !== ''
                ? $session['timezone']
                : resultspack_weather_timezone()
        );
    } catch (Exception $exception) {
        throw new InvalidArgumentException(
            'The session timezone is invalid. It must be resolved before correcting times.'
        );
    }

    $newStarted = $parseLocal($startLocal, $timezone);
    $newEnded = $parseLocal($endLocal, $timezone);

    $changed = resultspack_weather_save_timing_correction(
        $sessionId,
        $newStarted,
        $newEnded,
        $reason,
        $_POST['expected_started_epoch'] ?? '',
        $_POST['expected_ended_epoch'] ?? ''
    );

    $feedback = array(
        'error' => false,
        'message' => $changed
            ? 'Timing correction saved and recorded in the audit trail.'
            : 'The times were unchanged. No audit entry was added.',
    );
} catch (InvalidArgumentException $exception) {
    $feedback['message'] = $exception->getMessage();
} catch (RuntimeException $exception) {
    $feedback['message'] = $exception->getMessage();
}

$_SESSION['archeryweather_timing_feedback'][$sessionId] = $feedback;
session_write_close();

// The correction has already been committed before contacting Tempest.
if (empty($feedback['error']) && !empty($changed)) {
    $collectionMessage =
        resultspack_weather_collection_message($sessionId);

    session_start();

    $_SESSION['archeryweather_timing_feedback'][$sessionId]['message']
        .= ' ' . $collectionMessage;

    session_write_close();
}

header(
    'Location: SessionTiming.php?session_id=' . $sessionId,
    true,
    303
);
exit;