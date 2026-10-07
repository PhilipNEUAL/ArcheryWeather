<?php

// Summarise a list of weather values.
function resultspack_weather_numeric_summary(array $values)
{
    $numbers = array();

    foreach ($values as $value) {
        if ($value !== null && is_numeric($value)) {
            $numbers[] = (float) $value;
        }
    }

    if (!$numbers) {
        return null;
    }

    return array(
        'count' => count($numbers),
        'average' =>
            array_sum($numbers) / count($numbers),
        'min' => min($numbers),
        'max' => max($numbers),
    );
}

// Calculate the mean of compass bearings, since normal arithmetic mean does not work for directions. ie 359° and 1° should average to 0°, not 180°.
function resultspack_weather_circular_mean(array $directions)
{
    $x = 0.0;
    $y = 0.0;
    $count = 0;

    foreach ($directions as $direction) {
        if (
            $direction === null
            || !is_numeric($direction)
        ) {
            continue;
        }

        $radians =
            deg2rad((float) $direction);

        $x += cos($radians);
        $y += sin($radians);
        $count++;
    }

    if ($count === 0) {
        return null;
    }

    // If directions cancel, there is no prevailing direction.
    if (
        abs($x) < 0.000001
        && abs($y) < 0.000001
    ) {
        return null;
    }

    $degrees =
        rad2deg(
            atan2($y, $x)
        );

    if ($degrees < 0) {
        $degrees += 360;
    }

    return resultspack_weather_normalise_direction(
        $degrees
    );
}

// Summarise the environmental readings. Stored wind values are mph; convert to ms for display.
function resultspack_weather_environmental_summary(array $observations)
{
    $windValues = array();
    $gustValues = array();

    foreach ($observations as $observation) {
        $wind = $observation['wind_avg'] ?? null;
        $gust = $observation['wind_gust'] ?? null;

        if ($wind !== null && is_numeric($wind)) {
            $windValues[] = (float) $wind * 0.44704;
        }

        if ($gust !== null && is_numeric($gust)) {
            $gustValues[] = (float) $gust * 0.44704;
        }
    }

    return array(
        'wind' => resultspack_weather_numeric_summary($windValues),
        'gust' => resultspack_weather_numeric_summary($gustValues),
        'temperature' => resultspack_weather_numeric_summary(
            array_column($observations, 'air_temp')
        ),
        'humidity' => resultspack_weather_numeric_summary(
            array_column($observations, 'humidity')
        ),
        'station_pressure' => resultspack_weather_numeric_summary(
            array_column($observations, 'station_pressure')
        ),
        'sea_level_pressure' => resultspack_weather_numeric_summary(
            array_column($observations, 'sea_level_pressure')
        ),
        'solar_radiation' => resultspack_weather_numeric_summary(
            array_column($observations, 'solar_radiation')
        ),
        'illuminance' => resultspack_weather_numeric_summary(
            array_column($observations, 'illuminance')
        ),
        'uv' => resultspack_weather_numeric_summary(
            array_column($observations, 'uv')
        ),
        'rain' => resultspack_weather_numeric_summary(
            array_column($observations, 'precip_accumulation')
        ),
        'lightning' => resultspack_weather_numeric_summary(
            array_column($observations, 'strike_count')
        ),
    );
}

// Find the peak-gust time and corrected prevailing wind direction.
function resultspack_weather_wind_context(
    array $observations,
    array $session
) {
    $maximumGust = null;
    $gustTimestamp = null;
    $sinSum = 0.0;
    $cosSum = 0.0;
    $directionCount = 0;
    $relativeCounts = array();
    $shootingBearing = resultspack_weather_effective_shooting_bearing($session);

    foreach ($observations as $observation) {
        $gust = $observation['wind_gust'] ?? null;
        $timestamp = (int) ($observation['timestamp'] ?? 0);

        if ($gust !== null && is_numeric($gust)) {
            $gust = (float) $gust;

            if ($maximumGust === null || $gust > $maximumGust) {
                $maximumGust = $gust;
                $gustTimestamp = $timestamp > 0 ? $timestamp : null;
            } elseif (
                $gust === $maximumGust
                && $timestamp > 0
                && ($gustTimestamp === null || $timestamp < $gustTimestamp)
            ) {
                // If the maximum repeats, show its earliest recorded time.
                $gustTimestamp = $timestamp;
            }
        }

        $rawDirection = $observation['wind_dir'] ?? null;

        if ($rawDirection === null || !is_numeric($rawDirection)) {
            continue;
        }

        $direction = resultspack_weather_effective_wind_direction(
            (float) $rawDirection,
            $session
        );

        if ($direction === null) {
            continue;
        }

        $radians = deg2rad($direction);
        $sinSum += sin($radians);
        $cosSum += cos($radians);
        $directionCount++;
        if ($shootingBearing !== null) {
            $relative = resultspack_weather_relative_wind(
                $direction,
                $shootingBearing
            );

            if ($relative !== null) {
                $label = $relative['label'];

                $relativeCounts[$label] =
                    ($relativeCounts[$label] ?? 0) + 1;
            }
        }
    }

    $prevailingDirection = null;
    $concentration = null;

    if ($directionCount > 0) {
        $meanSin = $sinSum / $directionCount;
        $meanCos = $cosSum / $directionCount;

        $concentration = sqrt(
            $meanSin * $meanSin + $meanCos * $meanCos
        );

        if ($concentration >= 0.10) {
            $prevailingDirection = fmod(
                rad2deg(atan2($meanSin, $meanCos)) + 360,
                360
            );
        }
    }

    $relativeLeaders = array();
    $relativePercent = null;

    if ($relativeCounts) {
        $highestCount = max($relativeCounts);
        $relativeTotal = array_sum($relativeCounts);

        foreach ($relativeCounts as $label => $count) {
            if ($count === $highestCount) {
                $relativeLeaders[] = $label;
            }
        }

        $relativePercent = 100 * $highestCount / $relativeTotal;
    }

    return array(
        'gust_timestamp' => $gustTimestamp,
        'direction_count' => $directionCount,
        'prevailing_direction' => $prevailingDirection,
        'direction_concentration' => $concentration,
        'shooting_bearing' => $shootingBearing,
        'relative_leaders' => $relativeLeaders,
        'relative_percent' => $relativePercent,
    );
}

// Build a plain-text weather summary for display or export.
// Uses the existing environmental and corrected wind summaries.
function resultspack_weather_concise_summary(
    array $environment,
    array $windContext
) {
    $parts = array();

    $temperature = $environment['temperature'] ?? null;

    if ($temperature !== null) {
        $parts[] =
            resultspack_weather_format_number(
                $temperature['average'],
                1
            ) . ' °C average ('
            . resultspack_weather_format_number(
                $temperature['min'],
                1
            ) . '–'
            . resultspack_weather_format_number(
                $temperature['max'],
                1
            ) . ' °C)';
    } else {
        $parts[] = 'temperature data unavailable';
    }

    $wind = $environment['wind'] ?? null;

    if ($wind !== null) {
        $parts[] = 'wind '
            . resultspack_weather_format_number(
                $wind['average'],
                1
            ) . ' m/s average';
    } else {
        $parts[] = 'wind-speed data unavailable';
    }

    $direction = $windContext['prevailing_direction'] ?? null;

    if ($direction !== null) {
        // Keep rounded bearings in the range 0–359°.
        $roundedDirection = ((int) round($direction)) % 360;

        $parts[] = 'prevailing '
            . resultspack_weather_compass_direction($direction)
            . ' (' . $roundedDirection . '°)';
    } elseif (($windContext['direction_count'] ?? 0) > 0) {
        $parts[] = 'highly variable wind direction';
    } else {
        $parts[] = 'wind-direction data unavailable';
    }

    $rain = $environment['rain'] ?? null;

    if ($rain === null) {
        $parts[] = 'rainfall data unavailable';
    } else {
        $recordedRain = $rain['average'] * $rain['count'];

        if ($recordedRain == 0.0) {
            $parts[] = 'no rain recorded';
        } elseif ($recordedRain > 0 && $recordedRain < 0.01) {
            $parts[] = 'less than 0.01 mm rain recorded';
        } elseif ($recordedRain > 0) {
            $parts[] =
                resultspack_weather_format_number(
                    $recordedRain,
                    2
                ) . ' mm rain recorded';
        } else {
            $parts[] = 'rainfall data needs checking';
        }
    }

    return implode('; ', $parts);
}