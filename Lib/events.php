<?php

// Return recorded judge/weather events for one session.
function resultspack_weather_get_events($sessionId)
{
    $sessionId = (int) $sessionId;

    if ($sessionId <= 0) {
        return array();
    }

    if (
        !resultspack_weather_table_exists(
            'CustomArcheryWeatherEvents'
        )
    ) {
        return array();
    }

    $result = safe_r_sql(
        "SELECT
            CrweId,
            CrweTimestamp,
            CrweAction,
            CrweReason,
            CrweNote
        FROM CustomArcheryWeatherEvents
        WHERE CrweSession=" . $sessionId . "
        ORDER BY CrweTimestamp ASC, CrweId ASC"
    );

    $events = array();

    while ($row = safe_fetch($result)) {
        $events[] = array(
            'id' =>
                (int) $row->CrweId,

            'timestamp' =>
                (int) $row->CrweTimestamp,

            'action' =>
                (string) $row->CrweAction,

            'reason' =>
                (string) $row->CrweReason,

            'note' =>
                (string) $row->CrweNote,
        );
    }

    return $events;
}