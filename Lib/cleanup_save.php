<?php

// Delete only the completed Test/Excluded sessions reviewed by the user.
function resultspack_weather_cleanup_delete_batch(array $reviewed)
{
    global $WRIT_CON;

    if (!$reviewed || count($reviewed) > 200) {
        throw new InvalidArgumentException('Invalid cleanup selection.');
    }

    $snapshots = array();

    foreach ($reviewed as $session) {
        if (
            !is_array($session)
            || !isset($session['id'])
            || !is_int($session['id'])
            || $session['id'] <= 0
            || isset($snapshots[$session['id']])
        ) {
            throw new InvalidArgumentException(
                'The cleanup selection is invalid. Review it again.'
            );
        }

        $snapshots[$session['id']] = $session;
    }

    // Lock sessions in a consistent order.
    ksort($snapshots, SORT_NUMERIC);

    $ids = array_keys($snapshots);
    $idList = implode(',', $ids);

    if (!$WRIT_CON) {
        $WRIT_CON = safe_w_con();
    }

    $connection = $WRIT_CON;
    $transactionOpen = false;

    $query = function ($sql) use ($connection) {
        $result = mysqli_query($connection, $sql);

        if ($result === false) {
            throw new RuntimeException('A cleanup database operation failed.');
        }

        return $result;
    };

    $metadataFields = array(
        'CrwsTournament' => 'tournament_id',
        'CrwsStationName' => 'station_name',
        'CrwsStartedEpoch' => 'started_epoch',
        'CrwsEndedEpoch' => 'ended_epoch',
        'CrwsTimezone' => 'timezone',
        'CrwsResearchStatus' => 'research_status',
    );

    $linkedTables = array(
        'observations' => array(
            'table' => 'CustomArcheryWeatherObservations',
            'id' => 'CrwoId',
            'session' => 'CrwoSession',
        ),
        'events' => array(
            'table' => 'CustomArcheryWeatherEvents',
            'id' => 'CrweId',
            'session' => 'CrweSession',
        ),
        'timing_corrections' => array(
            'table' => 'CustomArcheryWeatherTimingCorrections',
            'id' => 'CrwtcId',
            'session' => 'CrwtcSession',
        ),
        'direction_corrections' => array(
            'table' => 'CustomArcheryWeatherDirectionCorrections',
            'id' => 'CrwdcId',
            'session' => 'CrwdcSession',
        ),
    );

    $deleted = array('sessions' => count($ids));

    try {
        if (!mysqli_begin_transaction($connection)) {
            throw new RuntimeException('Could not start cleanup transaction.');
        }

        $transactionOpen = true;

        $result = $query(
            'SELECT CrwsId, '
            . implode(', ', array_keys($metadataFields))
            . ' FROM CustomArcheryWeatherSessions'
            . ' WHERE CrwsId IN (' . $idList . ')'
            . ' ORDER BY CrwsId FOR UPDATE'
        );

        $found = 0;

        while ($row = mysqli_fetch_assoc($result)) {
            $id = (int) $row['CrwsId'];
            $snapshot = $snapshots[$id];
            $found++;

            if (
                $row['CrwsEndedEpoch'] === null
                || !in_array(
                    $row['CrwsResearchStatus'],
                    array('test', 'excluded'),
                    true
                )
            ) {
                throw new InvalidArgumentException(
                    'Session #' . $id . ' is no longer eligible. '
                    . 'Nothing has been deleted. Review your selection again.'
                );
            }

            foreach ($metadataFields as $column => $key) {
                if (
                    !array_key_exists($key, $snapshot)
                    || (string) $row[$column] !== (string) $snapshot[$key]
                ) {
                    throw new InvalidArgumentException(
                        'Session #' . $id . ' has changed since your review. '
                        . 'Nothing has been deleted. Review your selection again.'
                    );
                }
            }
        }

        mysqli_free_result($result);

        if ($found !== count($ids)) {
            throw new InvalidArgumentException(
                'A selected session no longer exists. '
                . 'Nothing has been deleted. Review your selection again.'
            );
        }

        // Lock and count the linked records before deleting any of them.
        foreach ($linkedTables as $key => $definition) {
            $counts = array_fill_keys($ids, 0);

            $result = $query(
                'SELECT ' . $definition['session'] . ' AS session_id'
                . ' FROM ' . $definition['table']
                . ' WHERE ' . $definition['session']
                . ' IN (' . $idList . ')'
                . ' ORDER BY ' . $definition['session']
                . ', ' . $definition['id']
                . ' FOR UPDATE'
            );

            while ($row = mysqli_fetch_assoc($result)) {
                $counts[(int) $row['session_id']]++;
            }

            mysqli_free_result($result);

            foreach ($counts as $id => $count) {
                if (
                    !isset($snapshots[$id][$key])
                    || $count !== $snapshots[$id][$key]
                ) {
                    throw new InvalidArgumentException(
                        'The ' . str_replace('_', ' ', $key)
                        . ' count for session #' . $id . ' has changed. '
                        . 'Nothing has been deleted. Review your selection again.'
                    );
                }
            }

            $deleted[$key] = array_sum($counts);
        }

        // All eligibility and count checks have passed.
        foreach ($linkedTables as $key => $definition) {
            $query(
                'DELETE FROM ' . $definition['table']
                . ' WHERE ' . $definition['session']
                . ' IN (' . $idList . ')'
            );

            if (mysqli_affected_rows($connection) !== $deleted[$key]) {
                throw new RuntimeException(
                    'The number of removed records did not match the review.'
                );
            }
        }

        $query(
            "DELETE FROM CustomArcheryWeatherSessions"
            . " WHERE CrwsId IN (" . $idList . ")"
            . " AND CrwsEndedEpoch IS NOT NULL"
            . " AND BINARY CrwsResearchStatus IN ('test', 'excluded')"
        );

        if (mysqli_affected_rows($connection) !== count($ids)) {
            throw new RuntimeException(
                'The number of removed sessions did not match the review.'
            );
        }

        if (!mysqli_commit($connection)) {
            throw new RuntimeException('Cleanup commit could not be confirmed.');
        }

        $transactionOpen = false;

        return $deleted;
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
            'Cleanup could not be confirmed. Check the sessions list before retrying.',
            0,
            $e
        );
    }
}