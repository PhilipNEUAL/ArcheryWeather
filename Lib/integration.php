<?php

// Public, read-only interface for other IANSEO custom modules.
// The calling module must already have loaded IANSEO's bootstrap.

require_once(__DIR__ . '/helpers.php');
require_once(__DIR__ . '/schema.php');
require_once(__DIR__ . '/sessions.php');
require_once(__DIR__ . '/observations.php');
require_once(__DIR__ . '/summary.php');

// Allows callers to check which interface they are using.
function archeryweather_integration_version()
{
    return 1;
}

// Return completed Real sessions belonging to one competition.
function archeryweather_integration_sessions($tournamentId)
{
    $tournamentId = (int) $tournamentId;

    if ($tournamentId <= 0) {
        return array();
    }

    $sessions = array();

    foreach (resultspack_weather_get_completed_sessions() as $session) {
        if (
            (int) $session['tournament_id'] !== $tournamentId
            || $session['research_status'] !== 'real'
            || (int) $session['ended_epoch']
                <= (int) $session['started_epoch']
        ) {
            continue;
        }

        $timezone = trim((string) $session['timezone']);

        if ($timezone === '') {
            $timezone = 'UTC';
        }

        // Supply a ready-to-display label to the calling module.
        $label = 'Session #' . (int) $session['id']
            . ' — '
            . resultspack_weather_format_timestamp(
                $session['started_epoch'],
                $timezone,
                'd/m/Y H:i P'
            )
            . ' to '
            . resultspack_weather_format_timestamp(
                $session['ended_epoch'],
                $timezone,
                'd/m/Y H:i P'
            )
            . ' — '
            . $session['station_name'];

        $sessions[] = array(
            'id' => (int) $session['id'],
            'label' => $label,
        );
    }

    return $sessions;
}

// Return the current summary for one eligible session.
function archeryweather_integration_summary($tournamentId, $sessionId)
{
    $tournamentId = (int) $tournamentId;
    $sessionId = (int) $sessionId;

    $session = $sessionId > 0
        ? resultspack_weather_get_completed_session($sessionId)
        : null;

    if (
        !$session
        || $tournamentId <= 0
        || (int) $session['tournament_id'] !== $tournamentId
        || $session['research_status'] !== 'real'
        || (int) $session['ended_epoch']
            <= (int) $session['started_epoch']
    ) {
        return array(
            'ok' => false,
            'error' =>
                'This session is not available as a completed Real '
                . 'session for this competition.',
        );
    }

    $observations = resultspack_weather_get_observations($sessionId);

    if (!$observations) {
        return array(
            'ok' => false,
            'error' =>
                'This session has no observations within its current window.',
        );
    }

    $environment = resultspack_weather_environmental_summary(
        $observations
    );

    $windContext = resultspack_weather_wind_context(
        $observations,
        $session
    );

    return array(
        'ok' => true,
        'session_id' => $sessionId,
        'observation_count' => count($observations),
        'summary' => resultspack_weather_concise_summary(
            $environment,
            $windContext
        ),
        'humidity_average' =>
            $environment['humidity']['average'] ?? null,
    );
}