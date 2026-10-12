<?php

// List completed Test/Excluded sessions and every linked record.
// Read the stored status directly: unknown statuses must not qualify.
function resultspack_weather_cleanup_candidates()
{
    $result = safe_r_sql(
        "SELECT
            s.CrwsId,
            s.CrwsTournament,
            s.CrwsStationName,
            s.CrwsStartedEpoch,
            s.CrwsEndedEpoch,
            s.CrwsTimezone,
            s.CrwsResearchStatus,

            (SELECT COUNT(*)
             FROM CustomArcheryWeatherObservations o
             WHERE o.CrwoSession = s.CrwsId) AS ObservationCount,

            (SELECT COUNT(*)
             FROM CustomArcheryWeatherEvents e
             WHERE e.CrweSession = s.CrwsId) AS EventCount,

            (SELECT COUNT(*)
             FROM CustomArcheryWeatherTimingCorrections t
             WHERE t.CrwtcSession = s.CrwsId) AS TimingCount,

            (SELECT COUNT(*)
             FROM CustomArcheryWeatherDirectionCorrections d
             WHERE d.CrwdcSession = s.CrwsId) AS DirectionCount

        FROM CustomArcheryWeatherSessions s
        WHERE BINARY s.CrwsResearchStatus IN ('test', 'excluded')
          AND s.CrwsEndedEpoch IS NOT NULL
        ORDER BY s.CrwsStartedEpoch DESC, s.CrwsId DESC"
    );

    $sessions = array();

    while ($row = safe_fetch($result)) {
        $sessions[] = array(
            'id' => (int) $row->CrwsId,
            'tournament_id' => (int) $row->CrwsTournament,
            'station_name' => (string) $row->CrwsStationName,
            'started_epoch' => (int) $row->CrwsStartedEpoch,
            'ended_epoch' => (int) $row->CrwsEndedEpoch,
            'timezone' => (string) $row->CrwsTimezone,
            'research_status' => (string) $row->CrwsResearchStatus,
            'observations' => (int) $row->ObservationCount,
            'events' => (int) $row->EventCount,
            'timing_corrections' => (int) $row->TimingCount,
            'direction_corrections' => (int) $row->DirectionCount,
        );
    }

    return $sessions;
}