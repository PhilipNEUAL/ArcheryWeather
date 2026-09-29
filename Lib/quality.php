<?php

// Calculate data-coverage statistics for one completed weather session. Tempest bucket=1 observations are aligned to the minute, so the expected number of observations are the complete minutess falling within the session window.
function resultspack_weather_session_quality($sessionId)
{
    $session =
        resultspack_weather_get_completed_session(
            $sessionId
        );

    if (!$session) {
        return null;
    }

    $start =
        (int) $session['started_epoch'];

    $end =
        (int) $session['ended_epoch'];

    if ($start <= 0 || $end <= $start) {
        return null;
    }

    $intervalSeconds = 60;

    $firstExpected =
        (int) (
            ceil(
                $start / $intervalSeconds
            ) * $intervalSeconds
        );

    $lastExpected =
        (int) (
            floor(
                $end / $intervalSeconds
            ) * $intervalSeconds
        );

    $expected = 0;

    if ($firstExpected <= $lastExpected) {
        $expected =
            (int) floor(
                (
                    $lastExpected
                    - $firstExpected
                )
                / $intervalSeconds
            ) + 1;
    }

    $timestamps =
        resultspack_weather_get_observation_timestamps(
            $sessionId
        );

    $storedMap = array();

    foreach ($timestamps as $timestamp) {
        $storedMap[$timestamp] = true;
    }

    $receivedExpected = 0;
    $missing = 0;
    $currentMissingRun = 0;
    $longestMissingRun = 0;

    if ($expected > 0) {
        for (
            $timestamp = $firstExpected;
            $timestamp <= $lastExpected;
            $timestamp += $intervalSeconds
        ) {
            if (isset($storedMap[$timestamp])) {
                $receivedExpected++;
                $currentMissingRun = 0;
            } else {
                $missing++;
                $currentMissingRun++;

                if (
                    $currentMissingRun
                    > $longestMissingRun
                ) {
                    $longestMissingRun =
                        $currentMissingRun;
                }
            }
        }
    }

    $coverage =
        $expected > 0
            ? (
                $receivedExpected
                / $expected
            ) * 100
            : null;

    return array(
        'expected' =>
            $expected,

        'received' =>
            $receivedExpected,

        'stored_total' =>
            count($timestamps),

        'missing' =>
            $missing,

        'coverage_percent' =>
            $coverage,

        'longest_gap_minutes' =>
            $longestMissingRun,

        'first_timestamp' =>
            $timestamps
                ? reset($timestamps)
                : null,

        'last_timestamp' =>
            $timestamps
                ? end($timestamps)
                : null,
    );
}