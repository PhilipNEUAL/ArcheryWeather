<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/config.php');
require_once(__DIR__ . '/Lib/schema.php');
require_once(__DIR__ . '/Lib/sessions.php');
require_once(__DIR__ . '/Lib/observations.php');
require_once(__DIR__ . '/Lib/tempest.php');
require_once(__DIR__ . '/Lib/collection.php');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function archeryweather_live_collect_reply($status, array $data)
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    archeryweather_live_collect_reply(405, array('ok' => false, 'error' => 'Please use the live collection control.'));
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$token = $_POST['form_token'] ?? '';
$expected = $_SESSION['archeryweather_collect_token'] ?? '';
$validToken = is_string($token) && is_string($expected)
    && $expected !== '' && hash_equals($expected, $token);

// Do not hold the browser's PHP session lock during the network request.
session_write_close();

if (!$validToken) {
    archeryweather_live_collect_reply(403, array('ok' => false, 'error' => 'This page has expired. Reload the session viewer.'));
}

$input = $_POST['session_id'] ?? '';
$sessionId = is_string($input)
    ? filter_var($input, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)))
    : false;

if ($sessionId === false) {
    archeryweather_live_collect_reply(400, array('ok' => false, 'error' => 'Invalid weather session.'));
}

$connection = null;
$lockName = '';
$lockHeld = false;
$status = 200;

try {
    global $WRIT_CON;
    if (!$WRIT_CON) {
        $WRIT_CON = safe_w_con();
    }
    $connection = $WRIT_CON;

    $databaseResult = mysqli_query($connection, 'SELECT DATABASE() AS database_name');
    if ($databaseResult === false) {
        throw new RuntimeException('Database check failed.');
    }
    $database = mysqli_fetch_assoc($databaseResult);
    mysqli_free_result($databaseResult);

    $lockName = 'aw_live_' . substr(hash('sha256', $database['database_name'] . '|' . $sessionId), 0, 48);
    $lockSql = "'" . mysqli_real_escape_string($connection, $lockName) . "'";
    $lockResult = mysqli_query($connection, 'SELECT GET_LOCK(' . $lockSql . ', 0) AS acquired');
    if ($lockResult === false) {
        throw new RuntimeException('Collection lock failed.');
    }
    $lock = mysqli_fetch_assoc($lockResult);
    mysqli_free_result($lockResult);
    if (!isset($lock['acquired'])) {
        throw new RuntimeException('Collection lock unavailable.');
    }

    $lockHeld = (int) $lock['acquired'] === 1;

    if (!$lockHeld) {
        $result = array('ok' => true, 'busy' => true, 'message' => 'Another viewer is collecting this session.');
    } else {
        $session = resultspack_weather_get_session($sessionId);

        if (!$session) {
            $status = 404;
            $result = array('ok' => false, 'error' => 'Weather session not found.');
        } elseif ($session['ended_epoch'] !== null) {
            $result = array('ok' => true, 'session_closed' => true, 'message' => 'Session completed. Live collection has stopped.');
        } else {
            $result = resultspack_weather_import_session_observations($sessionId, true);
            if (!empty($result['session_closed'])) {
                $result = array('ok' => true, 'session_closed' => true, 'message' => 'Session completed during retrieval. Live collection has stopped.');
            } elseif (empty($result['ok'])) {
                $status = 502;
            } else {
                $result['stored'] = resultspack_weather_count_observations($sessionId);
                $result['checked_at'] = time();
            }
        }
    }
} catch (Throwable $exception) {
    $status = 500;
    $result = array('ok' => false, 'error' => 'Live retrieval could not be confirmed. Some readings may have been saved; retrying preserves existing readings.');
} finally {
    if ($lockHeld && $connection) {
        try {
            mysqli_query($connection, 'SELECT RELEASE_LOCK(' . "'" . mysqli_real_escape_string($connection, $lockName) . "'" . ')');
        } catch (Throwable $releaseError) {
            // Closing the database connection also releases its named locks.
        }
    }
}

archeryweather_live_collect_reply($status, $result);
