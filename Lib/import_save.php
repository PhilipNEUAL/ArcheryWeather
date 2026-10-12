<?php

// Import a checked batch using one transaction.
// Existing sessions are never updated.
function resultspack_weather_import_save_batch(
    array $packages,
    array $tournamentIds
) {
    global $WRIT_CON;

    if (!$packages || count($packages) > 20) {
        throw new InvalidArgumentException('Invalid import batch size.');
    }

    // Recheck the retained packages before starting any database work.
    foreach ($packages as $package) {
        $checked = resultspack_weather_import_check_json(
            json_encode($package, JSON_THROW_ON_ERROR)
        );

        if (!$checked['ok']) {
            throw new InvalidArgumentException($checked['error']);
        }
    }

    if (!$WRIT_CON) {
        $WRIT_CON = safe_w_con();
    }

    $connection = $WRIT_CON;
    $transactionOpen = false;
    $lockHeld = false;
    $lockName = '';

    // Direct mysqli calls allow errors to reach our rollback handler.
    $query = function ($sql) use ($connection) {
        $result = mysqli_query($connection, $sql);

        if ($result === false) {
            throw new RuntimeException('An import database operation failed.');
        }

        return $result;
    };

    $quote = function ($value) use ($connection) {
        if ($value === null) {
            return 'NULL';
        }

        // Preserve numeric precision when converting JSON numbers to SQL.
        $text = is_float($value)
            ? json_encode(
                $value,
                JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
            )
            : (string) $value;

        return "'" . mysqli_real_escape_string($connection, $text) . "'";
    };

    // Table and column names come only from the fixed maps below.
    $insert = function ($table, array $values) use ($query, $quote) {
        $columns = array();
        $sqlValues = array();

        foreach ($values as $column => $value) {
            $columns[] = '`' . $column . '`';
            $sqlValues[] = $quote($value);
        }

        $query(
            'INSERT INTO `' . $table . '` ('
            . implode(', ', $columns)
            . ') VALUES ('
            . implode(', ', $sqlValues)
            . ')'
        );
    };

    $sessionFields = array(
        'CrwsStationId' => 'station_id',
        'CrwsDeviceId' => 'device_id',
        'CrwsStationName' => 'station_name',
        'CrwsStartedEpoch' => 'started_epoch',
        'CrwsEndedEpoch' => 'ended_epoch',
        'CrwsTimezone' => 'timezone',
        'CrwsShootingBearing' => 'shooting_bearing',
        'CrwsDirectionCorrection' => 'direction_correction',
        'CrwsDirectionVerification' => 'direction_verification',
        'CrwsSensorHeight' => 'sensor_height',
        'CrwsForwardOffset' => 'forward_offset',
        'CrwsLateralOffset' => 'lateral_offset',
        'CrwsGroundSurface' => 'ground_surface',
        'CrwsExposure' => 'exposure',
        'CrwsPositionNotes' => 'position_notes',
        'CrwsResearchStatus' => 'research_status',
        'CrwsCreated' => 'created',
    );

    $sections = array(
        'observations' => array(
            'table' => 'CustomArcheryWeatherObservations',
            'session_column' => 'CrwoSession',
            'reverse' => false,
            'fields' => array(
                'CrwoTimestamp' => 'timestamp',
                'CrwoReportInterval' => 'report_interval',
                'CrwoWindLull' => 'wind_lull',
                'CrwoWindAvg' => 'wind_avg',
                'CrwoWindGust' => 'wind_gust',
                'CrwoWindDir' => 'wind_dir',
                'CrwoStationPressure' => 'station_pressure',
                'CrwoSeaLevelPressure' => 'sea_level_pressure',
                'CrwoAirTemp' => 'air_temp',
                'CrwoRh' => 'humidity',
                'CrwoIlluminance' => 'illuminance',
                'CrwoUv' => 'uv',
                'CrwoSolarRadiation' => 'solar_radiation',
                'CrwoPrecipAccumulation' => 'precip_accumulation',
                'CrwoLocalDayPrecipAccumulation' => 'local_day_precip',
                'CrwoPrecipType' => 'precip_type',
                'CrwoStrikeCount' => 'strike_count',
                'CrwoStrikeDistance' => 'strike_distance',
                'CrwoImported' => 'imported',
            ),
        ),
        'events' => array(
            'table' => 'CustomArcheryWeatherEvents',
            'session_column' => 'CrweSession',
            'reverse' => false,
            'fields' => array(
                'CrweTimestamp' => 'timestamp',
                'CrweAction' => 'action',
                'CrweReason' => 'reason',
                'CrweNote' => 'note',
                'CrweCreated' => 'created',
            ),
        ),
        'timing_corrections' => array(
            'table' => 'CustomArcheryWeatherTimingCorrections',
            'session_column' => 'CrwtcSession',
            'reverse' => true,
            'fields' => array(
                'CrwtcOldStartedEpoch' => 'old_started_epoch',
                'CrwtcOldEndedEpoch' => 'old_ended_epoch',
                'CrwtcNewStartedEpoch' => 'new_started_epoch',
                'CrwtcNewEndedEpoch' => 'new_ended_epoch',
                'CrwtcTimezone' => 'timezone',
                'CrwtcReason' => 'reason',
                'CrwtcCreated' => 'created',
            ),
        ),
        'direction_corrections' => array(
            'table' => 'CustomArcheryWeatherDirectionCorrections',
            'session_column' => 'CrwdcSession',
            'reverse' => true,
            'fields' => array(
                'CrwdcOldCorrection' => 'old_correction',
                'CrwdcNewCorrection' => 'new_correction',
                'CrwdcRecordedBearing' => 'recorded_bearing',
                'CrwdcVerifiedBearing' => 'verified_bearing',
                'CrwdcVerification' => 'verification',
                'CrwdcReason' => 'reason',
                'CrwdcCreated' => 'created',
            ),
        ),
    );

    $outcome = array(
        'imported' => array(),
        'skipped' => array(),
    );

    try {
        // Serialise imports into this database, including different users.
        $databaseResult = $query('SELECT DATABASE() AS database_name');
        $databaseRow = mysqli_fetch_assoc($databaseResult);
        mysqli_free_result($databaseResult);

        $lockName = 'aw_import_'
            . substr(hash('sha256', $databaseRow['database_name']), 0, 40);

        $lockResult = $query(
            'SELECT GET_LOCK(' . $quote($lockName) . ', 0) AS acquired'
        );

        $lockRow = mysqli_fetch_assoc($lockResult);
        mysqli_free_result($lockResult);

        if ((int) $lockRow['acquired'] !== 1) {
            throw new InvalidArgumentException(
                'Another import is running. Please try again shortly.'
            );
        }

        $lockHeld = true;

        if (!mysqli_begin_transaction($connection)) {
            throw new RuntimeException('Could not start the import transaction.');
        }

        $transactionOpen = true;

        foreach ($packages as $index => $package) {
            $session = $package['session'];

            // Lock existing sessions at this station while checking them.
            $existingResult = $query(
                'SELECT CrwsId, CrwsStationId, CrwsDeviceId, '
                . 'CrwsStartedEpoch, CrwsEndedEpoch, CrwsTimezone '
                . 'FROM CustomArcheryWeatherSessions '
                . 'WHERE CrwsStationId = ' . (int) $session['station_id']
                . ' ORDER BY CrwsId FOR UPDATE'
            );

            $matchingIds = array();
            $overlappingIds = array();

            while ($row = mysqli_fetch_assoc($existingResult)) {
                $existing = array(
                    'station_id' => (int) $row['CrwsStationId'],
                    'device_id' => $row['CrwsDeviceId'] === null
                        ? null : (int) $row['CrwsDeviceId'],
                    'started_epoch' => (int) $row['CrwsStartedEpoch'],
                    'ended_epoch' => $row['CrwsEndedEpoch'] === null
                        ? null : (int) $row['CrwsEndedEpoch'],
                    'timezone' => (string) $row['CrwsTimezone'],
                );

                if (
                    hash_equals(
                        $package['session_fingerprint'],
                        resultspack_weather_transfer_fingerprint($existing)
                    )
                ) {
                    $matchingIds[] = (int) $row['CrwsId'];
                } elseif (
                    $existing['started_epoch'] <= $session['ended_epoch']
                    && (
                        $existing['ended_epoch'] === null
                        || $existing['ended_epoch'] >= $session['started_epoch']
                    )
                ) {
                    $overlappingIds[] = (int) $row['CrwsId'];
                }
            }

            mysqli_free_result($existingResult);

            if ($matchingIds || $overlappingIds) {
                $outcome['skipped'][] = array(
                    'package_index' => $index,
                    'reason' => $matchingIds ? 'existing' : 'overlap',
                    'local_ids' => $matchingIds ?: $overlappingIds,
                );

                continue;
            }

            $requestedId = $tournamentIds[$index] ?? null;

            $tournamentId = is_string($requestedId) || is_int($requestedId)
                ? filter_var(
                    $requestedId,
                    FILTER_VALIDATE_INT,
                    array('options' => array('min_range' => 1))
                )
                : false;

            if ($tournamentId === false) {
                throw new InvalidArgumentException(
                    'Choose a local competition for every new session. '
                    . 'If the matching results have changed, preview the upload again.'
                );
            }

            $competitionResult = $query(
                'SELECT ToId FROM Tournament WHERE ToId = '
                . (int) $tournamentId . ' FOR UPDATE'
            );

            $competition = mysqli_fetch_assoc($competitionResult);
            mysqli_free_result($competitionResult);

            if (!$competition) {
                throw new InvalidArgumentException(
                    'A selected local competition no longer exists. Preview the upload again.'
                );
            }

            // No source ID is inserted: MySQL assigns a fresh local ID.
            $values = array('CrwsTournament' => (int) $tournamentId);

            foreach ($sessionFields as $column => $field) {
                $values[$column] = $session[$field] ?? null;
            }

            $insert('CustomArcheryWeatherSessions', $values);

            $newSessionId = (int) mysqli_insert_id($connection);

            if ($newSessionId <= 0) {
                throw new RuntimeException('No new session ID was returned.');
            }

            foreach ($sections as $section => $definition) {
                $rows = $package[$section] ?? array();

                // Exports list corrections newest first.
                // Insert oldest first to preserve their local audit ordering.
                if ($definition['reverse']) {
                    $rows = array_reverse($rows);
                }

                foreach ($rows as $row) {
                    $values = array(
                        $definition['session_column'] => $newSessionId,
                    );

                    foreach ($definition['fields'] as $column => $field) {
                        $values[$column] = $row[$field] ?? null;
                    }

                    $insert($definition['table'], $values);
                }
            }

            $outcome['imported'][] = array(
                'package_index' => $index,
                'local_id' => $newSessionId,
                'observations' => count($package['observations']),
                'events' => count($package['events']),
                'timing_corrections' =>
                    count($package['timing_corrections']),
                'direction_corrections' =>
                    count($package['direction_corrections'] ?? array()),
            );
        }

        if (!mysqli_commit($connection)) {
            throw new RuntimeException('The import commit could not be confirmed.');
        }

        $transactionOpen = false;

        return $outcome;
    } catch (Throwable $e) {
        if ($transactionOpen) {
            try {
                mysqli_rollback($connection);
            } catch (Throwable $rollbackError) {
                // Preserve the original failure.
            }
        }

        if ($e instanceof InvalidArgumentException) {
            throw $e;
        }

        throw new RuntimeException(
            'The import could not be confirmed. Check the sessions list before retrying.',
            0,
            $e
        );
    } finally {
        if ($lockHeld) {
            try {
                $query('SELECT RELEASE_LOCK(' . $quote($lockName) . ')');
            } catch (Throwable $releaseError) {
                // A lost database connection also releases its named locks.
            }
        }
    }
}