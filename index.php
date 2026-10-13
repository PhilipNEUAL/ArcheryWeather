<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/schema.php');

try {
    archeryweather_ensure_schema();
} catch (RuntimeException $exception) {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');

    echo '<h1>ArcheryWeather installation needs attention</h1>';
    echo '<p>'
        . htmlspecialchars(
            $exception->getMessage(),
            ENT_QUOTES,
            'UTF-8'
        )
        . '</p>';

    exit;
}

header('Location: Sessions.php', true, 302);
exit;