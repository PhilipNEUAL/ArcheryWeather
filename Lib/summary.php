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