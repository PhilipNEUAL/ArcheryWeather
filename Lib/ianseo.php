<?php

// Return the tournaments available in this copy of IANSEO.
function resultspack_weather_fetch_tournament_list()
{
    $result = safe_r_sql(
        "SELECT
            ToId,
            ToCode,
            ToName,
            ToWhere,
            ToWhenFrom,
            ToWhenTo
        FROM Tournament
        ORDER BY ToWhenFrom DESC, ToId DESC"
    );

    $tournaments = array();

    while ($row = safe_fetch($result)) {
        $tournaments[] = array(
            'id' => (int) $row->ToId,
            'code' => (string) $row->ToCode,
            'name' => (string) $row->ToName,
            'where' => (string) $row->ToWhere,
            'date_from' => (string) $row->ToWhenFrom,
            'date_to' => (string) $row->ToWhenTo,
        );
    }

    return $tournaments;
}