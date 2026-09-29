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