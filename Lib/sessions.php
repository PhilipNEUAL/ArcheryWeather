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

// Create a weather session starting now.
// Existing callers default to Test.
// Returns the new session ID.
function resultspack_weather_create_session(
    $tournamentId,
    $stationId,
    $stationName = '',
    $researchStatus = 'test',
    $shootingBearing = null,
    $directionVerification = 'unverified',
    $sensorHeight = null,
    $forwardOffset = null,
    $lateralOffset = null,
    $groundSurface = '',
    $exposure = '',
    $positionNotes = ''
) {

    if (!in_array($researchStatus, array('real', 'test'), true)) {
        throw new InvalidArgumentException(
            'Please choose Real or Test for the new session.'
        );
    }

    // Bearing of zero is north; blank is unknown.
    if (is_string($shootingBearing)) {
        $shootingBearing = trim($shootingBearing);
    }

    if ($shootingBearing === '' || $shootingBearing === null) {
        $shootingBearing = null;
    } else {
        if (
            !is_numeric($shootingBearing)
            || !is_finite((float) $shootingBearing)
            || (float) $shootingBearing < 0
            || (float) $shootingBearing >= 360
        ) {
            throw new InvalidArgumentException(
                'Shooting bearing must be at least 0° and less than 360°, '
                . 'or left blank if unknown.'
            );
        }

        $shootingBearing = (float) $shootingBearing;
    }

    $bearingSql = $shootingBearing === null
        ? 'NULL'
        : number_format($shootingBearing, 6, '.', '');

    $allowedVerificationMethods = array(
        'unverified',
        'phone_compass',
        'map_satellite',
        'second_compass',
        'known_site_alignment',
        'surveyed_bearing',
        'other',
    );

    if (
        !in_array(
            $directionVerification,
            $allowedVerificationMethods,
            true
        )
    ) {
        throw new InvalidArgumentException(
            'Please choose a valid direction verification method.'
        );
    }

    if (
        $shootingBearing === null
        && $directionVerification !== 'unverified'
    ) {
        throw new InvalidArgumentException(
            'Enter a shooting bearing before choosing how it was verified.'
        );
    }

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

    if (is_string($sensorHeight)) {
        $sensorHeight = trim($sensorHeight);
    }

    if ($sensorHeight === '' || $sensorHeight === null) {
        $sensorHeight = null;
    } else {
        if (
            !is_numeric($sensorHeight)
            || !is_finite((float) $sensorHeight)
            || (float) $sensorHeight < 0.01
            || (float) $sensorHeight > 20
        ) {
            throw new InvalidArgumentException(
                'Sensor height must be between 0.01 and 20 metres, '
                . 'or left blank if unknown.'
            );
        }

        $sensorHeight = (float) $sensorHeight;
    }

    $heightSql = $sensorHeight === null
        ? 'NULL'
        : number_format($sensorHeight, 2, '.', '');

    $positionSql = array();

    foreach (
        array(
            'Fore/aft position' => $forwardOffset,
            'Lateral position' => $lateralOffset,
        ) as $label => $offset
    ) {
        if (is_string($offset)) {
            $offset = trim($offset);
        }

        if ($offset === '' || $offset === null) {
            $positionSql[] = 'NULL';
            continue;
        }

        if (
            !is_numeric($offset)
            || !is_finite((float) $offset)
            || (float) $offset < -1000
            || (float) $offset > 1000
        ) {
            throw new InvalidArgumentException(
                $label . ' must be between -1000 and 1000 metres, '
                . 'or left blank if unknown.'
            );
        }

        $positionSql[] = number_format(
            (float) $offset,
            2,
            '.',
            ''
        );
    }

    $forwardOffsetSql = $positionSql[0];
    $lateralOffsetSql = $positionSql[1];
    
    $allowedGroundSurfaces = array(
        '',
        'grass',
        'artificial_turf',
        'hardstanding',
        'indoor_floor',
        'mixed',
        'other',
    );

    if (!in_array($groundSurface, $allowedGroundSurfaces, true)) {
        throw new InvalidArgumentException(
            'Please choose a valid ground surface.'
        );
    }

    $allowedExposures = array(
        '',
        'open',
        'partly_sheltered',
        'sheltered',
        'indoor',
        'other',
    );

    if (!in_array($exposure, $allowedExposures, true)) {
        throw new InvalidArgumentException(
            'Please choose a valid site exposure.'
        );
    }

    if (!is_string($positionNotes)) {
        throw new InvalidArgumentException(
            'Please enter position notes as text.'
        );
    }

    $positionNotes = trim($positionNotes);

    if (preg_match('/\A.{0,2000}\z/us', $positionNotes) !== 1) {
        throw new InvalidArgumentException(
            'Position notes must contain valid text of no more than 2000 characters.'
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
            "CrwsShootingBearing, " .
            "CrwsDirectionVerification, " .
            "CrwsSensorHeight, " .
            "CrwsForwardOffset, " .
            "CrwsLateralOffset, " .
            "CrwsGroundSurface, " .
            "CrwsExposure, " .
            "CrwsResearchStatus, " .
            "CrwsCreated" .
        ") VALUES (" .
            $tournamentId . ", " .
            $stationId . ", " .
            StrSafe_DB($stationName) . ", " .
            $startedEpoch . ", " .
            StrSafe_DB($timezone) . ", " .
            StrSafe_DB($positionNotes) . ", " .
            $bearingSql . ", " .
            StrSafe_DB($directionVerification) . ", " .
            $heightSql . ", " .
            $forwardOffsetSql . ", " .
            $lateralOffsetSql . ", " .
            StrSafe_DB($groundSurface) . ", " .
            StrSafe_DB($exposure) . ", " .
            StrSafe_DB($researchStatus) . ", " .
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