<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/transfer.php');
require_once(__DIR__ . '/Lib/import.php');
require_once(__DIR__ . '/Lib/import_save.php');
require_once(__DIR__ . '/Lib/schema.php');
require_once(__DIR__ . '/Lib/sessions.php');
require_once(__DIR__ . '/Lib/ianseo.php');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$error = '';
$package = null;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new InvalidArgumentException(
            'Choose a JSON file from the Import / Export page first.'
        );
    }

    // PHP can discard the entire submission if post_max_size is exceeded.
    if (empty($_POST) && empty($_FILES)) {
        throw new InvalidArgumentException(
            'No upload was received. The file may exceed the server upload limit.'
        );
    }

    $expectedToken =
        $_SESSION['archeryweather_transfer_token'] ?? '';

    $submittedToken = $_POST['form_token'] ?? '';

    if (
        !is_string($expectedToken)
        || $expectedToken === ''
        || !is_string($submittedToken)
        || !hash_equals($expectedToken, $submittedToken)
    ) {
        throw new InvalidArgumentException(
            'The form has expired. Return to Import / Export and try again.'
        );
    }

    session_write_close();

    $upload = $_FILES['weather_package'] ?? null;

    if (
        !is_array($upload)
        || !isset($upload['error'])
        || !is_int($upload['error'])
    ) {
        throw new InvalidArgumentException(
            'No valid file upload was received.'
        );
    }

    if (
        $upload['error'] === UPLOAD_ERR_INI_SIZE
        || $upload['error'] === UPLOAD_ERR_FORM_SIZE
    ) {
        throw new InvalidArgumentException(
            'The file exceeds the server upload limit.'
        );
    }

    if ($upload['error'] === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('Please select a JSON file.');
    }

    if ($upload['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException(
            'The upload did not complete. Please try again.'
        );
    }

    $temporaryPath = $upload['tmp_name'] ?? null;

    if (
        !is_string($temporaryPath)
        || !is_uploaded_file($temporaryPath)
    ) {
        throw new InvalidArgumentException(
            'The uploaded file could not be verified.'
        );
    }

    $result = resultspack_weather_import_read_upload($temporaryPath);

    if (!$result['ok']) {
        throw new InvalidArgumentException($result['error']);
    }

    $previews = array();

    foreach ($result['packages'] as $package) {
        $previews[] = array(
            'package' => $package,
            'matches' =>
                resultspack_weather_import_find_local_matches($package),
        );
    }

    $localTournaments = resultspack_weather_fetch_tournament_list();

    // Retain the checked packages on the server for the import step.
    // Opening a new successful preview replaces the previous pending batch.
    $batchToken = bin2hex(random_bytes(32));

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['archeryweather_pending_import'] = array(
        'token' => $batchToken,
        'created' => time(),
        'packages' => $result['packages'],
    );

    session_write_close();
} catch (InvalidArgumentException $e) {
    $error = $e->getMessage();
} catch (Throwable $e) {
    $error = 'The file could not be checked. No session has been imported.';
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$escape = function ($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
};

$PAGE_TITLE = 'Weather Import Preview';

include('Common/Templates/head.php');

echo '<p><a href="WeatherTransfer.php">'
    . '&larr; Back to Import / Export</a></p>';

if ($error !== '') {
    echo '<div role="alert" style="color:#a12622;'
        . 'border:2px solid #a12622;padding:12px;margin:12px 0;">'
        . $escape($error)
        . '</div>';
} else {
    echo '<p><strong>'
        . count($previews)
        . ' session package(s) checked. Nothing has been imported.'
        . '</strong></p>';

    echo '<form method="post" action="WeatherImport.php">';

    echo '<input type="hidden" name="batch_token" value="'
        . $escape($batchToken) . '">';

    $selectableCount = 0;

    foreach ($previews as $previewIndex => $preview) {
        $package = $preview['package'];
        $localMatches = $preview['matches'];

    $session = $package['session'];
    $tournament = $package['tournament'];
    $timezone = new DateTimeZone($session['timezone']);

    $formatTime = function ($timestamp) use ($timezone) {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone($timezone)
            ->format('d/m/Y H:i:s T');
    };

    $insideWindow = 0;

    foreach ($package['observations'] as $observation) {
        if (
            $observation['timestamp'] >= $session['started_epoch']
            && $observation['timestamp'] <= $session['ended_epoch']
        ) {
            $insideWindow++;
        }
    }

    $observationCount = count($package['observations']);

    $sourceId = $session['source_session_id'] ?? null;

    $competition = trim(
        ($tournament['code'] ?? '')
        . ' '
        . ($tournament['name'] ?? '')
    );

    $previewRows = array(
        'Source session ID' =>
            is_int($sourceId) && $sourceId > 0
                ? $sourceId
                : 'Not supplied',

        'Source competition' =>
            $competition !== '' ? $competition : 'Not supplied',

        'Research status' => ucfirst($session['research_status']),
        'Station' => $session['station_name'],
        'Station ID' => $session['station_id'],
        'Started' => $formatTime($session['started_epoch']),
        'Ended' => $formatTime($session['ended_epoch']),
        'Session timezone' => $session['timezone'],
        'Total exported observations' => $observationCount,
        'Inside the current session window' => $insideWindow,
        'Outside the current session window' =>
            $observationCount - $insideWindow,
        'Judge events' => count($package['events']),
        'Timing corrections' => count($package['timing_corrections']),
        'Direction corrections' => count($package['direction_corrections']),
        'Session fingerprint' => 'Matches the session metadata',
    );

    echo '<p style="color:#216b32"><strong>'
        . 'The file passed the format and storage checks.'
        . '</strong></p>';

    $competitionMatches = $localMatches['competitions'];

    if (count($competitionMatches) === 1) {
        $match = $competitionMatches[0];

        $previewRows['Suggested local competition'] =
            $match['code'] . ' — ' . $match['name']
            . ' (local ID ' . $match['id'] . ')';

        $previewRows['Competition matching'] =
            'Code and both dates match; confirm before importing.';
    } elseif (count($competitionMatches) > 1) {
        $previewRows['Competition matching'] =
            'Several local competitions match. A selection is required.';
    } else {
        $previewRows['Competition matching'] =
            'No matching code and dates found. A local competition must be selected.';
    }

    $matchingIds = array();

    foreach ($localMatches['sessions'] as $match) {
        $matchingIds[] = '#' . $match['id'];
    }

    $previewRows['Existing session check'] =
        $matchingIds
            ? 'Matching session fingerprint: '
                . implode(', ', $matchingIds)
                . '. Existing records will not be overwritten.'
            : 'No existing session has the same fingerprint.';

    $overlappingIds = array();

    foreach ($localMatches['overlapping_sessions'] as $match) {
        $overlappingIds[] = '#' . $match['id'];
    }

    $previewRows['Other sessions requiring review'] =
        $overlappingIds
            ? 'Same station with overlapping times: '
                . implode(', ', $overlappingIds)
            : 'No other sessions overlap at this station.';

    $previewRows['Source ID handling'] =
        'Source IDs are reference labels only. New imports receive new local IDs.';

    echo '<table class="Tabella freeWidth">';
    echo '<tr><th colspan="2" class="Main">Import preview</th></tr>';

    foreach ($previewRows as $label => $value) {
        echo '<tr><th>' . $escape($label) . '</th>'
            . '<td>' . $escape($value) . '</td></tr>';
    }

    echo '</table>';

    if (!empty($localMatches['sessions'])) {
        echo '<p><strong>Existing session found — this package will be skipped.</strong> '
            . 'Its local observations and history will remain unchanged.</p>';
    } elseif (!empty($localMatches['overlapping_sessions'])) {
        echo '<p style="color:#a12622"><strong>'
            . 'Review required — this package will be skipped.'
            . '</strong> Another session at this station overlaps these times.</p>';
    } elseif (!$localTournaments) {
        echo '<p style="color:#a12622">'
            . 'No local competitions are available. Create or load the '
            . 'destination competition in IANSEO first.</p>';
    } else {
        $selectableCount++;

        $suggestedId = count($localMatches['competitions']) === 1
            ? $localMatches['competitions'][0]['id']
            : null;

        $selectId = 'import-tournament-' . $previewIndex;

        echo '<p><label for="' . $selectId . '">'
            . '<strong>Import into local competition:</strong>'
            . '</label> ';

        echo '<select id="' . $selectId . '" '
            . 'name="tournament_ids[' . $previewIndex . ']" required>';

        echo '<option value=""'
            . ($suggestedId === null ? ' selected' : '')
            . '>Choose a competition</option>';

        foreach ($localTournaments as $tournament) {
            $label = $tournament['code']
                . ' — ' . $tournament['name']
                . ' (' . $tournament['date_from']
                . '; local ID ' . $tournament['id'] . ')';

            echo '<option value="' . (int) $tournament['id'] . '"'
                . ($suggestedId === $tournament['id'] ? ' selected' : '')
                . '>' . $escape($label) . '</option>';
        }

        echo '</select></p>';

    }
}

    if ($selectableCount > 0) {
        echo '<p><strong>'
            . $selectableCount
            . ' new session(s) ready to import.</strong></p>';

        echo '<p><button type="submit">'
            . 'Import new sessions'
            . '</button></p>';

        echo '<p>This preview expires after 30 minutes.</p>';
    } else {
        echo '<p><strong>No new sessions are available to import from this upload.</strong></p>';
    }

    echo '</form>';
}

include('Common/Templates/tail.php');