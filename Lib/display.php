<?php

require_once(__DIR__ . '/helpers.php');
require_once(__DIR__ . '/summary.php');

// Display the environmental summary for one weather session.
function resultspack_weather_render_environmental_summary(
    array $observations,
    array $session
) {
        if ($observations) {
        $environment = resultspack_weather_environmental_summary(
            $observations
        );

            $windContext = resultspack_weather_wind_context(
            $observations,
            $session
        );

        $summaryRows = array(
            'Concise weather summary' =>
                resultspack_weather_concise_summary(
                    $environment,
                    $windContext
                ),
            'Average wind' => 'Not available',
            'Maximum gust' => 'Not available',
            'Prevailing wind from' => 'Not available',
            'Most frequent wind relative to range' => 'Not available',
            'Temperature' => 'Not available',
            'Relative humidity' => 'Not available',
        );

        if ($environment['wind'] !== null) {
            $summaryRows['Average wind'] =
                resultspack_weather_format_number(
                    $environment['wind']['average'],
                    1
                ) . ' m/s';
        }

        if ($environment['gust'] !== null) {
            $summaryRows['Maximum gust'] =
                resultspack_weather_format_number(
                    $environment['gust']['max'],
                    1
                ) . ' m/s';
        }

        if (
            $environment['gust'] !== null
            && $windContext['gust_timestamp'] !== null
        ) {
            $summaryRows['Maximum gust'] .= ' at '
                . resultspack_weather_format_timestamp(
                    $windContext['gust_timestamp'],
                    $session['timezone'],
                    'd/m/Y H:i:s T'
                );
        }

        if ($windContext['prevailing_direction'] !== null) {
            $direction = $windContext['prevailing_direction'];

            $summaryRows['Prevailing wind from'] =
                resultspack_weather_compass_direction($direction)
                . ' ('
                . resultspack_weather_format_number($direction, 1)
                . '°)';
        } elseif ($windContext['direction_count'] > 0) {
            $summaryRows['Prevailing wind from'] =
                'Highly variable; no clear prevailing direction';
        }

        if ($windContext['shooting_bearing'] === null) {
            $summaryRows['Most frequent wind relative to range'] =
                'Shooting bearing not recorded';
        } elseif ($windContext['relative_leaders']) {
            $tied = count($windContext['relative_leaders']) > 1;

            $summaryRows['Most frequent wind relative to range'] =
                implode(' / ', $windContext['relative_leaders'])
                . ($tied ? ' — tied at ' : ' — ')
                . resultspack_weather_format_number(
                    $windContext['relative_percent'],
                    1
                )
                . '%'
                . ($tied ? ' each' : '')
                . ' of valid direction readings';
        }
        
        foreach (array(
            'temperature' => array('Temperature', '°C'),
            'humidity' => array('Relative humidity', '%'),
            'station_pressure' => array('Station pressure', 'hPa'),
            'sea_level_pressure' => array('Sea-level pressure', 'hPa'),
            'solar_radiation' => array('Solar radiation', 'W/m²'),
            'illuminance' => array('Illuminance', 'lux'),
        ) as $key => $display) {
            $values = $environment[$key];

            if ($values === null) {
                $summaryRows[$display[0]] = 'Not available';
                continue;
            }

            $summaryRows[$display[0]] =
                resultspack_weather_format_number(
                    $values['average'],
                    1
                ) . ' ' . $display[1] . ' average; '
                . resultspack_weather_format_number(
                    $values['min'],
                    1
                ) . '–'
                . resultspack_weather_format_number(
                    $values['max'],
                    1
                ) . ' ' . $display[1] . ' range';
        }

        $summaryRows['Maximum UV index'] =
            $environment['uv'] !== null
                ? resultspack_weather_format_number(
                    $environment['uv']['max'],
                    1
                )
                : 'Not available';

        $summaryRows['Recorded rainfall'] =
            $environment['rain'] !== null
                ? resultspack_weather_format_number(
                    $environment['rain']['average']
                        * $environment['rain']['count'],
                    2
                ) . ' mm'
                : 'Not available';

        $summaryRows['Lightning strikes recorded'] =
            $environment['lightning'] !== null
                ? number_format(
                    $environment['lightning']['average']
                        * $environment['lightning']['count'],
                    0
                )
                : 'Not available';

        echo '<br>';
        echo '<table class="Tabella freeWidth">';
        echo '<tr><th class="Main" colspan="2">';
        echo 'Environmental summary';
        echo '</th></tr>';

        echo '<tr><td colspan="2">';
        echo 'Calculated from available stored readings. Averages are per observation; missing readings are excluded.';
        echo '<br>';
        echo ' Prevailing direction is a circular mean of directions, with the session direction correction applied.';
        echo '</td></tr>';

        foreach ($summaryRows as $label => $value) {
            echo '<tr>';
            echo '<td class="Bold">'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
                . '</td>';
            echo '<td>'
                . htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
                . '</td>';
            echo '</tr>';
        }

        echo '</table>';
    }
}