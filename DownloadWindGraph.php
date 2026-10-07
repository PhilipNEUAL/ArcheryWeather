<?php

// the original wind-download URL.
$sessionInput = $_GET['session_id'] ?? '';

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

header(
    'Location: DownloadGraph.php?session_id='
    . $sessionId . '&graph=wind',
    true,
    302
);
exit;