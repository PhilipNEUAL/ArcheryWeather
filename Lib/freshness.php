<?php

// yes i could have picked a different name. no i'm not changing it.

require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/tempest.php');

// Assess the age of an observation using the reporting interval.
function resultspack_weather_freshness(
    $timestamp,
    $reportInterval = 1
) {
    $checkedAt = time();

    $result = array(
        'status' => 'unknown',
        'label' => 'UNKNOWN',
        'timestamp' => null,
        'checked_at' => $checkedAt,
        'age_seconds' => null,
        'message' => 'Observation time unavailable.',
    );

    $timestamp = filter_var(
        $timestamp,
        FILTER_VALIDATE_INT,
        array('options' => array('min_range' => 1))
    );

    if ($timestamp === false) {
        return $result;
    }

    $result['timestamp'] = $timestamp;

    if ($timestamp > $checkedAt + 60) {
        $result['message'] =
            'The observation time is ahead of the server clock. '
            . 'Check the server date and time.';

        return $result;
    }

    if (
        !is_numeric($reportInterval)
        || !is_finite((float) $reportInterval)
        || (float) $reportInterval <= 0
    ) {
        $reportInterval = 1;
    }

    $intervalSeconds = (float) $reportInterval * 60;
    $ageSeconds = max(0, $checkedAt - $timestamp);

    $result['age_seconds'] = $ageSeconds;

    if ($ageSeconds <= $intervalSeconds * 2) {
        $result['status'] = 'live';
        $result['label'] = 'LIVE';
        $result['message'] = 'Recent observations are available.';
    } elseif ($ageSeconds <= $intervalSeconds * 5) {
        $result['status'] = 'delayed';
        $result['label'] = 'DELAYED';
        $result['message'] = 'The latest observation is older than expected.';
    } else {
        $result['status'] = 'stale';
        $result['label'] = 'STALE';
        $result['message'] = 'No recent observation is available.';
    }

    return $result;
}

// Fetch the latest reading from Tempest's online service and assess its age.
function resultspack_weather_check_station_freshness($stationId)
{
    $unknown = resultspack_weather_freshness(null);

    $stationId = filter_var(
        $stationId,
        FILTER_VALIDATE_INT,
        array('options' => array('min_range' => 1))
    );

    if ($stationId === false) {
        $unknown['message'] = 'Please select a weather station.';
        return $unknown;
    }

    $response = resultspack_weather_fetch_latest_observation(
        $stationId
    );

    $unknown['checked_at'] = time();

    if (empty($response['ok'])) {
        $unknown['message'] =
            'The latest check failed. Could not contact '
            . 'Tempest’s online service successfully.';

        return $unknown;
    }

    $data = $response['data'] ?? null;

    if (
        !is_array($data)
        || !isset($data['status']['status_code'])
        || !is_numeric($data['status']['status_code'])
        || (int) $data['status']['status_code'] !== 0
    ) {
        $unknown['message'] =
            'The latest check failed. Tempest did not return '
            . 'a successful observation response.';

        return $unknown;
    }

    $rows = $data['obs'] ?? null;
    $fields = $data['ob_fields'] ?? null;

    if (is_array($rows) && !$rows) {
        $unknown['message'] =
            'Tempest returned no observations for this station.';

        return $unknown;
    }

    if (
        !is_array($rows)
        || !is_array($fields)
        || !$fields
    ) {
        $unknown['message'] =
            'The latest check failed. Unexpected observation format.';

        return $unknown;
    }

    foreach ($fields as $field) {
        if (!is_string($field) || $field === '') {
            $unknown['message'] =
                'The latest check failed. Invalid observation field names.';

            return $unknown;
        }
    }

    if (
        count(array_unique($fields)) !== count($fields)
        || !in_array('timestamp', $fields, true)
    ) {
        $unknown['message'] =
            'The latest check failed. Observation fields are incomplete.';

        return $unknown;
    }

    $latest = null;
    $latestTimestamp = 0;

    foreach ($rows as $values) {
        if (!is_array($values) || count($values) !== count($fields)) {
            continue;
        }

        $observation = array_combine(
            $fields,
            array_values($values)
        );

        $timestamp = filter_var(
            $observation['timestamp'] ?? null,
            FILTER_VALIDATE_INT,
            array('options' => array('min_range' => 1))
        );

        if ($timestamp !== false && $timestamp > $latestTimestamp) {
            $latest = $observation;
            $latestTimestamp = $timestamp;
        }
    }

    if ($latest === null) {
        $unknown['message'] =
            'The latest check failed. No valid observation timestamp was returned.';

        return $unknown;
    }

    return resultspack_weather_freshness(
        $latestTimestamp,
        $latest['report_interval'] ?? 1
    );
}