<?php

// Make a request to the Tempest REST API.
function resultspack_weather_api_request($path, $query = array())
{
    $config = resultspack_weather_config();

    if (empty($config['personal_access_token'])) {
        return array(
            'ok' => false,
            'error' => 'Tempest access token is not configured.',
        );
    }

    if (!function_exists('curl_init')) {
        return array(
            'ok' => false,
            'error' => 'PHP cURL support is not available.',
        );
    }

    $query['token'] = $config['personal_access_token'];

    $url =
        'https://swd.weatherflow.com/swd/rest/'
        . ltrim($path, '/')
        . '?'
        . http_build_query($query);

    $ch = curl_init();

    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ));

    $body = curl_exec($ch);

    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);

        return array(
            'ok' => false,
            'error' => 'Tempest request failed: ' . $error,
        );
    }

    $httpCode =
        (int) curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

    curl_close($ch);

    $data = json_decode($body, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        return array(
            'ok' => false,
            'error' =>
                'Tempest returned HTTP '
                . $httpCode
                . '.',
        );
    }

    if (!is_array($data)) {
        return array(
            'ok' => false,
            'error' =>
                'Tempest returned an invalid JSON response.',
        );
    }

    return array(
        'ok' => true,
        'data' => $data,
    );
}


// Return the stations available to this Tempest account.
function resultspack_weather_fetch_stations()
{
    return resultspack_weather_api_request(
        'stations'
    );
}


// Return the latest observation for Tempest station.
function resultspack_weather_fetch_latest_observation($stationId)
{
    if (empty($stationId)) {
        return array(
            'ok' => false,
            'error' =>
                'No Tempest station ID was supplied.',
        );
    }

    return resultspack_weather_api_request(
        'observations/stn/'
        . rawurlencode((string) $stationId),
        array(
            'units_temp' => 'c',
            'units_wind' => 'mph',
            'units_pressure' => 'mb',
            'units_precip' => 'mm',
            'units_distance' => 'km',
        )
    );
}


// Fetch minute observations for Tempest station. Times are Unix timestamps in UTC.
function resultspack_weather_fetch_observations(
    $stationId,
    $startEpoch,
    $endEpoch
) {
    if (empty($stationId)) {
        return array(
            'ok' => false,
            'error' =>
                'No Tempest station ID was supplied.',
        );
    }

    $startEpoch = (int) $startEpoch;
    $endEpoch = (int) $endEpoch;

    if ($startEpoch <= 0 || $endEpoch <= 0) {
        return array(
            'ok' => false,
            'error' =>
                'Invalid weather observation time range.',
        );
    }

    if ($endEpoch <= $startEpoch) {
        return array(
            'ok' => false,
            'error' =>
                'Weather observation end time must be after the start time.',
        );
    }

    return resultspack_weather_api_request(
        'observations/stn/'
        . rawurlencode((string) $stationId),
        array(
            'time_start' => $startEpoch,
            'time_end' => $endEpoch,
            'bucket' => 1,
            'units_temp' => 'c',
            'units_wind' => 'mph',
            'units_pressure' => 'mb',
            'units_precip' => 'mm',
            'units_distance' => 'km',
        )
    );
}