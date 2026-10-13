<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/freshness.php');
require_once(__DIR__ . '/Lib/schema.php');
require_once(__DIR__ . '/Lib/sessions.php');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(array('error' => 'GET request required.'));
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$allowedStations = $_SESSION['archeryweather_station_ids'] ?? array();

// Release the session lock before contacting Tempest.
session_write_close();

$input = $_GET['station_id'] ?? '';

$stationId = is_string($input)
    ? filter_var(
        $input,
        FILTER_VALIDATE_INT,
        array('options' => array('min_range' => 1))
    )
    : false;

$sessionInput = $_GET['session_id'] ?? null;
$stationAllowed = false;

if ($sessionInput !== null) {
    $sessionId = is_string($sessionInput)
        ? filter_var(
            $sessionInput,
            FILTER_VALIDATE_INT,
            array('options' => array('min_range' => 1))
        )
        : false;

    $weatherSession = $sessionId !== false
        ? resultspack_weather_get_session($sessionId)
        : null;

    if (
        $weatherSession
        && $stationId !== false
        && (int) $weatherSession['station_id'] === $stationId
    ) {
        if ($weatherSession['ended_epoch'] !== null) {
            http_response_code(409);
            echo json_encode(array(
                'session_closed' => true,
                'error' => 'This session has ended.',
            ));
            exit;
        }

        $stationAllowed = true;
    }
} else {
    // The creation page supplies its available-station list.
    $stationAllowed = $stationId !== false
        && in_array($stationId, $allowedStations, true);
}

if (!$stationAllowed) {
    http_response_code(403);
    echo json_encode(array(
        'error' => 'Reload the page and check the selected station or session.',
    ));
    exit;
}

try {
    $status = resultspack_weather_check_station_freshness($stationId);
    echo json_encode($status);
} catch (Exception $exception) {
    http_response_code(502);
    echo json_encode(array(
        'error' => 'The latest station check failed. Please try again.',
    ));
}