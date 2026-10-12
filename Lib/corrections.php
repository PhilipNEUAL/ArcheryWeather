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
            'CustomArcheryWeatherTimingCorrections'
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
        FROM CustomArcheryWeatherTimingCorrections
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
            'CustomArcheryWeatherDirectionCorrections'
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
        FROM CustomArcheryWeatherDirectionCorrections
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

// Save revised session times and their audit entry together.
// Returns true when changed, or false when the times are unchanged.
function resultspack_weather_save_timing_correction(
    $sessionId,
    $newStarted,
    $newEnded,
    $reason,
    $expectedStarted,
    $expectedEnded
) {
    // All timestamps here are Unix timestamps, not local date strings.
    $numbers = array(
        'session_id' => $sessionId,
        'new_started' => $newStarted,
        'new_ended' => $newEnded,
        'expected_started' => $expectedStarted,
        'expected_ended' => $expectedEnded,
    );

    foreach ($numbers as $key => $value) {
        $validated = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            array('options' => array('min_range' => 1))
        );

        if ($validated === false) {
            throw new InvalidArgumentException(
                'The session or timing values are invalid. '
                . 'Reload the timing page and try again.'
            );
        }

        $numbers[$key] = $validated;
    }

    $sessionId = $numbers['session_id'];
    $newStarted = $numbers['new_started'];
    $newEnded = $numbers['new_ended'];
    $expectedStarted = $numbers['expected_started'];
    $expectedEnded = $numbers['expected_ended'];

    if ($newEnded <= $newStarted) {
        throw new InvalidArgumentException(
            'The corrected end must be after the corrected start.'
        );
    }

    if ($newEnded > time()) {
        throw new InvalidArgumentException(
            'The corrected session times cannot be in the future.'
        );
    }

    if (!is_string($reason)) {
        throw new InvalidArgumentException(
            'Please enter a reason for the correction.'
        );
    }

    $reason = trim($reason);

    if (
        $reason === ''
        || preg_match('/\A.{1,2000}\z/us', $reason) !== 1
    ) {
        throw new InvalidArgumentException(
            'Please enter a reason of between 1 and 2000 characters.'
        );
    }

    // Use IANSEO's existing write connection.
    global $WRIT_CON;

    if (!$WRIT_CON) {
        $WRIT_CON = safe_w_con();
    }

    $connection = $WRIT_CON;
    $transactionStarted = false;

    // Direct queries allow errors to reach our rollback handler instead
    // of IANSEO's safe_error(), which exits the request immediately.
    $query = function ($sql) use ($connection) {
        $result = mysqli_query($connection, $sql);

        if ($result === false) {
            throw new RuntimeException('Timing database operation failed.');
        }

        return $result;
    };

    try {
        if (!mysqli_begin_transaction($connection)) {
            throw new RuntimeException('Could not start timing transaction.');
        }

        $transactionStarted = true;

        // Lock this session while checking and changing its times.
        $result = $query(
            "SELECT CrwsStartedEpoch, CrwsEndedEpoch, CrwsTimezone "
            . "FROM CustomArcheryWeatherSessions "
            . "WHERE CrwsId=" . $sessionId . " FOR UPDATE"
        );

        $row = mysqli_fetch_assoc($result);
        mysqli_free_result($result);

        if (!$row || $row['CrwsEndedEpoch'] === null) {
            throw new InvalidArgumentException(
                'Timing corrections require an existing completed session.'
            );
        }

        $oldStarted = (int) $row['CrwsStartedEpoch'];
        $oldEnded = (int) $row['CrwsEndedEpoch'];

        if (
            $oldStarted !== $expectedStarted
            || $oldEnded !== $expectedEnded
        ) {
            throw new InvalidArgumentException(
                'The session times have changed since you opened this form. '
                . 'Reload the timing page and review the latest values.'
            );
        }

        if ($newStarted === $oldStarted && $newEnded === $oldEnded) {
            if (!mysqli_rollback($connection)) {
                throw new RuntimeException('Could not close timing transaction.');
            }

            $transactionStarted = false;
            return false;
        }

        $timezone = (string) $row['CrwsTimezone'];

        if ($timezone === '') {
            $timezone = resultspack_weather_timezone();
        }

        $timezoneSql = "'"
            . mysqli_real_escape_string($connection, $timezone)
            . "'";

        $reasonSql = "'"
            . mysqli_real_escape_string($connection, $reason)
            . "'";

        $query(
            "UPDATE CustomArcheryWeatherSessions SET "
            . "CrwsStartedEpoch=" . $newStarted . ", "
            . "CrwsEndedEpoch=" . $newEnded . " "
            . "WHERE CrwsId=" . $sessionId
        );

        $query(
            "INSERT INTO CustomArcheryWeatherTimingCorrections ("
            . "CrwtcSession, "
            . "CrwtcOldStartedEpoch, CrwtcOldEndedEpoch, "
            . "CrwtcNewStartedEpoch, CrwtcNewEndedEpoch, "
            . "CrwtcTimezone, CrwtcReason, CrwtcCreated"
            . ") VALUES ("
            . $sessionId . ", "
            . $oldStarted . ", " . $oldEnded . ", "
            . $newStarted . ", " . $newEnded . ", "
            . $timezoneSql . ", " . $reasonSql . ", UTC_TIMESTAMP()"
            . ")"
        );

        if (!mysqli_commit($connection)) {
            throw new RuntimeException('Could not commit timing transaction.');
        }

        $transactionStarted = false;
        return true;
    } catch (Throwable $exception) {
        if ($transactionStarted) {
            try {
                mysqli_rollback($connection);
            } catch (Throwable $rollbackException) {
                // Report the original failure below.
            }
        }

        if ($exception instanceof InvalidArgumentException) {
            throw $exception;
        }

        throw new RuntimeException(
            'The timing correction could not be confirmed as saved. '
            . 'Reload the timing page and check its current times and audit trail.',
            0,
            $exception
        );
    }
}

// Save direction correction and its audit entry together.
// Returns true when changed, or false when angle and method are unchanged.
function resultspack_weather_save_direction_correction(
    $sessionId,
    $verifiedBearing,
    $verification,
    $reason,
    $expectedCorrection,
    $expectedVerification,
    $expectedAuditId
) {
    $sessionId = filter_var(
        $sessionId,
        FILTER_VALIDATE_INT,
        array('options' => array('min_range' => 1))
    );

    $expectedAuditId = filter_var(
        $expectedAuditId,
        FILTER_VALIDATE_INT,
        array('options' => array('min_range' => 0))
    );

    if ($sessionId === false || $expectedAuditId === false) {
        throw new InvalidArgumentException(
            'Invalid session information. Reload the direction page.'
        );
    }

    if (
        !is_numeric($verifiedBearing)
        || !is_finite((float) $verifiedBearing)
        || (float) $verifiedBearing < 0
        || (float) $verifiedBearing > 359.99
    ) {
        throw new InvalidArgumentException(
            'The verified bearing must be between 0 and 359.99 degrees.'
        );
    }

    $verifiedCents = (int) round((float) $verifiedBearing * 100);

    if (abs((float) $verifiedBearing * 100 - $verifiedCents) > 0.000001) {
        throw new InvalidArgumentException(
            'Please enter the verified bearing with no more than two decimal places.'
        );
    }

    $allowedMethods = array(
        'map_satellite',
        'second_compass',
        'known_site_alignment',
        'surveyed_bearing',
        'other',
    );

    if (!in_array($verification, $allowedMethods, true)) {
        throw new InvalidArgumentException(
            'Please choose an independent verification method.'
        );
    }

    if (
        !is_numeric($expectedCorrection)
        || !is_finite((float) $expectedCorrection)
        || abs((float) $expectedCorrection) > 360
        || !is_string($expectedVerification)
    ) {
        throw new InvalidArgumentException(
            'Invalid original direction information. Reload the direction page.'
        );
    }

    $expectedCents = (int) round((float) $expectedCorrection * 100);

    if (!is_string($reason)) {
        throw new InvalidArgumentException(
            'Please enter a reason for the correction.'
        );
    }

    $reason = trim($reason);

    if (
        $reason === ''
        || preg_match('/\A.{1,2000}\z/us', $reason) !== 1
    ) {
        throw new InvalidArgumentException(
            'Please enter a reason of between 1 and 2000 characters.'
        );
    }

    global $WRIT_CON;

    if (!$WRIT_CON) {
        $WRIT_CON = safe_w_con();
    }

    $connection = $WRIT_CON;
    $transactionStarted = false;

    $query = function ($sql) use ($connection) {
        $result = mysqli_query($connection, $sql);

        if ($result === false) {
            throw new RuntimeException(
                'Direction database operation failed.'
            );
        }

        return $result;
    };

    try {
        if (!mysqli_begin_transaction($connection)) {
            throw new RuntimeException(
                'Could not start direction transaction.'
            );
        }

        $transactionStarted = true;

        $result = $query(
            "SELECT CrwsEndedEpoch, CrwsShootingBearing, "
            . "CrwsDirectionCorrection, CrwsDirectionVerification "
            . "FROM CustomArcheryWeatherSessions "
            . "WHERE CrwsId=" . $sessionId . " FOR UPDATE"
        );

        $row = mysqli_fetch_assoc($result);
        mysqli_free_result($result);

        if (!$row || $row['CrwsEndedEpoch'] === null) {
            throw new InvalidArgumentException(
                'Direction corrections require an existing completed session.'
            );
        }

        if ($row['CrwsShootingBearing'] === null) {
            throw new InvalidArgumentException(
                'This session has no original shooting bearing to correct.'
            );
        }

        // Read the latest audit entry while holding the session lock.
        $result = $query(
            "SELECT CrwdcId "
            . "FROM CustomArcheryWeatherDirectionCorrections "
            . "WHERE CrwdcSession=" . $sessionId
            . " ORDER BY CrwdcId DESC LIMIT 1 FOR UPDATE"
        );

        $auditRow = mysqli_fetch_assoc($result);
        mysqli_free_result($result);

        $latestAuditId = $auditRow ? (int) $auditRow['CrwdcId'] : 0;

        $oldCents = (int) round(
            (float) $row['CrwsDirectionCorrection'] * 100
        );

        $oldVerification = (string) $row['CrwsDirectionVerification'];

        if (
            $latestAuditId !== $expectedAuditId
            || $oldCents !== $expectedCents
            || $oldVerification !== $expectedVerification
        ) {
            throw new InvalidArgumentException(
                'The direction reference has changed since you opened this form. '
                . 'Reload the page and review the latest values.'
            );
        }

        // Calculate from the ORIGINAL bearing, not the previous correction.
        // Integer hundredths of a degree avoid floating-point drift.
        $recordedCents = (int) round(
            (float) $row['CrwsShootingBearing'] * 100
        );

        $recordedNormalised = (
            ($recordedCents % 36000) + 36000
        ) % 36000;

        $newCents = (
            ($verifiedCents - $recordedNormalised + 54000) % 36000
        ) - 18000;

        // Match the existing helper's convention for an exact half-turn.
        if ($newCents === -18000) {
            $newCents = 18000;
        }

        if (
            $newCents === $oldCents
            && $verification === $oldVerification
        ) {
            if (!mysqli_rollback($connection)) {
                throw new RuntimeException(
                    'Could not close direction transaction.'
                );
            }

            $transactionStarted = false;
            return false;
        }

        $recordedSql = number_format($recordedCents / 100, 2, '.', '');
        $verifiedSql = number_format($verifiedCents / 100, 2, '.', '');
        $oldSql = number_format($oldCents / 100, 2, '.', '');
        $newSql = number_format($newCents / 100, 2, '.', '');

        $verificationSql = "'"
            . mysqli_real_escape_string($connection, $verification)
            . "'";

        $reasonSql = "'"
            . mysqli_real_escape_string($connection, $reason)
            . "'";

        $query(
            "UPDATE CustomArcheryWeatherSessions SET "
            . "CrwsDirectionCorrection=" . $newSql . ", "
            . "CrwsDirectionVerification=" . $verificationSql
            . " WHERE CrwsId=" . $sessionId
        );

        $query(
            "INSERT INTO CustomArcheryWeatherDirectionCorrections ("
            . "CrwdcSession, CrwdcOldCorrection, CrwdcNewCorrection, "
            . "CrwdcRecordedBearing, CrwdcVerifiedBearing, "
            . "CrwdcVerification, CrwdcReason, CrwdcCreated"
            . ") VALUES ("
            . $sessionId . ", "
            . $oldSql . ", " . $newSql . ", "
            . $recordedSql . ", " . $verifiedSql . ", "
            . $verificationSql . ", " . $reasonSql
            . ", UTC_TIMESTAMP())"
        );

        if (!mysqli_commit($connection)) {
            throw new RuntimeException(
                'Could not commit direction transaction.'
            );
        }

        $transactionStarted = false;
        return true;
    } catch (Throwable $exception) {
        if ($transactionStarted) {
            try {
                mysqli_rollback($connection);
            } catch (Throwable $rollbackException) {
                // Report the original failure below.
            }
        }

        if ($exception instanceof InvalidArgumentException) {
            throw $exception;
        }

        throw new RuntimeException(
            'The direction correction could not be confirmed as saved. '
            . 'Reload the direction page and check its current values and audit trail.',
            0,
            $exception
        );
    }
}