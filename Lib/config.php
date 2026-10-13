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