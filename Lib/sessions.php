<?php

// Research status stored against a weather session.
function resultspack_weather_research_status($value)
{
    $allowed = array(
        'real',
        'test',
        'excluded',
    );

    return in_array($value, $allowed, true)
        ? $value
        : 'test';
}


// Return all existing weather sessions, newest first.
function resultspack_weather_get_sessions()
{
    if (
        !resultspack_weather_table_exists(
            'CustomArcheryWeatherSessions'
        )
    ) {
        return array();
    }

    $result = safe_r_sql(
        "SELECT
            CrwsId,
            CrwsTournament,
            CrwsStationId,
            CrwsDeviceId,
            CrwsStationName,
            CrwsStartedEpoch,
            CrwsEndedEpoch,
            CrwsTimezone,
            CrwsShootingBearing,
            CrwsDirectionCorrection,
            CrwsDirectionVerification,
            CrwsSensorHeight,
            CrwsForwardOffset,
            CrwsLateralOffset,
            CrwsGroundSurface,
            CrwsExposure,
            CrwsPositionNotes,
            CrwsResearchStatus
        FROM CustomArcheryWeatherSessions
        ORDER BY CrwsStartedEpoch DESC"
    );

    $sessions = array();

    while ($row = safe_fetch($result)) {
        $sessions[] = array(
            'id' => (int) $row->CrwsId,

            'tournament_id' =>
                (int) $row->CrwsTournament,

            'station_id' =>
                (int) $row->CrwsStationId,

            'device_id' =>
                $row->CrwsDeviceId !== null
                    ? (int) $row->CrwsDeviceId
                    : null,

            'station_name' =>
                (string) $row->CrwsStationName,

            'started_epoch' =>
                (int) $row->CrwsStartedEpoch,

            'ended_epoch' =>
                $row->CrwsEndedEpoch !== null
                    ? (int) $row->CrwsEndedEpoch
                    : null,

            'timezone' =>
                (string) $row->CrwsTimezone,

            'shooting_bearing' =>
                $row->CrwsShootingBearing !== null
                    ? (float) $row->CrwsShootingBearing
                    : null,

            'direction_correction' =>
                $row->CrwsDirectionCorrection !== null
                    ? (float) $row->CrwsDirectionCorrection
                    : 0.0,

            'direction_verification' =>
                (string) $row->CrwsDirectionVerification,

            'sensor_height' =>
                $row->CrwsSensorHeight !== null
                    ? (float) $row->CrwsSensorHeight
                    : null,

            'forward_offset' =>
                $row->CrwsForwardOffset !== null
                    ? (float) $row->CrwsForwardOffset
                    : null,

            'lateral_offset' =>
                $row->CrwsLateralOffset !== null
                    ? (float) $row->CrwsLateralOffset
                    : null,

            'ground_surface' =>
                (string) $row->CrwsGroundSurface,

            'exposure' =>
                (string) $row->CrwsExposure,

            'position_notes' =>
                (string) $row->CrwsPositionNotes,

            'research_status' =>
                resultspack_weather_research_status(
                    (string) $row->CrwsResearchStatus
                ),
        );
    }

    return $sessions;
}

// Find one weather session by ID.
function resultspack_weather_get_session($sessionId)
{
    $sessionId = (int) $sessionId;

    if ($sessionId <= 0) {
        return null;
    }

    foreach (resultspack_weather_get_sessions() as $session) {
        if ((int) $session['id'] === $sessionId) {
            return $session;
        }
    }

    return null;
}

// Return completed weather sessions, newest first.
function resultspack_weather_get_completed_sessions()
{
    $completed = array();

    foreach (resultspack_weather_get_sessions() as $session) {
        if ($session['ended_epoch'] !== null) {
            $completed[] = $session;
        }
    }

    return $completed;
}

// Find one completed weather session by ID.
function resultspack_weather_get_completed_session($sessionId)
{
    $session = resultspack_weather_get_session($sessionId);

    if (!$session || $session['ended_epoch'] === null) {
        return null;
    }

    return $session;
}

// Create a new Test session starting now.
// Returns the new session ID.
function resultspack_weather_create_session(
    $tournamentId,
    $stationId,
    $stationName = ''
) {
    $tournamentId = filter_var(
        $tournamentId,
        FILTER_VALIDATE_INT,
        array('options' => array('min_range' => 1))
    );

    $stationId = filter_var(
        $stationId,
        FILTER_VALIDATE_INT,
        array('options' => array('min_range' => 1))
    );

    if ($tournamentId === false || $stationId === false) {
        throw new InvalidArgumentException(
            'A valid competition and weather station are required.'
        );
    }

    // Confirm that the selected competition exists.
    $competitionFound = false;

    foreach (resultspack_weather_fetch_tournament_list() as $tournament) {
        if ((int) $tournament['id'] === $tournamentId) {
            $competitionFound = true;
            break;
        }
    }

    if (!$competitionFound) {
        throw new InvalidArgumentException(
            'The selected competition could not be found.'
        );
    }

    $stationName = trim((string) $stationName);

    if (strlen($stationName) > 255) {
        throw new InvalidArgumentException(
            'The weather station name is too long.'
        );
    }

    $startedEpoch = time();
    $timezone = resultspack_weather_timezone();

    $result = safe_w_sql(
        "INSERT INTO CustomArcheryWeatherSessions (" .
            "CrwsTournament, " .
            "CrwsStationId, " .
            "CrwsStationName, " .
            "CrwsStartedEpoch, " .
            "CrwsTimezone, " .
            "CrwsPositionNotes, " .
            "CrwsResearchStatus, " .
            "CrwsCreated" .
        ") VALUES (" .
            $tournamentId . ", " .
            $stationId . ", " .
            StrSafe_DB($stationName) . ", " .
            $startedEpoch . ", " .
            StrSafe_DB($timezone) . ", " .
            StrSafe_DB('') . ", " .
            StrSafe_DB('test') . ", " .
            StrSafe_DB(gmdate('Y-m-d H:i:s', $startedEpoch)) .
        ")"
    );

    if ($result === false) {
        throw new RuntimeException(
            'The weather session could not be created.'
        );
    }

    global $WRIT_CON;

    return (int) mysqli_insert_id($WRIT_CON);
}