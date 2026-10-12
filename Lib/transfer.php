<?php

// Retain the existing ResultsPack transfer-format version.
function resultspack_weather_transfer_format_version()
{
    return 1;
}

// Preserve the existing fingerprint calculation for compatibility.
// This identifies a session window; it is not a checksum of its readings.
function resultspack_weather_transfer_fingerprint(array $session)
{
    return hash(
        'sha256',
        implode('|', array(
            (string) ($session['station_id'] ?? ''),
            (string) ($session['device_id'] ?? ''),
            (string) ($session['started_epoch'] ?? ''),
            (string) ($session['ended_epoch'] ?? ''),
            (string) ($session['timezone'] ?? ''),
        ))
    );
}

// Read the explicitly selected session fields for export.
// Configuration and API credentials are not included.
function resultspack_weather_transfer_session_record($sessionId)
{
    $sessionId = filter_var(
        $sessionId,
        FILTER_VALIDATE_INT,
        array('options' => array('min_range' => 1))
    );

    if ($sessionId === false) {
        return null;
    }

    $result = safe_r_sql(
        "SELECT "
        . "CrwsId, CrwsTournament, CrwsStationId, CrwsDeviceId, "
        . "CrwsStationName, CrwsStartedEpoch, CrwsEndedEpoch, "
        . "CrwsTimezone, CrwsShootingBearing, "
        . "CrwsDirectionCorrection, CrwsDirectionVerification, "
        . "CrwsSensorHeight, CrwsForwardOffset, CrwsLateralOffset, "
        . "CrwsGroundSurface, CrwsExposure, CrwsPositionNotes, "
        . "CrwsResearchStatus, CrwsCreated "
        . "FROM CustomArcheryWeatherSessions "
        . "WHERE CrwsId=" . $sessionId
        . " AND CrwsEndedEpoch IS NOT NULL "
        . "LIMIT 1"
    );

    $row = safe_fetch($result);

    if (!$row) {
        return null;
    }

    $record = array();

    $integerFields = array(
        'source_session_id' => 'CrwsId',
        'source_tournament_id' => 'CrwsTournament',
        'station_id' => 'CrwsStationId',
        'device_id' => 'CrwsDeviceId',
        'started_epoch' => 'CrwsStartedEpoch',
        'ended_epoch' => 'CrwsEndedEpoch',
    );

    foreach ($integerFields as $key => $column) {
        $record[$key] = $row->$column !== null
            ? (int) $row->$column
            : null;
    }

    $decimalFields = array(
        'shooting_bearing' => 'CrwsShootingBearing',
        'direction_correction' => 'CrwsDirectionCorrection',
        'sensor_height' => 'CrwsSensorHeight',
        'forward_offset' => 'CrwsForwardOffset',
        'lateral_offset' => 'CrwsLateralOffset',
    );

    foreach ($decimalFields as $key => $column) {
        $record[$key] = $row->$column !== null
            ? (float) $row->$column
            : null;
    }

    $textFields = array(
        'station_name' => 'CrwsStationName',
        'timezone' => 'CrwsTimezone',
        'direction_verification' => 'CrwsDirectionVerification',
        'ground_surface' => 'CrwsGroundSurface',
        'exposure' => 'CrwsExposure',
        'position_notes' => 'CrwsPositionNotes',
        'research_status' => 'CrwsResearchStatus',
        'created' => 'CrwsCreated',
    );

    foreach ($textFields as $key => $column) {
        $record[$key] = (string) $row->$column;
    }

    return $record;
}

// Return ALL stored observations, including those outside a corrected window.
function resultspack_weather_transfer_observations($sessionId)
{
    $sessionId = (int) $sessionId;

    if ($sessionId <= 0) {
        return array();
    }

    $result = safe_r_sql(
        "SELECT "
        . "CrwoTimestamp, CrwoReportInterval, "
        . "CrwoWindLull, CrwoWindAvg, CrwoWindGust, CrwoWindDir, "
        . "CrwoStationPressure, CrwoSeaLevelPressure, "
        . "CrwoAirTemp, CrwoRh, CrwoIlluminance, CrwoUv, "
        . "CrwoSolarRadiation, CrwoPrecipAccumulation, "
        . "CrwoLocalDayPrecipAccumulation, CrwoPrecipType, "
        . "CrwoStrikeCount, CrwoStrikeDistance, CrwoImported "
        . "FROM CustomArcheryWeatherObservations "
        . "WHERE CrwoSession=" . $sessionId
        . " ORDER BY CrwoTimestamp ASC"
    );

    $integerFields = array(
        'timestamp' => 'CrwoTimestamp',
        'report_interval' => 'CrwoReportInterval',
        'precip_type' => 'CrwoPrecipType',
        'strike_count' => 'CrwoStrikeCount',
    );

    $decimalFields = array(
        'wind_lull' => 'CrwoWindLull',
        'wind_avg' => 'CrwoWindAvg',
        'wind_gust' => 'CrwoWindGust',
        'wind_dir' => 'CrwoWindDir',
        'station_pressure' => 'CrwoStationPressure',
        'sea_level_pressure' => 'CrwoSeaLevelPressure',
        'air_temp' => 'CrwoAirTemp',
        'humidity' => 'CrwoRh',
        'illuminance' => 'CrwoIlluminance',
        'uv' => 'CrwoUv',
        'solar_radiation' => 'CrwoSolarRadiation',
        'precip_accumulation' => 'CrwoPrecipAccumulation',
        'local_day_precip' => 'CrwoLocalDayPrecipAccumulation',
        'strike_distance' => 'CrwoStrikeDistance',
    );

    $observations = array();

    while ($row = safe_fetch($result)) {
        $observation = array();

        foreach ($integerFields as $key => $column) {
            $observation[$key] = $row->$column !== null
                ? (int) $row->$column
                : null;
        }

        foreach ($decimalFields as $key => $column) {
            $observation[$key] = $row->$column !== null
                ? (float) $row->$column
                : null;
        }

        $observation['imported'] = (string) $row->CrwoImported;

        $observations[] = $observation;
    }

    return $observations;
}

// Return all session events, preserving their original creation timestamps.
function resultspack_weather_transfer_events($sessionId)
{
    $sessionId = (int) $sessionId;

    if ($sessionId <= 0) {
        return array();
    }

    $result = safe_r_sql(
        "SELECT "
        . "CrweId, CrweTimestamp, CrweAction, CrweReason, "
        . "CrweNote, CrweCreated "
        . "FROM CustomArcheryWeatherEvents "
        . "WHERE CrweSession=" . $sessionId
        . " ORDER BY CrweTimestamp ASC, CrweId ASC"
    );

    $events = array();

    while ($row = safe_fetch($result)) {
        $events[] = array(
            'source_event_id' => (int) $row->CrweId,
            'timestamp' => (int) $row->CrweTimestamp,
            'action' => (string) $row->CrweAction,
            'reason' => (string) $row->CrweReason,
            'note' => (string) $row->CrweNote,
            'created' => (string) $row->CrweCreated,
        );
    }

    return $events;
}

// Return competition metadata using the existing transfer-format keys.
function resultspack_weather_transfer_tournament_record($tournamentId)
{
    $tournamentId = (int) $tournamentId;

    $record = array(
        'source_tournament_id' => $tournamentId,
        'code' => '',
        'name' => '',
        'short_name' => '',
        'venue' => '',
        'where' => '',
        'date_from' => '',
        'date_to' => '',
        'type_name' => '',
    );

    foreach (resultspack_weather_fetch_tournament_list() as $tournament) {
        if ((int) $tournament['id'] !== $tournamentId) {
            continue;
        }

        foreach (
            array(
                'code',
                'name',
                'short_name',
                'venue',
                'where',
                'date_from',
                'date_to',
                'type_name',
            ) as $field
        ) {
            $record[$field] = (string) ($tournament[$field] ?? '');
        }

        break;
    }

    return $record;
}

// Assemble one completed session in the existing ResultsPack JSON format.
function resultspack_weather_build_transfer_package($sessionId)
{
    $session = resultspack_weather_transfer_session_record($sessionId);

    if (!$session) {
        return array(
            'ok' => false,
            'error' => 'Completed weather session not found.',
        );
    }

    if ($session['ended_epoch'] <= $session['started_epoch']) {
        return array(
            'ok' => false,
            'error' => 'Session #' . $session['source_session_id']
                . ' has an end time that is not after its start. '
                . 'Correct its timing before exporting.',
        );
    }

    $sessionId = $session['source_session_id'];

    $timingCorrections =
        resultspack_weather_get_timing_corrections($sessionId);

    // Local database IDs must not become IDs on the receiving installation.
    foreach ($timingCorrections as &$correction) {
        unset($correction['id'], $correction['session_id']);
    }
    unset($correction);

    $directionCorrections =
        resultspack_weather_get_direction_corrections($sessionId);

    foreach ($directionCorrections as &$correction) {
        unset($correction['id'], $correction['session_id']);
    }
    unset($correction);

    $package = array(
        // Keep this identifier for compatibility with ResultsPack packages.
        'format' => 'resultspack-weather-session',
        'format_version' => resultspack_weather_transfer_format_version(),
        'exported_at_utc' => gmdate('c'),
        'session_fingerprint' =>
            resultspack_weather_transfer_fingerprint($session),
        'tournament' =>
            resultspack_weather_transfer_tournament_record(
                $session['source_tournament_id']
            ),
        'session' => $session,
        'quality' => resultspack_weather_session_quality($sessionId),
        'observations' =>
            resultspack_weather_transfer_observations($sessionId),
        'events' => resultspack_weather_transfer_events($sessionId),
        'timing_corrections' => $timingCorrections,
        'direction_corrections' => $directionCorrections,
    );

    return array(
        'ok' => true,
        'package' => $package,
    );
}

// Include the session ID so multiple sessions from one event have distinct names.
function resultspack_weather_transfer_filename(
    array $package,
    $extension = 'json'
) {
    if (!in_array($extension, array('json', 'csv'), true)) {
        $extension = 'json';
    }

    $session = $package['session'];

    $label = trim((string) ($package['tournament']['code'] ?? ''));

    if ($label === '') {
        $label = 'WeatherSession';
    }

    $label = preg_replace('/[^A-Za-z0-9._-]+/', '-', $label);
    $label = trim(substr($label, 0, 80), '-_.');

    if ($label === '') {
        $label = 'WeatherSession';
    }

    $date = resultspack_weather_format_timestamp(
        $session['started_epoch'],
        $session['timezone'],
        'Y-m-d'
    );

    return 'ArcheryWeather-'
        . $date . '-'
        . $label . '-session-'
        . (int) $session['source_session_id']
        . '.' . $extension;
}