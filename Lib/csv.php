<?php

// Protect text cells from being interpreted as spreadsheet formulas.
// Numeric values are handled separately, so negative measurements stay numeric.
function resultspack_weather_csv_text($value)
{
    $value = (string) $value;

    if (
        preg_match('/^[\x00-\x20]*[=+@-]/', $value)
        || preg_match('/^[\t\r\n]/', $value)
    ) {
        return "'" . $value;
    }

    return $value;
}

// Build an observation CSV from the same package used for JSON export.
function resultspack_weather_transfer_csv(array $package)
{
    $session = $package['session'];
    $tournament = $package['tournament'];
    $quality = $package['quality'];

    $timezone = new DateTimeZone($session['timezone']);

    $localTime = function ($timestamp) use ($timezone) {
        if ($timestamp === null) {
            return '';
        }

        return (new DateTimeImmutable('@' . (int) $timestamp))
            ->setTimezone($timezone)
            ->format('Y-m-d\TH:i:sP');
    };

    $utcTime = function ($timestamp) {
        return $timestamp === null
            ? ''
            : gmdate('Y-m-d\TH:i:s\Z', (int) $timestamp);
    };

    $toMetresPerSecond = function ($value) {
        return $value === null
            ? null
            : round((float) $value * 0.44704, 8);
    };

    $shootingBearing =
        resultspack_weather_effective_shooting_bearing($session);

    $base = array(
        'session_id' => $session['source_session_id'],
        'session_fingerprint' => $package['session_fingerprint'],
        'competition_code' => $tournament['code'] ?? '',
        'competition_name' => $tournament['name'] ?? '',
        'competition_date_from' => $tournament['date_from'] ?? '',
        'competition_date_to' => $tournament['date_to'] ?? '',
        'research_status' => $session['research_status'],
        'station_name' => $session['station_name'],
        'station_id' => $session['station_id'],
        'device_id' => $session['device_id'],
        'timezone' => $session['timezone'],
        'shooting_bearing_deg' => $session['shooting_bearing'],
        'direction_reference_correction_deg' =>
            $session['direction_correction'],
        'corrected_shooting_bearing_deg' => $shootingBearing,
        'direction_verification' => $session['direction_verification'],
        'sensor_height_m' => $session['sensor_height'],
        'station_forward_offset_m' => $session['forward_offset'],
        'station_lateral_offset_m' => $session['lateral_offset'],
        'ground_surface' => $session['ground_surface'],
        'site_exposure' => $session['exposure'],
        'position_notes' => $session['position_notes'],
        'session_started_local' => $localTime($session['started_epoch']),
        'session_ended_local' => $localTime($session['ended_epoch']),
        'session_started_utc' => $utcTime($session['started_epoch']),
        'session_ended_utc' => $utcTime($session['ended_epoch']),
        'session_window_coverage_percent' =>
            $quality['coverage_percent'] ?? null,
        'session_window_missing_observations' =>
            $quality['missing'] ?? null,
    );

    $measurementFields = array(
        'report_interval_minutes' => 'report_interval',
        'wind_lull_mph' => 'wind_lull',
        'wind_avg_mph' => 'wind_avg',
        'wind_gust_mph' => 'wind_gust',
        'wind_direction_from_deg' => 'wind_dir',
        'station_pressure_hpa' => 'station_pressure',
        'sea_level_pressure_hpa' => 'sea_level_pressure',
        'air_temp_c' => 'air_temp',
        'relative_humidity_percent' => 'humidity',
        'illuminance_lux' => 'illuminance',
        'uv_index' => 'uv',
        'solar_radiation_w_m2' => 'solar_radiation',
        'precip_accumulation_mm' => 'precip_accumulation',
        'local_day_precip_mm' => 'local_day_precip',
        'precip_type' => 'precip_type',
        'lightning_strike_count' => 'strike_count',
        'lightning_strike_distance_km' => 'strike_distance',
    );

    $makeRow = function (array $observation) use (
        $base,
        $session,
        $shootingBearing,
        $measurementFields,
        $localTime,
        $utcTime,
        $toMetresPerSecond
    ) {
        $timestamp = $observation['timestamp'] ?? null;

        $windDirection =
            resultspack_weather_effective_wind_direction(
                $observation['wind_dir'] ?? null,
                $session
            );

        $relativeWind = (
            $windDirection !== null
            && $shootingBearing !== null
        )
            ? resultspack_weather_relative_wind(
                $windDirection,
                $shootingBearing
            )
            : null;

        $row = $base;

        $row['observation_time_local'] = $localTime($timestamp);
        $row['observation_time_utc'] = $utcTime($timestamp);
        $row['observation_epoch'] = $timestamp;

        $row['inside_session_window'] = $timestamp === null
            ? null
            : (
                $timestamp >= $session['started_epoch']
                && $timestamp <= $session['ended_epoch']
                    ? 1
                    : 0
            );

        foreach ($measurementFields as $heading => $field) {
            $row[$heading] = $observation[$field] ?? null;
        }

        $row['wind_lull_m_s'] =
            $toMetresPerSecond($observation['wind_lull'] ?? null);

        $row['wind_avg_m_s'] =
            $toMetresPerSecond($observation['wind_avg'] ?? null);

        $row['wind_gust_m_s'] =
            $toMetresPerSecond($observation['wind_gust'] ?? null);

        $row['corrected_wind_direction_from_deg'] = $windDirection;
        $row['relative_wind_angle_deg'] = $relativeWind['angle'] ?? null;
        $row['relative_wind_description'] = $relativeWind['label'] ?? '';

        // Preserve the original stored date without guessing its timezone.
        $row['imported_at_as_stored'] = $observation['imported'] ?? '';

        return $row;
    };

    $handle = fopen('php://temp', 'w+');

    if ($handle === false) {
        throw new RuntimeException('Could not prepare the CSV.');
    }

    try {
        // UTF-8 marker helps Excel display Unicode text correctly.
        if (fwrite($handle, "\xEF\xBB\xBF") !== 3) {
            throw new RuntimeException('Could not write the CSV encoding marker.');
        }

        $writeRow = function (array $values) use ($handle) {
            $cells = array();

            foreach ($values as $value) {
                if ($value === null) {
                    $cells[] = '';
                } elseif (is_float($value)) {
                    $cells[] = json_encode(
                        $value,
                        JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
                    );
                } elseif (is_int($value)) {
                    $cells[] = $value;
                } else {
                    $cells[] = resultspack_weather_csv_text($value);
                }
            }

            if (
                fputcsv($handle, $cells, ',', '"', '', "\r\n") === false
            ) {
                throw new RuntimeException('Could not write a CSV row.');
            }
        };

        // Generate headers even when the session contains no observations.
        $writeRow(array_keys($makeRow(array())));

        foreach ($package['observations'] as $observation) {
            $writeRow(array_values($makeRow($observation)));
        }

        if (!rewind($handle)) {
            throw new RuntimeException('Could not read the completed CSV.');
        }

        $csv = stream_get_contents($handle);

        if ($csv === false) {
            throw new RuntimeException('Could not read the completed CSV.');
        }

        return $csv;
    } finally {
        fclose($handle);
    }
}