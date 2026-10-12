<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/config.php');
require_once(__DIR__ . '/Lib/helpers.php');
require_once(__DIR__ . '/Lib/schema.php');
require_once(__DIR__ . '/Lib/sessions.php');
require_once(__DIR__ . '/Lib/observations.php');
require_once(__DIR__ . '/Lib/ianseo.php');
require_once(__DIR__ . '/Lib/corrections.php');
require_once(__DIR__ . '/Lib/quality.php');
require_once(__DIR__ . '/Lib/transfer.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Please select sessions on the Import / Export page.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$token = $_POST['form_token'] ?? '';
$expectedToken = $_SESSION['archeryweather_transfer_token'] ?? '';

if (
    !is_string($token)
    || !is_string($expectedToken)
    || $expectedToken === ''
    || !hash_equals($expectedToken, $token)
) {
    http_response_code(403);
    exit('This form has expired. Reload the Import / Export page.');
}

$tempPath = null;
$zip = null;
$zipOpen = false;
$sessionIds = array();

// Remove temporary archives even if the request ends unexpectedly.
register_shutdown_function(function () use (&$tempPath) {
    if ($tempPath !== null && is_file($tempPath)) {
        unlink($tempPath);
    }
});

try {
    $inputs = $_POST['session_ids'] ?? array();

    if (!is_array($inputs) || !$inputs) {
        throw new InvalidArgumentException(
            'Please select at least one completed session.'
        );
    }

    foreach ($inputs as $input) {
        $id = is_string($input)
            ? filter_var(
                $input,
                FILTER_VALIDATE_INT,
                array('options' => array('min_range' => 1))
            )
            : false;

        if ($id === false) {
            throw new InvalidArgumentException(
                'The selection contains an invalid session ID.'
            );
        }

        $sessionIds[$id] = $id;
    }

    $sessionIds = array_values($sessionIds);
    $multiple = count($sessionIds) > 1;

    if ($multiple) {
        if (!class_exists('ZipArchive')) {
            throw new InvalidArgumentException(
                'Multiple-session exports require the PHP ZIP extension. '
                . 'You can still export one session at a time as JSON.'
            );
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'aw-export-');

        if ($tempPath === false) {
            $tempPath = null;
            throw new RuntimeException('Could not create temporary archive.');
        }

        $zip = new ZipArchive();

        if ($zip->open($tempPath, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open temporary archive.');
        }

        $zipOpen = true;
    }

    foreach ($sessionIds as $id) {
        $result = resultspack_weather_build_transfer_package($id);

        if (empty($result['ok'])) {
            throw new InvalidArgumentException(
                'Could not export session #' . $id . ': '
                . ($result['error'] ?? 'Package could not be built.')
            );
        }

        $package = $result['package'];

        $json = json_encode(
            $package,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new InvalidArgumentException(
                'Session #' . $id . ' could not be encoded as JSON.'
            );
        }

        $filename = resultspack_weather_transfer_filename(
            $package,
            'json'
        );

        if ($multiple && !$zip->addFromString($filename, $json)) {
            throw new RuntimeException('Could not add session to archive.');
        }
    }

    if ($multiple) {
        $closed = $zip->close();
        $zipOpen = false;

        if (!$closed) {
            throw new RuntimeException('Could not finish archive.');
        }

        $filename = 'ArcheryWeather-sessions-'
            . gmdate('Y-m-d-His')
            . '-UTC.zip';

        $contentType = 'application/zip';
        $length = filesize($tempPath);

        if ($length === false) {
            throw new RuntimeException('Could not read archive size.');
        }
    } else {
        $contentType = 'application/json; charset=UTF-8';
        $length = strlen($json);
    }
} catch (Throwable $exception) {
    if ($zipOpen) {
        try {
            $zip->close();
        } catch (Throwable $closeException) {
            // The shutdown handler will remove the temporary file.
        }
    }

    $_SESSION['archeryweather_export_error'] =
        $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'The export could not be prepared. Please try again.';

    $_SESSION['archeryweather_export_selection'] = $sessionIds;

    session_write_close();

    header('Location: WeatherTransfer.php', true, 303);
    exit;
}

unset(
    $_SESSION['archeryweather_export_error'],
    $_SESSION['archeryweather_export_selection']
);

session_write_close();

header('Content-Type: ' . $contentType);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $length);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');

if ($multiple) {
    readfile($tempPath);
} else {
    echo $json;
}

exit;