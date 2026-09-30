<?php

// Preserve links created for the newer viewer.
$sessionInput = $_GET['session'] ?? '';

$sessionId = is_string($sessionInput)
    ? filter_var(
        $sessionInput,
        FILTER_VALIDATE_INT,
        array('options' => array('min_range' => 1))
    )
    : false;

if ($sessionId === false) {
    http_response_code(400);
    header('Content-Type: text/html; charset=UTF-8');

    echo '<h1>Invalid weather session</h1>';
    echo '<p>Please choose a session from ';
    echo '<a href="Sessions.php">the weather session list</a>.</p>';
    exit;
}

header(
    'Location: SessionView.php?session_id=' . $sessionId,
    true,
    302
);
exit;