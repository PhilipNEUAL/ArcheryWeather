<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/config.php');
require_once(__DIR__ . '/Lib/helpers.php');
require_once(__DIR__ . '/Lib/ianseo.php');
require_once(__DIR__ . '/Lib/cleanup.php');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['archeryweather_cleanup_token'])) {
    $_SESSION['archeryweather_cleanup_token'] =
        bin2hex(random_bytes(32));
}

$cleanupToken = $_SESSION['archeryweather_cleanup_token'];

session_write_close();

$candidates = resultspack_weather_cleanup_candidates();

$tournamentNames = array();

foreach (resultspack_weather_fetch_tournament_list() as $tournament) {
    $tournamentNames[$tournament['id']] =
        $tournament['code'] . ' — ' . $tournament['name'];
}

$escape = function ($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
};

$totals = array(
    'observations' => 0,
    'events' => 0,
    'timing_corrections' => 0,
    'direction_corrections' => 0,
);

foreach ($candidates as $candidate) {
    foreach ($totals as $key => $total) {
        $totals[$key] += $candidate[$key];
    }
}

$PAGE_TITLE = 'Weather Session Cleanup';

include('Common/Templates/head.php');

?>

<p><a href="Sessions.php">&larr; Back to weather sessions</a></p>

<h2>Weather session cleanup</h2>

<p>
    Only Test and Excluded sessions can be deleted.
</p>

<p>
    Please consider making a backup using
    <a href="WeatherTransfer.php">Import / Export &nearr;</a>.
</p>

<p>
    <strong><?php echo count($candidates); ?> eligible session(s).</strong>
    </p>

<style>
#weather-cleanup tbody tr:nth-child(odd) > td {
    background-color: #f5f9fd;
}

#weather-cleanup tbody tr:nth-child(even) > td {
    background-color: #e7f0f9;
}

#weather-cleanup tbody tr:hover > td {
    background-color: #cfe3f7;
}
</style>

<form id="cleanup-form" method="post" action="SessionDeletePreview.php">
    <input
        type="hidden"
        name="form_token"
        value="<?php echo $escape($cleanupToken); ?>"
    >

    <p>
        <button type="button" id="cleanup-select-all">Select all</button>
        <button type="button" id="cleanup-clear">Clear selection</button>
        <span id="cleanup-count" role="status"></span>
    </p>

<div style="overflow-x:auto;max-width:100%;">
    <table id="weather-cleanup" class="Tabella freeWidth">
        <thead>
            <tr>
                <th class="Title">Select</th>
                <th class="Title">Session</th>
                <th class="Title">Competition</th>
                <th class="Title">Station</th>
                <th class="Title">Started</th>
                <th class="Title">Status</th>
                <th class="Title">All observations</th>
                <th class="Title">Judge events</th>
                <th class="Title">Timing corrections</th>
                <th class="Title">Direction corrections</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$candidates): ?>
                <tr>
                    <td colspan="10">
                        No completed Test or Excluded sessions are available.
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($candidates as $candidate): ?>
                <tr>
                    <td>
                        <input
                            type="checkbox"
                            name="session_ids[]"
                            value="<?php echo $candidate['id']; ?>"
                            aria-label="Select session <?php
                                echo $candidate['id'];
                            ?>"
                        >
                    </td>
                    <td>
                        <a href="SessionView.php?session_id=<?php
                            echo $candidate['id'];
                        ?>">
                            #<?php echo $candidate['id']; ?> &nearr;
                        </a>
                    </td>

                    <td><?php
                        echo $escape(
                            $tournamentNames[$candidate['tournament_id']]
                            ?? ('Competition ' . $candidate['tournament_id'])
                        );
                    ?></td>

                    <td><?php
                        echo $escape($candidate['station_name']);
                    ?></td>

                    <td><?php
                        echo $escape(
                            resultspack_weather_format_timestamp(
                                $candidate['started_epoch'],
                                $candidate['timezone'],
                                'd/m/Y H:i:s T'
                            )
                        );
                    ?></td>

                    <td><?php
                        echo $escape(ucfirst($candidate['research_status']));
                    ?></td>

                    <?php foreach ($totals as $key => $total): ?>
                        <td><?php echo $candidate[$key]; ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>

        <tfoot>
            <tr>
                <th colspan="6">Total linked records</th>
                <?php foreach ($totals as $total): ?>
                    <td><strong><?php echo $total; ?></strong></td>
                <?php endforeach; ?>
            </tr>
        </tfoot>
    </table>
</div>

    <p>
        <button type="submit" id="cleanup-review" disabled>
            Review selected sessions
        </button>
    </p>
</form>

<script>
(() => {
    const form = document.getElementById('cleanup-form');
    const boxes = Array.from(
        form.querySelectorAll('input[name="session_ids[]"]')
    );
    const count = document.getElementById('cleanup-count');
    const button = document.getElementById('cleanup-review');

    function updateSelection() {
        const selected = boxes.filter(box => box.checked).length;
        count.textContent = ` ${selected} of ${boxes.length} sessions selected`;
        button.disabled = selected === 0;
    }

    document.getElementById('cleanup-select-all').addEventListener('click', () => {
        boxes.forEach(box => { box.checked = true; });
        updateSelection();
    });

    document.getElementById('cleanup-clear').addEventListener('click', () => {
        boxes.forEach(box => { box.checked = false; });
        updateSelection();
    });

    boxes.forEach(box => box.addEventListener('change', updateSelection));
    window.addEventListener('pageshow', updateSelection);
    updateSelection();
})();
</script>

<?php

include('Common/Templates/tail.php');