<?php

// Return timing corrections for one weather session, newest first.
function resultspack_weather_get_timing_corrections($sessionId)
{
    $sessionId = (int) $sessionId;

    if ($sessionId <= 0) {
        return array();
    }

    if (
        !resultspack_weather_table_exists(
            'CustomResultsPackWeatherTimingCorrections'
        )
    ) {
        return array();
    }

    $result = safe_r_sql(
        "SELECT
            CrwtcId,
            CrwtcSession,
            CrwtcOldStartedEpoch,
            CrwtcOldEndedEpoch,
            CrwtcNewStartedEpoch,
            CrwtcNewEndedEpoch,
            CrwtcTimezone,
            CrwtcReason,
            CrwtcCreated
        FROM CustomResultsPackWeatherTimingCorrections
        WHERE CrwtcSession=" . $sessionId . "
        ORDER BY CrwtcId DESC"
    );

    $corrections = array();

    while ($row = safe_fetch($result)) {
        $corrections[] = array(
            'id' =>
                (int) $row->CrwtcId,

            'session_id' =>
                (int) $row->CrwtcSession,

            'old_started_epoch' =>
                (int) $row->CrwtcOldStartedEpoch,

            'old_ended_epoch' =>
                (int) $row->CrwtcOldEndedEpoch,

            'new_started_epoch' =>
                (int) $row->CrwtcNewStartedEpoch,

            'new_ended_epoch' =>
                (int) $row->CrwtcNewEndedEpoch,

            'timezone' =>
                (string) $row->CrwtcTimezone,

            'reason' =>
                (string) $row->CrwtcReason,

            'created' =>
                (string) $row->CrwtcCreated,
        );
    }

    return $corrections;
}


// Return direction reference corrections for one weather session, newest first.
function resultspack_weather_get_direction_corrections($sessionId)
{
    $sessionId = (int) $sessionId;

    if ($sessionId <= 0) {
        return array();
    }

    if (
        !resultspack_weather_table_exists(
            'CustomResultsPackWeatherDirectionCorrections'
        )
    ) {
        return array();
    }

    $result = safe_r_sql(
        "SELECT
            CrwdcId,
            CrwdcSession,
            CrwdcOldCorrection,
            CrwdcNewCorrection,
            CrwdcRecordedBearing,
            CrwdcVerifiedBearing,
            CrwdcVerification,
            CrwdcReason,
            CrwdcCreated
        FROM CustomResultsPackWeatherDirectionCorrections
        WHERE CrwdcSession=" . $sessionId . "
        ORDER BY CrwdcId DESC"
    );

    $corrections = array();

    while ($row = safe_fetch($result)) {
        $corrections[] = array(
            'id' =>
                (int) $row->CrwdcId,

            'session_id' =>
                (int) $row->CrwdcSession,

            'old_correction' =>
                (float) $row->CrwdcOldCorrection,

            'new_correction' =>
                (float) $row->CrwdcNewCorrection,

            'recorded_bearing' =>
                $row->CrwdcRecordedBearing !== null
                    ? (float) $row->CrwdcRecordedBearing
                    : null,

            'verified_bearing' =>
                (float) $row->CrwdcVerifiedBearing,

            'verification' =>
                (string) $row->CrwdcVerification,

            'reason' =>
                (string) $row->CrwdcReason,

            'created' =>
                (string) $row->CrwdcCreated,
        );
    }

    return $corrections;
}