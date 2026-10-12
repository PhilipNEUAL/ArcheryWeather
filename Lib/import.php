<?php

// Read one JSON session package without changing the database.
function resultspack_weather_import_decode_json($json)
{
    if (!is_string($json) || trim($json) === '') {
        return array(
            'ok' => false,
            'error' => 'The weather-session file is empty.',
        );
    }

    // Limit each individual JSON package to 20 MiB.
    if (strlen($json) > 20 * 1024 * 1024) {
        return array(
            'ok' => false,
            'error' => 'The weather-session file exceeds the 20 MiB limit.',
        );
    }

    // Allow a UTF-8 byte-order mark added by some text editors.
    if (substr($json, 0, 3) === "\xEF\xBB\xBF") {
        $json = substr($json, 3);
    }

    try {
        $package = json_decode(
            $json,
            true,
            64,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $e) {
        return array(
            'ok' => false,
            'error' => 'The file does not contain valid weather-session JSON.',
        );
    }

    if (
        !is_array($package)
        || ($package['format'] ?? null) !== 'resultspack-weather-session'
    ) {
        return array(
            'ok' => false,
            'error' => 'This is not an ArcheryWeather or ResultsPack weather-session package.',
        );
    }

    if (
        ($package['format_version'] ?? null)
        !== resultspack_weather_transfer_format_version()
    ) {
        return array(
            'ok' => false,
            'error' => 'This weather-session package uses an unsupported format version.',
        );
    }

    if (
        !isset($package['session'])
        || !is_array($package['session'])
        || empty($package['session'])
    ) {
        return array(
            'ok' => false,
            'error' => 'The weather-session package has no valid session metadata.',
        );
    }

    // Decoding succeeded. The contents still need detailed validation.
    return array(
        'ok' => true,
        'package' => $package,
    );
}

// Check session metadata and the structure of the exported sections.
// Individual observations and audit records are checked separately.
function resultspack_weather_import_validate_structure(array $package)
{
    $fail = function ($message) {
        return array(
            'ok' => false,
            'error' => $message,
        );
    };

    $session = $package['session'] ?? null;

    if (!is_array($session) || empty($session)) {
        return $fail('The package has no valid session metadata.');
    }

    foreach (array('station_id', 'started_epoch', 'ended_epoch') as $key) {
        if (
            !isset($session[$key])
            || !is_int($session[$key])
            || $session[$key] <= 0
        ) {
            return $fail(
                'The session contains an invalid ' . $key . ' value.'
            );
        }
    }

    // A device ID may be unavailable in older session records.
    if (
        array_key_exists('device_id', $session)
        && $session['device_id'] !== null
        && (
            !is_int($session['device_id'])
            || $session['device_id'] <= 0
        )
    ) {
        return $fail('The session contains an invalid device ID.');
    }

    if ($session['ended_epoch'] <= $session['started_epoch']) {
        return $fail('The session must end after it starts.');
    }

    $timezone = $session['timezone'] ?? null;

    if (!is_string($timezone) || trim($timezone) === '') {
        return $fail('The session has no valid timezone.');
    }

    try {
        new DateTimeZone($timezone);
    } catch (Exception $e) {
        return $fail('The session timezone is not recognised.');
    }

    $fingerprint = $package['session_fingerprint'] ?? null;

    if (
        !is_string($fingerprint)
        || !preg_match('/\A[a-f0-9]{64}\z/', $fingerprint)
    ) {
        return $fail('The package has no valid session fingerprint.');
    }

    $expectedFingerprint =
        resultspack_weather_transfer_fingerprint($session);

    if (!hash_equals($expectedFingerprint, $fingerprint)) {
        return $fail(
            'The session fingerprint does not match its metadata.'
        );
    }

    if (
        !isset($package['tournament'])
        || !is_array($package['tournament'])
    ) {
        return $fail('The package has no valid competition metadata.');
    }

    $sectionLimits = array(
        'observations' => 100000,
        'events' => 10000,
        'timing_corrections' => 10000,
        'direction_corrections' => 10000,
    );

    foreach ($sectionLimits as $section => $limit) {
        // Older exports may predate direction-correction history.
        if (
            $section === 'direction_corrections'
            && !array_key_exists($section, $package)
        ) {
            continue;
        }

        if (
            !array_key_exists($section, $package)
            || !is_array($package[$section])
        ) {
            return $fail(
                'The package has an invalid ' . $section . ' section.'
            );
        }

        $rows = $package[$section];

        if (count($rows) > $limit) {
            return $fail(
                'The ' . $section . ' section contains too many records.'
            );
        }

        // Lists must have consecutive keys starting at zero.
        if (
            $rows !== array()
            && array_keys($rows) !== range(0, count($rows) - 1)
        ) {
            return $fail(
                'The ' . $section . ' section must be a list of records.'
            );
        }

        foreach ($rows as $index => $row) {
            if (!is_array($row) || empty($row)) {
                return $fail(
                    'Record ' . ($index + 1)
                    . ' in ' . $section . ' is not a valid record.'
                );
            }
        }
    }

    return array('ok' => true);
}

// Validate a stored database date without changing its timezone.
function resultspack_weather_import_valid_datetime($value)
{
    if (
        !is_string($value)
        || !preg_match(
            '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/',
            $value
        )
        || substr($value, 0, 4) < '1000'
    ) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $value,
        new DateTimeZone('UTC')
    );

    return $date !== false
        && $date->format('Y-m-d H:i:s') === $value;
}

// Check observation values without modifying the supplied readings.
function resultspack_weather_import_validate_observations(array $rows)
{
    // Each pair is: maximum absolute stored value, decimal places.
    $decimalFields = array(
        'wind_lull' => array(9999999.999, 3),
        'wind_avg' => array(9999999.999, 3),
        'wind_gust' => array(9999999.999, 3),
        'wind_dir' => array(9999.99, 2),
        'station_pressure' => array(9999999.999, 3),
        'sea_level_pressure' => array(9999999.999, 3),
        'air_temp' => array(9999999.999, 3),
        'humidity' => array(9999.99, 2),
        'illuminance' => array(99999999999.999, 3),
        'uv' => array(9999999.999, 3),
        'solar_radiation' => array(99999999999.999, 3),
        'precip_accumulation' => array(99999999.999999, 6),
        'local_day_precip' => array(99999999.999999, 6),
        'strike_distance' => array(9999999.999, 3),
    );

    $integerFields = array(
        'report_interval' => array(0, 4294967295),
        'precip_type' => array(-2147483648, 2147483647),
        'strike_count' => array(-2147483648, 2147483647),
    );

    $seenTimestamps = array();
    $rowNumber = 0;

    foreach ($rows as $row) {
        $rowNumber++;

        $fail = function ($message) use ($rowNumber) {
            return array(
                'ok' => false,
                'error' => 'Observation ' . $rowNumber . ': ' . $message,
            );
        };

        if (!is_array($row)) {
            return $fail('the record is invalid.');
        }

        $timestamp = $row['timestamp'] ?? null;

        if (!is_int($timestamp) || $timestamp <= 0) {
            return $fail('the timestamp must be a positive integer.');
        }

        if (isset($seenTimestamps[$timestamp])) {
            return $fail('another reading has the same timestamp.');
        }

        $seenTimestamps[$timestamp] = true;

        foreach ($decimalFields as $field => $limits) {
            $value = $row[$field] ?? null;

            // Missing measurements are allowed.
            if ($value === null) {
                continue;
            }

            if (
                (!is_int($value) && !is_float($value))
                || !is_finite((float) $value)
            ) {
                return $fail($field . ' must be a number or null.');
            }

            if (abs($value) > $limits[0]) {
                return $fail($field . ' exceeds the database limit.');
            }

            if ($value != round($value, $limits[1])) {
                return $fail(
                    $field . ' has more decimal places than can be stored.'
                );
            }
        }

        foreach ($integerFields as $field => $limits) {
            $value = $row[$field] ?? null;

            if ($value === null) {
                continue;
            }

            if (
                !is_int($value)
                || $value < $limits[0]
                || $value > $limits[1]
            ) {
                return $fail(
                    $field . ' must be an integer within the database limits, or null.'
                );
            }
        }

        if (
            !resultspack_weather_import_valid_datetime(
                $row['imported'] ?? null
            )
        ) {
            return $fail('the original import date is missing or invalid.');
        }
    }

    return array('ok' => true);
}

// Check judge events and correction history without changing them.
function resultspack_weather_import_validate_history(array $package)
{
    $rules = array(
        'events' => array(
            'timestamps' => array('timestamp'),
            'text' => array(
                'action' => 32,
                'reason' => 64,
                'note' => null,
            ),
            'numbers' => array(),
        ),
        'timing_corrections' => array(
            'timestamps' => array(
                'old_started_epoch',
                'old_ended_epoch',
                'new_started_epoch',
                'new_ended_epoch',
            ),
            'text' => array(
                'timezone' => 64,
                'reason' => null,
            ),
            'numbers' => array(),
        ),
        'direction_corrections' => array(
            'timestamps' => array(),
            'text' => array(
                'verification' => 32,
                'reason' => null,
            ),
            'numbers' => array(
                'old_correction',
                'new_correction',
                'recorded_bearing',
                'verified_bearing',
            ),
        ),
    );

    foreach ($rules as $section => $rule) {
        $rows = $package[$section] ?? array();

        if (!is_array($rows)) {
            return array(
                'ok' => false,
                'error' => 'Invalid ' . $section . ' section.',
            );
        }

        $rowNumber = 0;

        foreach ($rows as $row) {
            $rowNumber++;

            $fail = function ($message) use ($section, $rowNumber) {
                return array(
                    'ok' => false,
                    'error' => $section . ', record '
                        . $rowNumber . ': ' . $message,
                );
            };

            if (!is_array($row)) {
                return $fail('the record is invalid.');
            }

            foreach ($rule['timestamps'] as $field) {
                $value = $row[$field] ?? null;

                if (!is_int($value) || $value <= 0) {
                    return $fail(
                        $field . ' must be a positive integer timestamp.'
                    );
                }
            }

            foreach ($rule['text'] as $field => $characterLimit) {
                $value = $row[$field] ?? null;

                if (!is_string($value)) {
                    return $fail($field . ' must be text.');
                }

                // MySQL TEXT fields have a limit measured in bytes.
                if ($characterLimit === null) {
                    if (strlen($value) > 65535) {
                        return $fail(
                            $field . ' exceeds the database text limit.'
                        );
                    }
                } else {
                    $characterCount = preg_match_all('/./us', $value);

                    if (
                        $characterCount === false
                        || $characterCount > $characterLimit
                    ) {
                        return $fail(
                            $field . ' exceeds its character limit or contains invalid text.'
                        );
                    }
                }
            }

            foreach ($rule['numbers'] as $field) {
                if (!array_key_exists($field, $row)) {
                    return $fail($field . ' is missing.');
                }

                $value = $row[$field];

                // Older corrections may have no recorded bearing.
                if (
                    $field === 'recorded_bearing'
                    && $value === null
                ) {
                    continue;
                }

                if (
                    (!is_int($value) && !is_float($value))
                    || !is_finite((float) $value)
                    || abs($value) > 9999.99
                    || $value != round($value, 2)
                ) {
                    return $fail(
                        $field . ' cannot be stored without changing its value.'
                    );
                }
            }

            if (
                !resultspack_weather_import_valid_datetime(
                    $row['created'] ?? null
                )
            ) {
                return $fail('the original creation date is invalid.');
            }

            if ($section === 'events' && trim($row['action']) === '') {
                return $fail('the event action is empty.');
            }

            if ($section === 'timing_corrections') {
                foreach (array('old', 'new') as $prefix) {
                    if (
                        $row[$prefix . '_ended_epoch']
                        <= $row[$prefix . '_started_epoch']
                    ) {
                        return $fail(
                            $prefix . ' end time must follow its start time.'
                        );
                    }
                }

                if (trim($row['timezone']) === '') {
                    return $fail('the timezone is empty.');
                }

                try {
                    new DateTimeZone($row['timezone']);
                } catch (Exception $e) {
                    return $fail('the timezone is not recognised.');
                }
            }
        }
    }

    return array('ok' => true);
}

// Check session metadata against the database's storage limits.
function resultspack_weather_import_validate_metadata(array $package)
{
    $session = $package['session'];

    $fail = function ($message) {
        return array(
            'ok' => false,
            'error' => 'Session metadata: ' . $message,
        );
    };

    foreach (array('station_id', 'device_id') as $field) {
        if (
            isset($session[$field])
            && $session[$field] > 2147483647
        ) {
            return $fail($field . ' exceeds the database limit.');
        }
    }

    $textFields = array(
        'station_name' => 255,
        'timezone' => 64,
        'direction_verification' => 32,
        'ground_surface' => 32,
        'exposure' => 32,
        'position_notes' => null,
        'research_status' => 16,
    );

    foreach ($textFields as $field => $characterLimit) {
        $value = $session[$field] ?? null;

        if (!is_string($value)) {
            return $fail($field . ' must be text.');
        }

        if ($characterLimit === null) {
            if (strlen($value) > 65535) {
                return $fail($field . ' exceeds the database text limit.');
            }
        } else {
            $characterCount = preg_match_all('/./us', $value);

            if (
                $characterCount === false
                || $characterCount > $characterLimit
            ) {
                return $fail(
                    $field . ' exceeds its character limit or contains invalid text.'
                );
            }
        }
    }

    // Maximum absolute value, decimal places, whether null is allowed.
    $numberFields = array(
        'shooting_bearing' => array(9999.9, 1, true),
        'direction_correction' => array(9999.99, 2, false),
        'sensor_height' => array(999.99, 2, true),
        'forward_offset' => array(99999.99, 2, true),
        'lateral_offset' => array(99999.99, 2, true),
    );

    foreach ($numberFields as $field => $limits) {
        $value = $session[$field] ?? null;

        if ($value === null && $limits[2]) {
            continue;
        }

        if (
            (!is_int($value) && !is_float($value))
            || !is_finite((float) $value)
            || abs($value) > $limits[0]
            || $value != round($value, $limits[1])
        ) {
            return $fail(
                $field . ' cannot be stored without changing its value.'
            );
        }
    }

    if (
        !in_array(
            $session['research_status'],
            array('real', 'test', 'excluded'),
            true
        )
    ) {
        return $fail('the research status is not recognised.');
    }

    if (
        !resultspack_weather_import_valid_datetime(
            $session['created'] ?? null
        )
    ) {
        return $fail('the original creation date is invalid.');
    }

    // Competition details are used for display and matching.
    // Source database IDs will not be used as local database IDs.
    $tournament = $package['tournament'];

    foreach (
        array(
            'code',
            'name',
            'short_name',
            'venue',
            'where',
            'date_from',
            'date_to',
            'type_name',
        ) as $field
    ) {
        if (
            array_key_exists($field, $tournament)
            && (
                !is_string($tournament[$field])
                || strlen($tournament[$field]) > 65535
            )
        ) {
            return $fail(
                'the competition ' . $field . ' field is invalid.'
            );
        }
    }

    return array('ok' => true);
}

// Decode and validate one JSON package. This never writes to the database.
function resultspack_weather_import_check_json($json)
{
    $decoded = resultspack_weather_import_decode_json($json);

    if (!$decoded['ok']) {
        return $decoded;
    }

    $package = $decoded['package'];

    // Structure must pass before the other checks access its contents.
    $check = resultspack_weather_import_validate_structure($package);

    if (!$check['ok']) {
        return $check;
    }

    $check = resultspack_weather_import_validate_metadata($package);

    if (!$check['ok']) {
        return $check;
    }

    $check = resultspack_weather_import_validate_observations(
        $package['observations']
    );

    if (!$check['ok']) {
        return $check;
    }

    $check = resultspack_weather_import_validate_history($package);

    if (!$check['ok']) {
        return $check;
    }

    // Older packages may not contain direction-correction history.
    if (!array_key_exists('direction_corrections', $package)) {
        $package['direction_corrections'] = array();
    }

    return array(
        'ok' => true,
        'package' => $package,
    );
}

// Suggest a local competition and identify existing sessions.
// Source database IDs are deliberately not used for matching.
function resultspack_weather_import_find_local_matches(array $package)
{
    $sourceTournament = $package['tournament'];
    $sourceSession = $package['session'];

    $competitionMatches = array();
    $sessionMatches = array();
    $overlappingSessions = array();

    $sourceCode = trim($sourceTournament['code'] ?? '');
    $sourceFrom = $sourceTournament['date_from'] ?? '';
    $sourceTo = $sourceTournament['date_to'] ?? '';

    // A competition suggestion requires its code AND both dates.
    // Multiple matches remain ambiguous; do not choose one silently.
    foreach (resultspack_weather_fetch_tournament_list() as $tournament) {
        if (
            $sourceCode !== ''
            && $sourceFrom !== ''
            && $sourceTo !== ''
            && strcasecmp(trim($tournament['code']), $sourceCode) === 0
            && $tournament['date_from'] === $sourceFrom
            && $tournament['date_to'] === $sourceTo
        ) {
            $competitionMatches[] = $tournament;
        }
    }

    $sourceFingerprint =
        resultspack_weather_transfer_fingerprint($sourceSession);

    // Search across all competitions, not just the suggested competition.
    foreach (resultspack_weather_get_sessions() as $localSession) {
        $localFingerprint =
            resultspack_weather_transfer_fingerprint($localSession);

        if (hash_equals($sourceFingerprint, $localFingerprint)) {
            $sessionMatches[] = $localSession;
            continue;
        }

        // A timing correction can change the fingerprint.
        // Flag overlapping sessions from the same station for review.
        if (
            $localSession['station_id'] === $sourceSession['station_id']
            && $localSession['started_epoch'] <= $sourceSession['ended_epoch']
            && (
                $localSession['ended_epoch'] === null
                || $localSession['ended_epoch'] >= $sourceSession['started_epoch']
            )
        ) {
            $overlappingSessions[] = $localSession;
        }
    }

    return array(
        'competitions' => $competitionMatches,
        'sessions' => $sessionMatches,
        'overlapping_sessions' => $overlappingSessions,
    );
}

// Read a JSON upload or a ZIP of JSON packages.
// ZIP entries are read directly; nothing is extracted to disk.
function resultspack_weather_import_read_upload($path)
{
    $limit = 20 * 1024 * 1024;
    $size = filesize($path);

    if ($size === false || $size <= 0 || $size > $limit) {
        return array(
            'ok' => false,
            'error' => 'The upload must contain data and be no larger than 20 MiB.',
        );
    }

    $signature = file_get_contents($path, false, null, 0, 4);

    if ($signature === false) {
        throw new RuntimeException('Could not read the upload.');
    }

    // Ordinary JSON goes through our existing checks.
    if (substr($signature, 0, 2) !== 'PK') {
        $json = file_get_contents($path, false, null, 0, $limit + 1);

        if ($json === false) {
            throw new RuntimeException('Could not read the upload.');
        }

        $checked = resultspack_weather_import_check_json($json);

        if (!$checked['ok']) {
            return $checked;
        }

        return array(
            'ok' => true,
            'packages' => array($checked['package']),
        );
    }

    if (!class_exists('ZipArchive')) {
        return array(
            'ok' => false,
            'error' => 'ZIP support is unavailable. Upload individual JSON files instead.',
        );
    }

    $zip = new ZipArchive();

    if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
        return array(
            'ok' => false,
            'error' => 'The ZIP could not be opened or is damaged.',
        );
    }

    try {
        if ($zip->numFiles > 100) {
            throw new InvalidArgumentException(
                'The ZIP contains too many entries.'
            );
        }

        $packages = array();
        $fingerprints = array();
        $expandedBytes = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);

            if ($entry === false) {
                throw new InvalidArgumentException(
                    'A ZIP entry could not be read.'
                );
            }

            $name = $entry['name'];

            // Folder entries contain no session data.
            if (substr($name, -1) === '/') {
                continue;
            }

            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'json') {
                throw new InvalidArgumentException(
                    'The ZIP must contain only JSON session files and folders.'
                );
            }

            if (count($packages) >= 20) {
                throw new InvalidArgumentException(
                    'Please upload no more than 20 sessions at a time.'
                );
            }

            $entrySize = $entry['size'];

            if (
                $entrySize <= 0
                || $entrySize > $limit - $expandedBytes
            ) {
                throw new InvalidArgumentException(
                    'ZIP contents must be non-empty and total no more than 20 MiB when uncompressed.'
                );
            }

            $json = $zip->getFromIndex($index, $entrySize + 1);

            if ($json === false || strlen($json) !== $entrySize) {
                throw new InvalidArgumentException(
                    'A JSON file inside the ZIP could not be read completely.'
                );
            }

            $expandedBytes += strlen($json);
            $checked = resultspack_weather_import_check_json($json);

            if (!$checked['ok']) {
                throw new InvalidArgumentException(
                    'ZIP file "' . $name . '": ' . $checked['error']
                );
            }

            $package = $checked['package'];
            $fingerprint = $package['session_fingerprint'];

            if (isset($fingerprints[$fingerprint])) {
                throw new InvalidArgumentException(
                    'The ZIP contains multiple packages with the same session fingerprint. '
                    . 'Preview these files individually to compare them.'
                );
            }

            $fingerprints[$fingerprint] = true;
            $packages[] = $package;
        }

        if (!$packages) {
            throw new InvalidArgumentException(
                'The ZIP contains no JSON session files.'
            );
        }

        return array(
            'ok' => true,
            'packages' => $packages,
        );
    } catch (InvalidArgumentException $e) {
        return array(
            'ok' => false,
            'error' => $e->getMessage(),
        );
    } finally {
        $zip->close();
    }
}