<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/freshness.php');

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

if (
    $stationId === false
    || !in_array($stationId, $allowedStations, true)
) {
    http_response_code(403);
    echo json_encode(array(
        'error' => 'Reload the creation page and select an available station.',
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