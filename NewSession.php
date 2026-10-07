<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/config.php');
require_once(__DIR__ . '/Lib/tempest.php');
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

$createdSessionId = (int) (
    $_SESSION['archeryweather_created_session'] ?? 0
);
unset($_SESSION['archeryweather_created_session']);

$researchStatus = 'test';

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

        $researchStatus = $_POST['research_status'] ?? '';

        if (!is_string($researchStatus)) {
            $researchStatus = '';
        }

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

                $newSessionId = resultspack_weather_create_session(
                    $tournamentId,
                    $stationId,
                    $stations[$stationId],
                    $researchStatus
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

    <?php if ($error !== ''): ?>
        <tr>
            <td colspan="2">
                <?php echo archeryweather_new_session_escape($error); ?>
            </td>
        </tr>
    <?php endif; ?>

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

            <p>
                Choose Real for competitions. Choose Test for development.
            </p>
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
        <button
            type="submit"
            <?php echo (!$tournaments || !$stations) ? 'disabled' : ''; ?>
        >
            Create session starting now
        </button>
    </p>
</form>

<?php

include('Common/Templates/tail.php');