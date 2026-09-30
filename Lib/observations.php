<?php

// Count observations for one weather session and within the stated window.
function resultspack_weather_count_observations($sessionId)
{
    $sessionId = (int) $sessionId;

    if ($sessionId <= 0) {
        return 0;
    }

    if (
        !resultspack_weather_table_exists(
            'CustomArcheryWeatherObservations'
        )
    ) {
        return 0;
    }

    $session =
        resultspack_weather_get_completed_session(
            $sessionId
        );

    $where =
        "CrwoSession=" . $sessionId;

    if ($session) {
        $where .=
            " AND CrwoTimestamp>="
            . (int) $session['started_epoch']
            . " AND CrwoTimestamp<="
            . (int) $session['ended_epoch'];
    }

    $result = safe_r_sql(
        "SELECT COUNT(*) AS ObservationCount
        FROM CustomArcheryWeatherObservations
        WHERE " . $where
    );

    $row = safe_fetch($result);

    return $row
        ? (int) $row->ObservationCount
        : 0;
}

// Return observations for one weather session.
function resultspack_weather_get_observations($sessionId)
{
    $sessionId = (int) $sessionId;

    if ($sessionId <= 0) {
        return array();
    }

    if (
        !resultspack_weather_table_exists(
            'CustomArcheryWeatherObservations'
        )
    ) {
        return array();
    }

    $session =
        resultspack_weather_get_completed_session(
            $sessionId
        );

    $where =
        "CrwoSession=" . $sessionId;

    if ($session) {
        $where .=
            " AND CrwoTimestamp>="
            . (int) $session['started_epoch']
            . " AND CrwoTimestamp<="
            . (int) $session['ended_epoch'];
    }

    $result = safe_r_sql(
        "SELECT
            CrwoTimestamp,
            CrwoReportInterval,
            CrwoWindLull,
            CrwoWindAvg,
            CrwoWindGust,
            CrwoWindDir,
            CrwoStationPressure,
            CrwoSeaLevelPressure,
            CrwoAirTemp,
            CrwoRh,
            CrwoIlluminance,
            CrwoUv,
            CrwoSolarRadiation,
            CrwoPrecipAccumulation,
            CrwoLocalDayPrecipAccumulation,
            CrwoPrecipType,
            CrwoStrikeCount,
            CrwoStrikeDistance
        FROM CustomArcheryWeatherObservations
        WHERE " . $where . "
        ORDER BY CrwoTimestamp ASC"
    );

    $observations = array();

    while ($row = safe_fetch($result)) {
        $observations[] = array(
            'timestamp' =>
                (int) $row->CrwoTimestamp,

            'report_interval' =>
                $row->CrwoReportInterval !== null
                    ? (int) $row->CrwoReportInterval
                    : null,

            'wind_lull' =>
                $row->CrwoWindLull !== null
                    ? (float) $row->CrwoWindLull
                    : null,

            'wind_avg' =>
                $row->CrwoWindAvg !== null
                    ? (float) $row->CrwoWindAvg
                    : null,

            'wind_gust' =>
                $row->CrwoWindGust !== null
                    ? (float) $row->CrwoWindGust
                    : null,

            'wind_dir' =>
                $row->CrwoWindDir !== null
                    ? (float) $row->CrwoWindDir
                    : null,

            'station_pressure' =>
                $row->CrwoStationPressure !== null
                    ? (float) $row->CrwoStationPressure
                    : null,

            'sea_level_pressure' =>
                $row->CrwoSeaLevelPressure !== null
                    ? (float) $row->CrwoSeaLevelPressure
                    : null,

            'air_temp' =>
                $row->CrwoAirTemp !== null
                    ? (float) $row->CrwoAirTemp
                    : null,

            'humidity' =>
                $row->CrwoRh !== null
                    ? (float) $row->CrwoRh
                    : null,

            'illuminance' =>
                $row->CrwoIlluminance !== null
                    ? (float) $row->CrwoIlluminance
                    : null,

            'uv' =>
                $row->CrwoUv !== null
                    ? (float) $row->CrwoUv
                    : null,

            'solar_radiation' =>
                $row->CrwoSolarRadiation !== null
                    ? (float) $row->CrwoSolarRadiation
                    : null,

            'precip_accumulation' =>
                $row->CrwoPrecipAccumulation !== null
                    ? (float) $row->CrwoPrecipAccumulation
                    : null,

            'local_day_precip' =>
                $row->CrwoLocalDayPrecipAccumulation !== null
                    ? (float) $row->CrwoLocalDayPrecipAccumulation
                    : null,

            'precip_type' =>
                $row->CrwoPrecipType !== null
                    ? (int) $row->CrwoPrecipType
                    : null,

            'strike_count' =>
                $row->CrwoStrikeCount !== null
                    ? (int) $row->CrwoStrikeCount
                    : null,

            'strike_distance' =>
                $row->CrwoStrikeDistance !== null
                    ? (float) $row->CrwoStrikeDistance
                    : null,
        );
    }

    return $observations;
}

// Return the timestamps for one weather session.
function resultspack_weather_get_observation_timestamps($sessionId)
{
    $sessionId = (int) $sessionId;

    if ($sessionId <= 0) {
        return array();
    }

    if (
        !resultspack_weather_table_exists(
            'CustomArcheryWeatherObservations'
        )
    ) {
        return array();
    }

    $session =
        resultspack_weather_get_completed_session(
            $sessionId
        );

    $where =
        "CrwoSession=" . $sessionId;

    if ($session) {
        $where .=
            " AND CrwoTimestamp>="
            . (int) $session['started_epoch']
            . " AND CrwoTimestamp<="
            . (int) $session['ended_epoch'];
    }

    $result = safe_r_sql(
        "SELECT CrwoTimestamp
        FROM CustomArcheryWeatherObservations
        WHERE " . $where . "
        ORDER BY CrwoTimestamp ASC"
    );

    $timestamps = array();

    while ($row = safe_fetch($result)) {
        $timestamps[] =
            (int) $row->CrwoTimestamp;
    }

    return $timestamps;
}