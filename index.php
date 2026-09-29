<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/config.php');
require_once(__DIR__ . '/Lib/helpers.php');
require_once(__DIR__ . '/Lib/tempest.php');
require_once(__DIR__ . '/Lib/ianseo.php');
require_once(__DIR__ . '/Lib/schema.php');
require_once(__DIR__ . '/Lib/sessions.php');
require_once(__DIR__ . '/Lib/observations.php');
require_once(__DIR__ . '/Lib/summary.php');

$PAGE_TITLE = 'Archery Weather';

include('Common/Templates/head.php');

//Heading table
    echo '<table class="Tabella freeWidth">';
    echo '<tr><th class="Main">Archery Weather</th></tr>';
    echo '<tr><td>';
    echo '<b>Standalone Archery Weather module is running.</b>';
    echo '</td></tr>';
    echo '</table>';

    echo '<br>';

//Configuration table
    echo '<table class="Tabella freeWidth">';
    echo '<tr><th class="Main" colspan="2">Configuration test</th></tr>';

    echo '<tr>';
    echo '<td class="Bold">Configured</td>';
    echo '<td>'
        . (resultspack_weather_is_configured() ? 'Yes' : 'No')
        . '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td class="Bold">Timezone</td>';
    echo '<td>'
        . htmlspecialchars(resultspack_weather_timezone())
        . '</td>';
    echo '</tr>';

    echo '</table>';

    echo '<br>';

//Helpers test table
    echo '<table class="Tabella freeWidth">';
    echo '<tr><th class="Main" colspan="2">Helpers test</th></tr>';

    echo '<tr>';
    echo '<td class="Bold">Number formatting</td>';
    echo '<td>'
        . resultspack_weather_format_number(12.3456, 2)
        . '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td class="Bold">Timestamp formatting</td>';
    echo '<td>'
        . htmlspecialchars(
            resultspack_weather_format_timestamp(
                0,
                'UTC',
                'Y-m-d H:i:s'
            )
        )
        . '</td>';
    echo '</tr>';

    $testRelativeWind =
        resultspack_weather_relative_wind(
            90,
            0
        );

    echo '<tr>';
    echo '<td class="Bold">Compass direction</td>';
    echo '<td>'
        . resultspack_weather_compass_direction(90)
        . '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td class="Bold">Relative wind</td>';
    echo '<td>'
        . htmlspecialchars($testRelativeWind['label'])
        . ' (' . $testRelativeWind['angle'] . '°)'
        . '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td class="Bold">Direction normalisation</td>';
    echo '<td>'
        . resultspack_weather_normalise_direction(-1)
        . '°</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td class="Bold">Direction difference</td>';
    echo '<td>'
        . resultspack_weather_direction_difference(56, 359)
        . '°</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td class="Bold">Corrected direction</td>';
    echo '<td>'
        . resultspack_weather_apply_direction_correction(56, -57)
        . '°</td>';
    echo '</tr>';

    $testSession = array(
        'shooting_bearing' => 56,
        'direction_correction' => -57,
    );

    echo '<tr>';
    echo '<td class="Bold">Effective shooting bearing</td>';
    echo '<td>'
        . resultspack_weather_effective_shooting_bearing($testSession)
        . '°</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td class="Bold">Corrected wind direction</td>';
    echo '<td>'
        . resultspack_weather_effective_wind_direction(
            182,
            $testSession
        )
        . '°</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td class="Bold">Verification label</td>';
    echo '<td>'
        . htmlspecialchars(
            resultspack_weather_direction_verification_label(
                'map_satellite'
            )
        )
        . '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td class="Bold">Tempest library</td>';
    echo '<td>'
        . (
            function_exists('resultspack_weather_fetch_stations')
                ? 'Loaded'
                : 'Missing'
        )
        . '</td>';
    echo '</tr>';

    echo '</table>';

// IANSEO database test
    $tournaments = resultspack_weather_fetch_tournament_list();

    echo '<br>';

    echo '<table class="Tabella freeWidth">';
    echo '<tr><th class="Main" colspan="2">IANSEO database test</th></tr>';

    echo '<tr>';
    echo '<td class="Bold">Tournaments found</td>';
    echo '<td>' . count($tournaments) . '</td>';
    echo '</tr>';

    if ($tournaments) {
        $latestTournament = reset($tournaments);

        echo '<tr>';
        echo '<td class="Bold">Most recent tournament</td>';
        echo '<td>'
            . htmlspecialchars(
                ($latestTournament['code'] !== ''
                    ? $latestTournament['code'] . ' — '
                    : '')
                . $latestTournament['name']
            )
            . '</td>';
        echo '</tr>';
    }

    echo '</table>';

// Weather database test
    $weatherStorage =
        resultspack_weather_existing_storage_status();

    echo '<br>';

    echo '<table class="Tabella freeWidth">';
    echo '<tr><th class="Main" colspan="3">Existing weather storage</th></tr>';

    echo '<tr>';
    echo '<th class="Title">Data</th>';
    echo '<th class="Title">Table found</th>';
    echo '<th class="Title">Rows</th>';
    echo '</tr>';

    foreach ($weatherStorage as $key => $storage) {
        echo '<tr>';

        echo '<td class="Bold">'
            . htmlspecialchars(
                ucwords(
                    str_replace('_', ' ', $key)
                )
            )
            . '</td>';

        echo '<td>'
            . ($storage['exists'] ? 'Yes' : 'No')
            . '</td>';

        echo '<td>'
            . (
                $storage['count'] !== null
                    ? (int) $storage['count']
                    : '—'
            )
            . '</td>';

        echo '</tr>';
    }

    echo '</table>';

// Weather session reading test
    $weatherSessions =
        resultspack_weather_get_sessions();
    $completedWeatherSessions =
        resultspack_weather_get_completed_sessions();

    echo '<br>';

    echo '<table class="Tabella freeWidth">';
    echo '<tr><th class="Main" colspan="5">Weather sessions test</th></tr>';

    echo '<tr>';
    echo '<td class="Bold">Completed sessions</td>';
    echo '<td colspan="4">'
        . count($completedWeatherSessions)
        . '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th class="Title">Session</th>';
    echo '<th class="Title">Competition</th>';
    echo '<th class="Title">Started</th>';
    echo '<th class="Title">Status</th>';
    echo '<th class="Title">Observations</th>';
    echo '</tr>';

    foreach ($weatherSessions as $session) {
        $competitionName =
            'Competition ' . $session['tournament_id'];

        foreach ($tournaments as $tournament) {
            if (
                (int) $tournament['id']
                === (int) $session['tournament_id']
            ) {
                $competitionName =
                    ($tournament['code'] !== ''
                        ? $tournament['code'] . ' — '
                        : '')
                    . $tournament['name'];

                break;
            }
        }

        echo '<tr>';

        echo '<td>'
            . (int) $session['id']
            . '</td>';

        echo '<td>'
            . htmlspecialchars($competitionName)
            . '</td>';

        echo '<td>'
            . htmlspecialchars(
                resultspack_weather_format_timestamp(
                    $session['started_epoch'],
                    $session['timezone'],
                    'd/m/Y H:i:s'
                )
            )
            . '</td>';

        echo '<td>'
            . htmlspecialchars(
                ucfirst($session['research_status'])
            )
            . '</td>';

        echo '<td>'
            . resultspack_weather_count_observations(
                $session['id']
            )
            . '</td>';

        echo '</tr>';
    }

    echo '</table>';

// Observation reading test
    $observationTestSession = null;

    foreach ($weatherSessions as $session) {
        if (
            resultspack_weather_count_observations(
                $session['id']
            ) > 0
        ) {
            $observationTestSession = $session;
            break;
        }
    }

    if ($observationTestSession) {
        $testObservations =
            resultspack_weather_get_observations(
                $observationTestSession['id']
            );

        echo '<br>';

        echo '<table class="Tabella freeWidth">';
        echo '<tr><th class="Main" colspan="2">Observation reading test</th></tr>';

        echo '<tr>';
        echo '<td class="Bold">Session</td>';
        echo '<td>'
            . (int) $observationTestSession['id']
            . '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<td class="Bold">Observations returned</td>';
        echo '<td>'
            . count($testObservations)
            . '</td>';
        echo '</tr>';

        if ($testObservations) {
            $firstObservation =
                reset($testObservations);

            $lastObservation =
                end($testObservations);

            $windValues =
                array_column(
                    $testObservations,
                    'wind_avg'
                );

            $windSummary =
                resultspack_weather_numeric_summary(
                    $windValues
                );

            echo '<tr>';
            echo '<td class="Bold">First observation</td>';
            echo '<td>'
                . htmlspecialchars(
                    resultspack_weather_format_timestamp(
                        $firstObservation['timestamp'],
                        $observationTestSession['timezone'],
                        'd/m/Y H:i:s'
                    )
                )
                . '</td>';
            echo '</tr>';

            echo '<tr>';
            echo '<td class="Bold">Last observation</td>';
            echo '<td>'
                . htmlspecialchars(
                    resultspack_weather_format_timestamp(
                        $lastObservation['timestamp'],
                        $observationTestSession['timezone'],
                        'd/m/Y H:i:s'
                    )
                )
                . '</td>';
            echo '</tr>';
        }

        if ($windSummary) {
            echo '<tr>';
            echo '<td class="Bold">Average wind</td>';
            echo '<td>'
                . resultspack_weather_format_number(
                    $windSummary['average'],
                    2
                )
                . ' mph</td>';
            echo '</tr>';

            echo '<tr>';
            echo '<td class="Bold">Maximum average wind</td>';
            echo '<td>'
                . resultspack_weather_format_number(
                    $windSummary['max'],
                    2
                )
                . ' mph</td>';
            echo '</tr>';
        }

        $circularMeanTest =
            resultspack_weather_circular_mean(
                array(359, 1)
            );

        echo '<tr>';
        echo '<td class="Bold">Circular mean test</td>';
        echo '<td>'
            . resultspack_weather_format_number(
                $circularMeanTest,
                1
            )
            . '°</td>';
        echo '</tr>';

        echo '</table>';
    }

    include('Common/Templates/tail.php');