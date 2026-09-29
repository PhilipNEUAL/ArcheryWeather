<?php

// Observations for one weather session and within the stated window.
function resultspack_weather_count_observations($sessionId)
{
    $sessionId = (int) $sessionId;

    if ($sessionId <= 0) {
        return 0;
    }

    if (
        !resultspack_weather_table_exists(
            'CustomResultsPackWeatherObservations'
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
        FROM CustomResultsPackWeatherObservations
        WHERE " . $where
    );

    $row = safe_fetch($result);

    return $row
        ? (int) $row->ObservationCount
        : 0;
}