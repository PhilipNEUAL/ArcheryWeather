<?php

function resultspack_weather_config()
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $configFile = dirname(__DIR__) . '/WeatherConfig.local.php';

    if (!is_file($configFile)) {
        $config = array();
        return $config;
    }

    $loaded = require $configFile;

    if (!is_array($loaded)) {
        $config = array();
        return $config;
    }

    $config = $loaded;

    return $config;
}

function resultspack_weather_is_configured()
{
    $config = resultspack_weather_config();

    return !empty($config['personal_access_token']);
}

function resultspack_weather_config_summary()
{
    $config = resultspack_weather_config();

    return array(
        'configured' => resultspack_weather_is_configured(),
        'station_id' => $config['station_id'] ?? null,
        'device_id' => $config['device_id'] ?? null,
    );
}

// Return the configured timezone for weather sessions.
function resultspack_weather_timezone()
{
    $config = resultspack_weather_config();

    $timezone = trim(
        (string) ($config['timezone'] ?? 'UTC')
    );

    if ($timezone === '') {
        return 'UTC';
    }

    try {
        new DateTimeZone($timezone);
        return $timezone;
    } catch (Exception $e) {
        return 'UTC';
    }
}