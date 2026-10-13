<?php

// Convert a local event time to an epoch without guessing during DST changes.
function resultspack_weather_event_parse_local_time($value, $timezoneName)
{
    if (
        !is_string($value)
        || preg_match(
            '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}(?::[0-9]{2})?\z/',
            $value
        ) !== 1
    ) {
        throw new InvalidArgumentException(
            'Enter a valid event date and time.'
        );
    }

    // Browsers may omit seconds when they are zero.
    if (strlen($value) === 16) {
        $value .= ':00';
    }

    if (!is_string($timezoneName) || trim($timezoneName) === '') {
        throw new InvalidArgumentException(
            'The session has no valid timezone.'
        );
    }

    try {
        $timezone = new DateTimeZone($timezoneName);
    } catch (Exception $e) {
        throw new InvalidArgumentException(
            'The session timezone is not recognised.'
        );
    }

    // Parse the clock fields without applying the session timezone yet.
    $clock = DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i:s',
        $value,
        new DateTimeZone('UTC')
    );

    if (
        $clock === false
        || $clock->format('Y-m-d\TH:i:s') !== $value
    ) {
        throw new InvalidArgumentException(
            'The event date or time is invalid.'
        );
    }

    $clockEpoch = $clock->getTimestamp();

    // Gather nearby timezone offsets, including both sides of a DST change.
    $offsets = array($timezone->getOffset($clock));

    $transitions = $timezone->getTransitions(
        $clockEpoch - (3 * 86400),
        $clockEpoch + (3 * 86400)
    );

    if (is_array($transitions)) {
        foreach ($transitions as $transition) {
            $offsets[] = (int) $transition['offset'];
        }
    }

    $matches = array();

    foreach (array_unique($offsets) as $offset) {
        $candidateEpoch = $clockEpoch - $offset;

        $candidate = (new DateTimeImmutable('@' . $candidateEpoch))
            ->setTimezone($timezone);

        if ($candidate->format('Y-m-d\TH:i:s') === $value) {
            $matches[$candidateEpoch] = $candidateEpoch;
        }
    }

    if (!$matches) {
        throw new InvalidArgumentException(
            'That local time does not exist because the clocks changed. '
            . 'Check the event time.'
        );
    }

    if (count($matches) > 1) {
        throw new InvalidArgumentException(
            'That local time occurred twice when the clocks changed. '
            . 'This form cannot yet distinguish the two occurrences.'
        );
    }

    return (int) reset($matches);
}