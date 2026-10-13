<?php

// Retrieve and store observations for a weather session.
// Open-session collection must be explicitly requested.
// Existing readings are preserved when retrieved again.
function resultspack_weather_import_session_observations(
    $sessionId,
    $allowOpen = false
) {
    $session = resultspack_weather_get_session($sessionId);

    if (!$session) {
        return array(
            'ok' => false,
            'error' => 'Weather session not found.',
        );
    }

    $isOpen = $session['ended_epoch'] === null;

    if ($isOpen && !$allowOpen) {
        return array(
            'ok' => false,
            'error' => 'Please end the session before retrieving observations.',
        );
    }

    $start = (int) $session['started_epoch'];

    // For an open session, retrieve only as far as the current time.
    $end = $isOpen
        ? time()
        : (int) $session['ended_epoch'];

    // A newly started session may not have an observation window yet.
    if ($isOpen && $start > 0 && $end === $start) {
        return array(
            'ok' => true,
            'received' => 0,
            'added' => 0,
            'skipped' => 0,
        );
    }

    if ($start <= 0 || $end <= $start) {
        return array(
            'ok' => false,
            'error' => 'The session end must be after its start time.',
        );
    }

    $response = resultspack_weather_fetch_observations(
        $session['station_id'],
        $start,
        $end
    );

    if (empty($response['ok'])) {
        return array(
            'ok' => false,
            'error' => 'Could not retrieve observations from Tempest. '
                . 'Check the connection and configuration, then try again.',
        );
    }

    $data = $response['data'] ?? null;

    if (
        !is_array($data)
        || !isset($data['status']['status_code'])
        || !is_numeric($data['status']['status_code'])
        || (int) $data['status']['status_code'] !== 0
    ) {
        return array(
            'ok' => false,
            'error' => 'Tempest did not return a successful observation response.',
        );
    }

    $rows = $data['obs'] ?? null;

    if (!is_array($rows)) {
        return array(
            'ok' => false,
            'error' => 'Tempest returned an unexpected observation format.',
        );
    }

    // An explicitly empty observation array is a valid empty result.
    if (!$rows) {
        return array(
            'ok' => true,
            'received' => 0,
            'added' => 0,
            'skipped' => 0,
        );
    }

    $fields = $data['ob_fields'] ?? null;

    if (!is_array($fields) || !$fields) {
        return array(
            'ok' => false,
            'error' => 'Tempest did not identify the observation fields.',
        );
    }

    foreach ($fields as $field) {
        if (!is_string($field) || $field === '') {
            return array(
                'ok' => false,
                'error' => 'Tempest returned invalid observation field names.',
            );
        }
    }

    if (
        count(array_unique($fields)) !== count($fields)
        || !in_array('timestamp', $fields, true)
    ) {
        return array(
            'ok' => false,
            'error' => 'Tempest returned incomplete or duplicate field names.',
        );
    }

    // Preserve the field mapping and storage units used by ResultsPack.
    // In particular, wind remains stored in mph and displayed elsewhere in m/s.
    $fieldMap = array(
        'report_interval' => 'CrwoReportInterval',
        'wind_lull' => 'CrwoWindLull',
        'wind_avg' => 'CrwoWindAvg',
        'wind_gust' => 'CrwoWindGust',
        'wind_dir' => 'CrwoWindDir',
        'station_pressure' => 'CrwoStationPressure',
        'sea_level_pressure' => 'CrwoSeaLevelPressure',
        'air_temp' => 'CrwoAirTemp',
        'rh' => 'CrwoRh',
        'illuminance' => 'CrwoIlluminance',
        'uv' => 'CrwoUv',
        'solar_radiation' => 'CrwoSolarRadiation',
        'precip_accumulation' => 'CrwoPrecipAccumulation',
        'local_day_precip_accumulation' => 'CrwoLocalDayPrecipAccumulation',
        'precip_type' => 'CrwoPrecipType',
        'strike_count' => 'CrwoStrikeCount',
        'strike_distance' => 'CrwoStrikeDistance',
    );

    if ($allowOpen) {
        $currentSession = resultspack_weather_get_session(
            $session['id']
        );

        if (!$currentSession) {
            return array(
                'ok' => false,
                'error' => 'Weather session no longer exists.',
            );
        }

        if ($currentSession['ended_epoch'] !== null) {
            return array(
                'ok' => false,
                'session_closed' => true,
                'error' => 'This session ended during retrieval.',
            );
        }

        $start = max(
            $start,
            (int) $currentSession['started_epoch']
        );
    }
    
    $before = resultspack_weather_count_observations($session['id']);
    $skipped = 0;

    foreach ($rows as $values) {
        if (!is_array($values) || count($values) !== count($fields)) {
            $skipped++;
            continue;
        }

        $observation = array_combine($fields, array_values($values));

        $timestamp = filter_var(
            $observation['timestamp'] ?? null,
            FILTER_VALIDATE_INT,
            array('options' => array('min_range' => 1))
        );

        // Do not store readings outside this session's recorded window.
        if (
            $timestamp === false
            || $timestamp < $start
            || $timestamp > $end
        ) {
            $skipped++;
            continue;
        }

        $columns = array('CrwoSession', 'CrwoTimestamp');
        $sqlValues = array(
            (string) (int) $session['id'],
            (string) $timestamp,
        );

        foreach ($fieldMap as $field => $column) {
            $value = $observation[$field] ?? null;
            $columns[] = $column;

            if ($value === null) {
                $sqlValues[] = 'NULL';
                continue;
            }

            if (
                !is_numeric($value)
                || !is_finite((float) $value)
            ) {
                $skipped++;
                continue 2;
            }

            $sqlValues[] = number_format((float) $value, 6, '.', '');
        }

        $columns[] = 'CrwoImported';
        $sqlValues[] = StrSafe_DB(gmdate('Y-m-d H:i:s'));

        // On a duplicate session/timestamp, leave the existing row untouched.
        $insertSql =
            "INSERT INTO CustomArcheryWeatherObservations ("
            . implode(',', $columns)
            . ") ";

        if ($allowOpen) {
            // Recheck the parent session as part of the insert itself.
            $insertSql .=
                "SELECT " . implode(',', $sqlValues)
                . " FROM CustomArcheryWeatherSessions"
                . " WHERE CrwsId=" . (int) $session['id']
                . " AND CrwsEndedEpoch IS NULL"
                . " AND CrwsStartedEpoch<=" . $timestamp
                . " AND CrwsStationId="
                . (int) $session['station_id'];
        } else {
            $insertSql .=
                "VALUES (" . implode(',', $sqlValues) . ")";
        }

        $insertSql .=
            " ON DUPLICATE KEY UPDATE CrwoId=CrwoId";

        $result = safe_w_sql($insertSql);

        if ($result === false) {
            return array(
                'ok' => false,
                'error' => 'Observation storage did not finish. '
                    . 'Some readings may have been saved; retrying is safe.',
            );
        }
    }

    $after = resultspack_weather_count_observations($session['id']);

    return array(
        'ok' => true,
        'received' => count($rows),
        'added' => max(0, $after - $before),
        'skipped' => $skipped,
    );
}

// Run retrieval and return a message for the session details page.
function resultspack_weather_collection_message($sessionId)
{
    try {
        $result = resultspack_weather_import_session_observations(
            $sessionId
        );
    } catch (Exception $exception) {
        return 'Retrieval did not finish. Some readings may have '
            . 'been saved. Use Retrieve observations from Tempest to retry.';
    }

    if (empty($result['ok'])) {
        return 'Retrieval failed: '
            . ($result['error'] ?? 'An unexpected error occurred.')
            . ' Use Retrieve observations from Tempest to retry.';
    }

    $received = (int) $result['received'];
    $added = (int) $result['added'];
    $skipped = (int) $result['skipped'];

    if ($received === 0) {
        return 'Tempest returned no observations for this session. '
            . 'Any observations already stored have been kept.';
    }

    return 'Tempest returned ' . $received . ' historical readings. '
        . $added . ' new observations added; '
        . $skipped . ' readings skipped. '
        . 'Readings already stored were kept unchanged.';
}