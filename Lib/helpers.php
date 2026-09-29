<?php

// Format Unix timestamp in the requested weather-session timezone.
function resultspack_weather_format_timestamp(
    $timestamp,
    $timezone = null,
    $format = 'Y-m-d H:i:s'
) {
    if (!is_numeric($timestamp)) {
        return 'Not available';
    }

    if ($timezone === null || trim((string) $timezone) === '') {
        $timezone = resultspack_weather_timezone();
    }

    try {
        $tz = new DateTimeZone((string) $timezone);
    } catch (Exception $e) {
        $tz = new DateTimeZone('UTC');
    }

    $date = new DateTimeImmutable('@' . (int) $timestamp);

    return $date
        ->setTimezone($tz)
        ->format($format);
}

// Format numeric weather value for display.
function resultspack_weather_format_number($value, $decimals = 1)
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return 'Not available';
    }

    return number_format((float) $value, $decimals, '.', '');
}

// Convert compass bearing into 16-point compass direction.
function resultspack_weather_compass_direction($degrees)
{
    if (!is_numeric($degrees)) {
        return 'Unknown';
    }

    $points = array(
        'N', 'NNE', 'NE', 'ENE',
        'E', 'ESE', 'SE', 'SSE',
        'S', 'SSW', 'SW', 'WSW',
        'W', 'WNW', 'NW', 'NNW'
    );

    $degrees = fmod(((float) $degrees + 360), 360);
    $index = (int) floor(($degrees + 11.25) / 22.5) % 16;

    return $points[$index];
}


/* Describe wind direction relative to the shooting direction.
 * Tempest wind direction is the direction the wind is coming FROM.
 * Shooting bearing is the direction from the shooting line towards the targets.*/
function resultspack_weather_relative_wind(
    $windDirection,
    $shootingBearing
) {
    if (!is_numeric($windDirection) || !is_numeric($shootingBearing)) {
        return null;
    }

    $windDirection =
        fmod(((float) $windDirection + 360), 360);

    $shootingBearing =
        fmod(((float) $shootingBearing + 360), 360);

    $relative =
        fmod(
            $windDirection - $shootingBearing + 540,
            360
        ) - 180;

    $absolute = abs($relative);

    if ($absolute <= 22.5) {
        $label = 'Headwind';

    } elseif ($absolute >= 157.5) {
        $label = 'Tailwind';

    } elseif ($relative > 0) {
        if ($absolute < 67.5) {
            $label =
                'Quartering headwind from the right';

        } elseif ($absolute <= 112.5) {
            $label =
                'Right-to-left crosswind';

        } else {
            $label =
                'Quartering tailwind from the right';
        }

    } else {
        if ($absolute < 67.5) {
            $label =
                'Quartering headwind from the left';

        } elseif ($absolute <= 112.5) {
            $label =
                'Left-to-right crosswind';

        } else {
            $label =
                'Quartering tailwind from the left';
        }
    }

    return array(
        'angle' => round($relative, 1),
        'label' => $label,
    );
}

// Normalise a compass direction to 0 <= direction < 360.
function resultspack_weather_normalise_direction($degrees)
{
    if ($degrees === null || $degrees === '' || !is_numeric($degrees)) {
        return null;
    }

    $degrees = fmod((float) $degrees, 360.0);

    if ($degrees < 0) {
        $degrees += 360.0;
    }

    return $degrees;
}

// Return the shortest signed correction from one direction to another. Example: recorded = 56, verified = 359, result = -57.
function resultspack_weather_direction_difference($recorded, $verified)
{
    $recorded = resultspack_weather_normalise_direction($recorded);
    $verified = resultspack_weather_normalise_direction($verified);

    if ($recorded === null || $verified === null) {
        return null;
    }

    $difference = $verified - $recorded;

    while ($difference > 180) {
        $difference -= 360;
    }

    while ($difference <= -180) {
        $difference += 360;
    }

    return $difference;
}

// Apply correction to direction reference without changing raw data.
function resultspack_weather_apply_direction_correction(
    $direction,
    $correction = 0
) {
    $direction = resultspack_weather_normalise_direction($direction);

    if ($direction === null) {
        return null;
    }

    if (!is_numeric($correction)) {
        $correction = 0;
    }

    return resultspack_weather_normalise_direction(
        $direction + (float) $correction
    );
}

// Return the shooting bearing.
function resultspack_weather_effective_shooting_bearing(array $session)
{
    return resultspack_weather_apply_direction_correction(
        $session['shooting_bearing'] ?? null,
        $session['direction_correction'] ?? 0
    );
}

// Return Tempest wind direction corrected to the same geographic reference.
function resultspack_weather_effective_wind_direction(
    $windDirection,
    array $session
) {
    return resultspack_weather_apply_direction_correction(
        $windDirection,
        $session['direction_correction'] ?? 0
    );
}

// Validate how the shooting direction was verified.
function resultspack_weather_direction_verification($value)
{
    $value = strtolower(trim((string) $value));

    $allowed = array(
        'unverified',
        'phone_compass',
        'map_satellite',
        'second_compass',
        'known_site_alignment',
        'surveyed_bearing',
        'other',
    );

    return in_array($value, $allowed, true)
        ? $value
        : 'unverified';
}

// Readable label for verified direction method.
function resultspack_weather_direction_verification_label($value)
{
    $value = resultspack_weather_direction_verification($value);

    $labels = array(
        'unverified' => 'Unverified',
        'phone_compass' => 'Phone compass',
        'map_satellite' => 'Map / satellite',
        'second_compass' => 'Second compass',
        'known_site_alignment' => 'Known site alignment',
        'surveyed_bearing' => 'Surveyed bearing',
        'other' => 'Other',
    );

    return $labels[$value] ?? 'Unverified';
}

// Validate a station offset in metres.
function resultspack_weather_site_offset($value)
{
    if (
        $value === null
        || $value === ''
        || !is_numeric($value)
    ) {
        return null;
    }

    $value = (float) $value;

    if ($value < -1000 || $value > 1000) {
        return null;
    }

    return $value;
}

// Validate the recorded ground-surface category.
function resultspack_weather_ground_surface($value)
{
    $value =
        strtolower(
            trim((string) $value)
        );

    $allowed = array(
        '',
        'grass',
        'artificial_turf',
        'hardstanding',
        'indoor_floor',
        'mixed',
        'other',
    );

    return in_array(
        $value,
        $allowed,
        true
    )
        ? $value
        : '';
}

// Validate the recorded site-exposure category.
function resultspack_weather_site_exposure($value)
{
    $value =
        strtolower(
            trim((string) $value)
        );

    $allowed = array(
        '',
        'open',
        'partly_sheltered',
        'sheltered',
        'indoor',
        'other',
    );

    return in_array(
        $value,
        $allowed,
        true
    )
        ? $value
        : '';
}

// Human-readable fore/aft station position.
function resultspack_weather_forward_offset_label($value)
{
    if (
        $value === null
        || $value === ''
        || !is_numeric($value)
    ) {
        return 'Not recorded';
    }

    $value = (float) $value;

    if (abs($value) < 0.005) {
        return 'On shooting line';
    }

    return resultspack_weather_format_number(
        abs($value),
        1
    )
        . ' m '
        . (
            $value > 0
                ? 'toward targets'
                : 'behind shooting line'
        );
}

// Human-readable lateral station position.
function resultspack_weather_lateral_offset_label($value)
{
    if (
        $value === null
        || $value === ''
        || !is_numeric($value)
    ) {
        return 'Not recorded';
    }

    $value = (float) $value;

    if (abs($value) < 0.005) {
        return 'On field centre line';
    }

    return resultspack_weather_format_number(
        abs($value),
        1
    )
        . ' m '
        . (
            $value > 0
                ? 'right of centre'
                : 'left of centre'
        );
}