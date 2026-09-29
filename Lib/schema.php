<?php

// Check whether a table already exists in the current IANSEO database.
function resultspack_weather_table_exists($tableName)
{
    $result = safe_r_sql(
        "SHOW TABLES LIKE " . StrSafe_DB($tableName)
    );

    return (bool) safe_fetch($result);
}


// Inspect the existing ResultsPack weather storage. This isy read-only for the time being.
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