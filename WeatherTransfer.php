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
require_once(__DIR__ . '/Lib/import.php');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['archeryweather_transfer_token'])) {
    $_SESSION['archeryweather_transfer_token'] =
        bin2hex(random_bytes(32));
}

// Allow a session-details link to preselect one session.
$selectedInput = $_GET['session_id'] ?? '';

$selectedSessionId = is_string($selectedInput)
    ? filter_var(
        $selectedInput,
        FILTER_VALIDATE_INT,
        array('options' => array('min_range' => 1))
    )
    : false;

$exportError = $_SESSION['archeryweather_export_error'] ?? '';

$selectedSessionIds =
    $_SESSION['archeryweather_export_selection']
    ?? (
        $selectedSessionId !== false
            ? array($selectedSessionId)
            : array()
    );

unset(
    $_SESSION['archeryweather_export_error'],
    $_SESSION['archeryweather_export_selection']
);

$weatherSessions = resultspack_weather_get_completed_sessions();

$tournamentNames = array();

foreach (resultspack_weather_fetch_tournament_list() as $tournament) {
    $tournamentNames[$tournament['id']] =
        ($tournament['code'] !== ''
            ? $tournament['code'] . ' — '
            : '')
        . $tournament['name'];
}

$PAGE_TITLE = 'Weather Data Import / Export';

include('Common/Templates/head.php');

?>
<style>
#weather-transfer tr.session-row:nth-child(odd) > td {
    background-color: #f5f9fd;
}

#weather-transfer tr.session-row:nth-child(even) > td {
    background-color: #e7f0f9;
}

#weather-transfer tr.session-row:hover > td,
#weather-transfer tr.session-row:focus-within > td {
    background-color: #cfe3f7;
}
</style>

<p><a href="Sessions.php">&larr; Back to weather sessions</a></p>

<form id="weather-transfer-form" method="post" action="WeatherExport.php">
    <input
        type="hidden"
        name="form_token"
        value="<?php
            echo htmlspecialchars(
                $_SESSION['archeryweather_transfer_token'],
                ENT_QUOTES,
                'UTF-8'
            );
        ?>"
    >

    <table class="Tabella freeWidth">
        <tr>
            <th class="Main">Weather data import / export</th>
        </tr>
    </table>

    <h2>Export Weather Session</h2>

    <p>
        <button type="button" id="select-all-sessions">Select all</button>
        <button type="button" id="clear-session-selection">Clear selection</button>
        <span id="selection-count" role="status"></span>
    </p>

    <div style="overflow-x:auto;max-width:100%;">
        <table id="weather-transfer" class="Tabella freeWidth">
            <thead>
                <tr>
                    <th class="Title" scope="col">Select</th>
                    <th class="Title" scope="col">Session</th>
                    <th class="Title" scope="col">Competition</th>
                    <th class="Title" scope="col">Started</th>
                    <th class="Title" scope="col">Research status</th>
                    <th class="Title" scope="col">Observations in window</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$weatherSessions): ?>
                    <tr>
                        <td colspan="6">
                            No completed weather sessions are available.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($weatherSessions as $session): ?>
                        <?php
                        $id = (int) $session['id'];

                        $competitionName =
                            $tournamentNames[$session['tournament_id']]
                            ?? ('Competition ' . $session['tournament_id']);
                        ?>
                        <tr class="session-row">
                            <td>
                                <input
                                    type="checkbox"
                                    name="session_ids[]"
                                    value="<?php echo $id; ?>"
                                    aria-label="Select session <?php echo $id; ?>"
                                    <?php
                                        echo in_array($id, $selectedSessionIds, true)
                                            ? 'checked'
                                            : '';
                                    ?>
                                >
                            </td>
                            <td>
                                <a href="SessionView.php?session_id=<?php echo $id; ?>">
                                    #<?php echo $id; ?> ↗
                                </a>
                            </td>
                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $competitionName,
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                                ?>
                            </td>
                            <td style="white-space:nowrap;">
                                <?php
                                echo htmlspecialchars(
                                    resultspack_weather_format_timestamp(
                                        $session['started_epoch'],
                                        $session['timezone'],
                                        'd/m/Y H:i:s T'
                                    ),
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                                ?>
                            </td>
                            <td>
                                <?php
                                echo htmlspecialchars(
                                    ucfirst($session['research_status']),
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                                ?>
                            </td>
                            <td>
                                <?php
                                echo (int) resultspack_weather_count_observations(
                                    $id
                                );
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <p>
        <button type="submit" name="export_format" value="json">
            JSON
        </button>

        <button type="submit" name="export_format" value="csv">
            CSV
        </button>
    </p>

    <?php if ($exportError !== ''): ?>
        <div
            id="export-error"
            role="alert"
            tabindex="-1"
            style="color:#a12622;border:2px solid #a12622;
                padding:12px;margin:12px 0;"
        >
            <?php
            echo htmlspecialchars($exportError, ENT_QUOTES, 'UTF-8');
            ?>
        </div>

        <script>
            document.getElementById('export-error').focus();
            document.getElementById('export-error').scrollIntoView({
                block: 'center'
            });
        </script>
    <?php endif; ?>
</form>

<script>
(() => {
    const form = document.getElementById('weather-transfer-form');
    const checkboxes = Array.from(
        form.querySelectorAll('input[name="session_ids[]"]')
    );
    const count = document.getElementById('selection-count');

    function updateCount() {
        const selected = checkboxes.filter(box => box.checked).length;
        count.textContent = ` ${selected} of ${checkboxes.length} sessions selected`;
    }

    document.getElementById('select-all-sessions').addEventListener('click', () => {
        checkboxes.forEach(box => {
            box.checked = true;
        });
        updateCount();
    });

    document.getElementById('clear-session-selection').addEventListener('click', () => {
        checkboxes.forEach(box => {
            box.checked = false;
        });
        updateCount();
    });

    checkboxes.forEach(box => {
        box.addEventListener('change', updateCount);
    });

    window.addEventListener('pageshow', updateCount);
    updateCount();
})();
</script>

<h2>Import Weather Session</h2>

<form
    method="post"
    action="WeatherImportPreview.php"
    enctype="multipart/form-data"
>
    <input
        type="hidden"
        name="form_token"
        value="<?php
        echo htmlspecialchars(
            $_SESSION['archeryweather_transfer_token'],
            ENT_QUOTES,
            'UTF-8'
        );
        ?>"
    >

    <p>
        <label for="weather-package"></label>
        <input
            type="file"
            id="weather-package"
            name="weather_package"
            accept=".json,.zip,application/json,application/zip"
            required
        >
    </p>

    <p>
        <button type="submit">Check file and preview</button>
    </p>
</form>

<?php

include('Common/Templates/tail.php');