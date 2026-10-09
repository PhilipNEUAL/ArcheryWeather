<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/config.php');
require_once(__DIR__ . '/Lib/tempest.php');
require_once(__DIR__ . '/Lib/freshness.php');
require_once(__DIR__ . '/Lib/ianseo.php');
require_once(__DIR__ . '/Lib/schema.php');
require_once(__DIR__ . '/Lib/sessions.php');

// Use IANSEO's PHP session, or start one if necessary.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['archeryweather_create_token'])) {
    $_SESSION['archeryweather_create_token'] =
        bin2hex(random_bytes(32));
}

// Escape text displayed in this page.
function archeryweather_new_session_escape($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

$tournaments = resultspack_weather_fetch_tournament_list();
$stationResponse = resultspack_weather_fetch_stations();

$stations = array();
$error = '';

if (empty($stationResponse['ok'])) {
    $error = 'Could not retrieve weather stations. '
        . 'Check your Tempest configuration and connection.';
} else {
    $data = $stationResponse['data'];

    if (
        isset($data['status']['status_code'])
        && (int) $data['status']['status_code'] !== 0
    ) {
        $error = 'Tempest could not provide the station list.';
    } elseif (
        !isset($data['stations'])
        || !is_array($data['stations'])
    ) {
        $error = 'Tempest returned an unexpected station response.';
    } else {
        foreach ($data['stations'] as $station) {
            if (!is_array($station)) {
                continue;
            }

            $stationId = filter_var(
                $station['station_id'] ?? null,
                FILTER_VALIDATE_INT,
                array('options' => array('min_range' => 1))
            );

            if ($stationId === false) {
                continue;
            }

            $stations[$stationId] = (string) (
                $station['station_name'] ?? ('Station ' . $stationId)
            );
        }

        if (!$stations) {
            $error = 'No weather stations were available for this account.';
        }
    }
}

// Allow status checks only for stations returned for this account.
$_SESSION['archeryweather_station_ids'] = array_keys($stations);

$createdSessionId = (int) (
    $_SESSION['archeryweather_created_session'] ?? 0
);
unset($_SESSION['archeryweather_created_session']);

$researchStatus = 'test';
$shootingBearing = '';
$sensorHeight = '';
$forwardOffset = '';
$lateralOffset = '';

$groundSurface = '';
$exposure = '';
$positionNotes = '';

$groundSurfaceOptions = array(
    '' => 'Not recorded',
    'grass' => 'Grass',
    'artificial_turf' => 'Artificial turf',
    'hardstanding' => 'Hardstanding',
    'indoor_floor' => 'Indoor floor',
    'mixed' => 'Mixed',
    'other' => 'Other',
);

$exposureOptions = array(
    '' => 'Not recorded',
    'open' => 'Open',
    'partly_sheltered' => 'Partly sheltered',
    'sheltered' => 'Sheltered',
    'indoor' => 'Indoor',
    'other' => 'Other',
);

$directionVerification = 'unverified';

$verificationMethods = array(
    'unverified' => 'Unverified',
    'phone_compass' => 'Phone compass',
    'map_satellite' => 'Map / satellite',
    'second_compass' => 'Second compass',
    'known_site_alignment' => 'Known site alignment',
    'surveyed_bearing' => 'Surveyed bearing',
    'other' => 'Other',
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['form_token'] ?? '';

    if (
        !is_string($submittedToken)
        || !hash_equals(
            $_SESSION['archeryweather_create_token'],
            $submittedToken
        )
    ) {
        $error = 'This form has expired or has already been submitted. '
            . 'Please use the current form below.';
    } elseif ($error === '') {
        $tournamentInput = $_POST['tournament_id'] ?? '';
        $stationInput = $_POST['station_id'] ?? '';

        $directionVerification =
            $_POST['direction_verification'] ?? '';

        if (!is_string($directionVerification)) {
            $directionVerification = '';
        }

        $groundSurface = $_POST['ground_surface'] ?? '';
        $exposure = $_POST['exposure'] ?? '';
        $positionNotes = $_POST['position_notes'] ?? '';

        if (!is_string($groundSurface)) {
            $groundSurface = 'invalid';
        }

        if (!is_string($exposure)) {
            $exposure = 'invalid';
        }

        if (!is_string($positionNotes)) {
            $positionNotes = null;
        }        
        
        $forwardOffset = $_POST['forward_offset'] ?? '';
        $lateralOffset = $_POST['lateral_offset'] ?? '';

        if (!is_string($forwardOffset)) {
            $forwardOffset = 'invalid';
        }

        if (!is_string($lateralOffset)) {
            $lateralOffset = 'invalid';
        }        
        
        $sensorHeight = $_POST['sensor_height'] ?? '';

        if (!is_string($sensorHeight)) {
            $sensorHeight = 'invalid';
        }
        
        $shootingBearing = $_POST['shooting_bearing'] ?? '';

        if (!is_string($shootingBearing)) {
            $shootingBearing = 'invalid';
        }
        
        $researchStatus = $_POST['research_status'] ?? '';

        if (!is_string($researchStatus)) {
            $researchStatus = '';
        }

        $allowNonLive =
            ($_POST['allow_nonlive_weather'] ?? '') === '1';

        $tournamentId = is_string($tournamentInput)
            ? filter_var(
                $tournamentInput,
                FILTER_VALIDATE_INT,
                array('options' => array('min_range' => 1))
            )
            : false;

        $stationId = is_string($stationInput)
            ? filter_var(
                $stationInput,
                FILTER_VALIDATE_INT,
                array('options' => array('min_range' => 1))
            )
            : false;

        if ($tournamentId === false) {
            $error = 'Please choose a competition.';
        } elseif (
            $stationId === false
            || !array_key_exists($stationId, $stations)
        ) {
            $error = 'Please choose an available weather station.';
        } else {
            try {
                archeryweather_ensure_schema();

                if ($researchStatus === 'real') {
                    $freshness =
                        resultspack_weather_check_station_freshness(
                            $stationId
                        );

                    if (
                        $freshness['status'] !== 'live'
                        && !$allowNonLive
                    ) {
                        throw new InvalidArgumentException(
                            'The session has not been created. The latest station check is '
                            . $freshness['label']
                            . '. Tick "start session" above if you really want to proceed.'
                        );
                    }
                }

                $newSessionId = resultspack_weather_create_session(
                    $tournamentId,
                    $stationId,
                    $stations[$stationId],
                    $researchStatus,
                    $shootingBearing,
                    $directionVerification,
                    $sensorHeight,
                    $forwardOffset,
                    $lateralOffset,
                    $groundSurface,
                    $exposure,
                    $positionNotes
                );

                // Consume the token only after successful creation.
                unset($_SESSION['archeryweather_create_token']);

                $_SESSION['archeryweather_created_session'] =
                    $newSessionId;

                session_write_close();

                header('Location: NewSession.php', true, 303);
                exit;
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            } catch (RuntimeException $exception) {
                $error = 'The session could not be created. '
                    . 'Please check the database connection and permissions.';
            }
        }
    }
}

$PAGE_TITLE = 'New Weather Session';
include('Common/Templates/head.php');

?>

<?php if ($createdSessionId > 0): ?>
    <p>
        <strong>
            Weather session #<?php echo $createdSessionId; ?> created.
        </strong>
        Its start time has been recorded. Observation collection
        has not started yet.

        <a href="SessionView.php?session_id=<?php
            echo (int) $createdSessionId;
        ?>">View this session</a>
    </p>
<?php endif; ?>

<form method="post" action="NewSession.php">
    <input
        type="hidden"
        name="form_token"
        value="<?php
        echo archeryweather_new_session_escape(
            $_SESSION['archeryweather_create_token']
        );
        ?>"
    >

<table class="Tabella freeWidth">
    <tr>
        <th class="Main" colspan="2">New weather session</th>
    </tr>

    <tr>
        <td><label for="tournament_id">Competition</label></td>
        <td>
            <select id="tournament_id" name="tournament_id" required>
                <option value="">Choose a competition</option>

                <?php foreach ($tournaments as $tournament): ?>
                    <option value="<?php echo (int) $tournament['id']; ?>">
                        <?php
                        echo archeryweather_new_session_escape(
                            $tournament['code'] . ' — ' . $tournament['name']
                        );
                        ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if (!$tournaments): ?>
                <p>No competitions were found in IANSEO.</p>
            <?php endif; ?>
        </td>
    </tr>

    <tr>
        <td><label for="station_id">Weather station</label></td>
        <td>
            <select id="station_id" name="station_id" required>
                <option value="">Choose a station</option>

                <?php foreach ($stations as $stationId => $stationName): ?>
                    <option value="<?php echo (int) $stationId; ?>">
                        <?php
                        echo archeryweather_new_session_escape(
                            $stationName . ' — station ' . $stationId
                        );
                        ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>

    <tr>
        <td>Latest station observation</td>
        <td>
            <div id="station-status" role="status" aria-live="polite">
                Select a station to check its latest observation.
            </div>

            <noscript>
                Automatic status checks require JavaScript.
            </noscript>
        </td>
    </tr>

    <tr>
        <td>
            <label for="shooting_bearing">Shooting bearing</label>
        </td>
        <td>
            <input
                type="number"
                id="shooting_bearing"
                name="shooting_bearing"
                min="0"
                max="359.9"
                step="0.1"
                value="<?php
                    echo archeryweather_new_session_escape(
                        $shootingBearing
                    );
                ?>"
            >
            ° towards the targets
        </td>
    </tr>

    <tr>
        <td>
            <label for="direction_verification">
                Bearing verification
            </label>
        </td>
        <td>
            <select
                id="direction_verification"
                name="direction_verification"
                required
            >
                <?php foreach ($verificationMethods as $value => $label): ?>
                    <option
                        value="<?php
                            echo archeryweather_new_session_escape($value);
                        ?>"
                        <?php
                            echo $directionVerification === $value
                                ? 'selected'
                                : '';
                        ?>
                    >
                        <?php
                            echo archeryweather_new_session_escape($label);
                        ?>
                    </option>
                <?php endforeach; ?>
            </select>

        </td>
    </tr>

    <tr>
        <td>
            <label for="sensor_height">Sensor height</label>
        </td>
        <td>
            <input
                type="number"
                id="sensor_height"
                name="sensor_height"
                min="0.01"
                max="20"
                step="0.01"
                value="<?php
                    echo archeryweather_new_session_escape(
                        $sensorHeight
                    );
                ?>"
            >
            m above ground

        </td>
    </tr>

    <tr>
        <td>
            <label for="forward_offset">Station fore/aft position</label>
        </td>
        <td>
            <input
                type="number"
                id="forward_offset"
                name="forward_offset"
                min="-1000"
                max="1000"
                step="0.01"
                value="<?php
                    echo archeryweather_new_session_escape(
                        $forwardOffset
                    );
                ?>"
            >
            m from the shooting line
        </td>
    </tr>

    <tr>
        <td>
            <label for="lateral_offset">Station lateral position</label>
        </td>
        <td>
            <input
                type="number"
                id="lateral_offset"
                name="lateral_offset"
                min="-1000"
                max="1000"
                step="0.01"
                value="<?php
                    echo archeryweather_new_session_escape(
                        $lateralOffset
                    );
                ?>"
            >
            m from the field centre line
        </td>
    </tr>

    <tr>
        <td>
            <label for="ground_surface">Ground surface</label>
        </td>
        <td>
            <select id="ground_surface" name="ground_surface">
                <?php foreach ($groundSurfaceOptions as $value => $label): ?>
                    <option
                        value="<?php
                            echo archeryweather_new_session_escape($value);
                        ?>"
                        <?php
                            echo $groundSurface === $value
                                ? 'selected'
                                : '';
                        ?>
                    >
                        <?php
                            echo archeryweather_new_session_escape($label);
                        ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>

    <tr>
        <td>
            <label for="exposure">Site exposure</label>
        </td>
        <td>
            <select id="exposure" name="exposure">
                <?php foreach ($exposureOptions as $value => $label): ?>
                    <option
                        value="<?php
                            echo archeryweather_new_session_escape($value);
                        ?>"
                        <?php
                            echo $exposure === $value
                                ? 'selected'
                                : '';
                        ?>
                    >
                        <?php
                            echo archeryweather_new_session_escape($label);
                        ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>

    <tr>
        <td>
            <label for="position_notes">Position notes</label>
        </td>
        <td>
            <textarea
                id="position_notes"
                name="position_notes"
                rows="4"
                cols="55"
                maxlength="2000"
                style="max-width:100%;"
            ><?php
                echo archeryweather_new_session_escape(
                    $positionNotes
                );
            ?></textarea>
        </td>
    </tr>

    <tr>
        <td>
            <label for="research_status">Session type</label>
        </td>
        <td>
            <select
                id="research_status"
                name="research_status"
                required
            >
                <option value="test"<?php
                    echo $researchStatus === 'test'
                        ? ' selected'
                        : '';
                ?>>Test</option>

                <option value="real"<?php
                    echo $researchStatus === 'real'
                        ? ' selected'
                        : '';
                ?>>Real</option>
            </select>
        </td>
    </tr>

    <tr>
        <td>Timezone</td>
        <td>
            <?php
            echo archeryweather_new_session_escape(
                resultspack_weather_timezone()
            );
            ?>
        </td>
    </tr>
</table>

    <p>
        <label>
            <input
                type="checkbox"
                name="allow_nonlive_weather"
                value="1"
            >
            Start session even if station is offline
        </label>
    </p>

    <p>
        <button
            type="submit"
            <?php echo (!$tournaments || !$stations) ? 'disabled' : ''; ?>
        >
            Create session starting now
        </button>
    </p>

    <?php if ($error !== ''): ?>
        <div
            id="session-error"
            role="alert"
            tabindex="-1"
            style="color:#a12622;border:2px solid #a12622;
                background:#fff4f4;padding:12px;margin:12px 0;
                font-weight:bold;"
        >
            <?php echo archeryweather_new_session_escape($error); ?>
        </div>

        <script>
            const sessionError = document.getElementById('session-error');
            sessionError.focus();
            sessionError.scrollIntoView({
                block: 'center'
            });
        </script>
    <?php endif; ?>
</form>

<?php

echo '<script src="js/station_status.js" defer></script>';

include('Common/Templates/tail.php');