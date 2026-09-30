<?php

// Check whether a table already exists in the current IANSEO database.
function resultspack_weather_table_exists($tableName)
{
    $result = safe_r_sql(
        "SHOW TABLES LIKE " . StrSafe_DB($tableName)
    );

    return (bool) safe_fetch($result);
}

// Inspect the existing ResultsPack weather storage without modifying it.
function resultspack_weather_existing_storage_status()
{
    $tables = array(
        'sessions' =>
            'CustomResultsPackWeatherSessions',

        'observations' =>
            'CustomResultsPackWeatherObservations',

        'events' =>
            'CustomResultsPackWeatherEvents',

        'timing_corrections' =>
            'CustomResultsPackWeatherTimingCorrections',

        'direction_corrections' =>
            'CustomResultsPackWeatherDirectionCorrections',
    );

    $status = array();

    foreach ($tables as $key => $tableName) {
        $exists =
            resultspack_weather_table_exists(
                $tableName
            );

        $count = null;

        if ($exists) {
            $result = safe_r_sql(
                "SELECT COUNT(*) AS RowCount " .
                "FROM " . $tableName
            );

            $row = safe_fetch($result);

            $count = $row
                ? (int) $row->RowCount
                : 0;
        }

        $status[$key] = array(
            'table' => $tableName,
            'exists' => $exists,
            'count' => $count,
        );
    }

    return $status;
}

// Create Sessions table.
function archeryweather_ensure_sessions_table($databaseName = null)
{
    safe_w_sql(
        "CREATE TABLE IF NOT EXISTS " .
        archeryweather_schema_table_name(
            'CustomArcheryWeatherSessions',
            $databaseName
        ) .
        " (" .
        "CrwsId int unsigned NOT NULL AUTO_INCREMENT," .
        "CrwsTournament int NOT NULL," .
        "CrwsStationId int NOT NULL," .
        "CrwsDeviceId int DEFAULT NULL," .
        "CrwsStationName varchar(255) NOT NULL DEFAULT ''," .
        "CrwsStartedEpoch bigint unsigned NOT NULL," .
        "CrwsEndedEpoch bigint unsigned DEFAULT NULL," .
        "CrwsTimezone varchar(64) NOT NULL DEFAULT 'UTC'," .
        "CrwsShootingBearing decimal(5,1) DEFAULT NULL," .
        "CrwsDirectionCorrection decimal(6,2) NOT NULL DEFAULT 0.00," .
        "CrwsDirectionVerification varchar(32) NOT NULL DEFAULT 'unverified'," .
        "CrwsSensorHeight decimal(5,2) DEFAULT NULL," .
        "CrwsForwardOffset decimal(7,2) DEFAULT NULL," .
        "CrwsLateralOffset decimal(7,2) DEFAULT NULL," .
        "CrwsGroundSurface varchar(32) NOT NULL DEFAULT ''," .
        "CrwsExposure varchar(32) NOT NULL DEFAULT ''," .
        "CrwsPositionNotes text NOT NULL," .
        "CrwsResearchStatus varchar(16) NOT NULL DEFAULT 'test'," .
        "CrwsCreated datetime NOT NULL," .
        "PRIMARY KEY (CrwsId)," .
        "KEY CrwsTournament (CrwsTournament)," .
        "KEY CrwsActive (CrwsEndedEpoch)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

// Create Observations table.
function archeryweather_ensure_observations_table($databaseName = null)
{
    safe_w_sql(
        "CREATE TABLE IF NOT EXISTS " .
        archeryweather_schema_table_name(
            'CustomArcheryWeatherObservations',
            $databaseName
        ) .
        " (" .
        "CrwoId int unsigned NOT NULL AUTO_INCREMENT," .
        "CrwoSession int unsigned NOT NULL," .
        "CrwoTimestamp bigint unsigned NOT NULL," .
        "CrwoReportInterval int unsigned DEFAULT NULL," .
        "CrwoWindLull decimal(10,3) DEFAULT NULL," .
        "CrwoWindAvg decimal(10,3) DEFAULT NULL," .
        "CrwoWindGust decimal(10,3) DEFAULT NULL," .
        "CrwoWindDir decimal(6,2) DEFAULT NULL," .
        "CrwoStationPressure decimal(10,3) DEFAULT NULL," .
        "CrwoSeaLevelPressure decimal(10,3) DEFAULT NULL," .
        "CrwoAirTemp decimal(10,3) DEFAULT NULL," .
        "CrwoRh decimal(6,2) DEFAULT NULL," .
        "CrwoIlluminance decimal(14,3) DEFAULT NULL," .
        "CrwoUv decimal(10,3) DEFAULT NULL," .
        "CrwoSolarRadiation decimal(14,3) DEFAULT NULL," .
        "CrwoPrecipAccumulation decimal(14,6) DEFAULT NULL," .
        "CrwoLocalDayPrecipAccumulation decimal(14,6) DEFAULT NULL," .
        "CrwoPrecipType int DEFAULT NULL," .
        "CrwoStrikeCount int DEFAULT NULL," .
        "CrwoStrikeDistance decimal(10,3) DEFAULT NULL," .
        "CrwoImported datetime NOT NULL," .
        "PRIMARY KEY (CrwoId)," .
        "UNIQUE KEY CrwoSessionTimestamp (CrwoSession,CrwoTimestamp)," .
        "KEY CrwoSession (CrwoSession)," .
        "KEY CrwoTimestamp (CrwoTimestamp)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

// Create Judge/Weather Events table.
function archeryweather_ensure_events_table($databaseName = null)
{
    safe_w_sql(
        "CREATE TABLE IF NOT EXISTS " .
        archeryweather_schema_table_name(
            'CustomArcheryWeatherEvents',
            $databaseName
        ) .
        " (" .
        "CrweId int unsigned NOT NULL AUTO_INCREMENT," .
        "CrweSession int unsigned NOT NULL," .
        "CrweTimestamp bigint unsigned NOT NULL," .
        "CrweAction varchar(32) NOT NULL," .
        "CrweReason varchar(64) NOT NULL DEFAULT ''," .
        "CrweNote text NOT NULL," .
        "CrweCreated datetime NOT NULL," .
        "PRIMARY KEY (CrweId)," .
        "KEY CrweSession (CrweSession)," .
        "KEY CrweTimestamp (CrweTimestamp)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

// Create Timing Corrections audit table.
function archeryweather_ensure_timing_corrections_table($databaseName = null)
{
    safe_w_sql(
        "CREATE TABLE IF NOT EXISTS " .
        archeryweather_schema_table_name(
            'CustomArcheryWeatherTimingCorrections',
            $databaseName
        ) .
        " (" .
        "CrwtcId int unsigned NOT NULL AUTO_INCREMENT," .
        "CrwtcSession int unsigned NOT NULL," .
        "CrwtcOldStartedEpoch bigint unsigned NOT NULL," .
        "CrwtcOldEndedEpoch bigint unsigned NOT NULL," .
        "CrwtcNewStartedEpoch bigint unsigned NOT NULL," .
        "CrwtcNewEndedEpoch bigint unsigned NOT NULL," .
        "CrwtcTimezone varchar(64) NOT NULL DEFAULT 'UTC'," .
        "CrwtcReason text NOT NULL," .
        "CrwtcCreated datetime NOT NULL," .
        "PRIMARY KEY (CrwtcId)," .
        "KEY CrwtcSession (CrwtcSession)," .
        "KEY CrwtcCreated (CrwtcCreated)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

// Create Direction Corrections audit table.
function archeryweather_ensure_direction_corrections_table($databaseName = null)
{
    safe_w_sql(
        "CREATE TABLE IF NOT EXISTS " .
        archeryweather_schema_table_name(
            'CustomArcheryWeatherDirectionCorrections',
            $databaseName
        ) .
        " (" .
        "CrwdcId int unsigned NOT NULL AUTO_INCREMENT," .
        "CrwdcSession int unsigned NOT NULL," .
        "CrwdcOldCorrection decimal(6,2) NOT NULL DEFAULT 0.00," .
        "CrwdcNewCorrection decimal(6,2) NOT NULL DEFAULT 0.00," .
        "CrwdcRecordedBearing decimal(6,2) DEFAULT NULL," .
        "CrwdcVerifiedBearing decimal(6,2) NOT NULL," .
        "CrwdcVerification varchar(32) NOT NULL DEFAULT 'other'," .
        "CrwdcReason text NOT NULL," .
        "CrwdcCreated datetime NOT NULL," .
        "PRIMARY KEY (CrwdcId)," .
        "KEY CrwdcSession (CrwdcSession)," .
        "KEY CrwdcCreated (CrwdcCreated)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

// Initialise all database tables.
function archeryweather_ensure_schema($databaseName = null)
{
    // Weather session records.
    archeryweather_ensure_sessions_table($databaseName);

    // Environmental observations.
    archeryweather_ensure_observations_table($databaseName);

    // Judge decisions and weather-related events.
    archeryweather_ensure_events_table($databaseName);

    // Session timing correction audit history.
    archeryweather_ensure_timing_corrections_table($databaseName);

    // Shooting direction correction audit history.
    archeryweather_ensure_direction_corrections_table($databaseName);
}

// Build a safe table reference for ArcheryWeather.
//
// Normally uses IANSEO's current database.
// An optional database name allows isolated installation testing.
//
// Only recognised ArcheryWeather tables are permitted.

function archeryweather_schema_table_name(
    $tableName,
    $databaseName = null
) {
    $allowedTables = array(
        'CustomArcheryWeatherSessions',
        'CustomArcheryWeatherObservations',
        'CustomArcheryWeatherEvents',
        'CustomArcheryWeatherTimingCorrections',
        'CustomArcheryWeatherDirectionCorrections',
    );

    if (!in_array($tableName, $allowedTables, true)) {
        throw new InvalidArgumentException(
            'Unrecognised ArcheryWeather table.'
        );
    }

    // Normal installation: use the current IANSEO database.
    if ($databaseName === null) {
        return '`' . $tableName . '`';
    }

    // Database names cannot be supplied as SQL parameters.
    // Validate the identifier before including it in SQL.
    if (
        !is_string($databaseName)
        || !preg_match('/^[A-Za-z0-9_]+$/D', $databaseName)
    ) {
        throw new InvalidArgumentException(
            'Invalid database name.'
        );
    }

    return '`' . $databaseName . '`.`' . $tableName . '`';
}