<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/config.php');
require_once(__DIR__ . '/Lib/helpers.php');
require_once(__DIR__ . '/Lib/schema.php');
require_once(__DIR__ . '/Lib/sessions.php');
require_once(__DIR__ . '/Lib/corrections.php');

$sessionId =
    isset($_GET['session_id'])
        ? (int) $_GET['session_id']
        : 0;

$session =
    resultspack_weather_get_session(
        $sessionId
    );

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['archeryweather_direction_token'])) {
    $_SESSION['archeryweather_direction_token'] =
        bin2hex(random_bytes(32));
}

$directionFeedback =
    $_SESSION['archeryweather_direction_feedback'][$sessionId]
    ?? null;

unset($_SESSION['archeryweather_direction_feedback'][$sessionId]);

$verifiedInput = '';
$verificationInput = '';
$reasonInput = '';

if (!empty($directionFeedback['error'])) {
    $verifiedInput = $directionFeedback['bearing'];
    $verificationInput = $directionFeedback['verification'];
    $reasonInput = $directionFeedback['reason'];
}

$PAGE_TITLE = 'Direction Reference';

include('Common/Templates/head.php');

echo '<p>';

echo '<a href="SessionView.php?session_id='
    . (int) $sessionId
    . '">';

echo '&larr; Back to weather session';

echo '</a>';

echo '</p>';

if (!$session) {
    echo '<table class="Tabella freeWidth">';

    echo '<tr>';
    echo '<th class="Main">';
    echo 'Direction Reference';
    echo '</th>';
    echo '</tr>';

    echo '<tr>';
    echo '<td>';
    echo 'Weather session not found.';
    echo '</td>';
    echo '</tr>';

    echo '</table>';

    include('Common/Templates/tail.php');
    exit;
}

$effectiveBearing =
    resultspack_weather_effective_shooting_bearing(
        $session
    );

$directionCorrections =
    resultspack_weather_get_direction_corrections(
        $session['id']
    );

echo '<table class="Tabella freeWidth">';

echo '<tr>';
echo '<th class="Main" colspan="2">';
echo 'Direction Reference — Session '
    . (int) $session['id'];
echo '</th>';
echo '</tr>';

echo '<tr>';
echo '<td class="Bold">Recorded shooting bearing</td>';
echo '<td>'
    . (
        $session['shooting_bearing'] !== null
            ? resultspack_weather_format_number(
                $session['shooting_bearing'],
                1
            ) . '°'
            : 'Not recorded'
    )
    . '</td>';
echo '</tr>';

echo '<tr>';
echo '<td class="Bold">Effective shooting bearing</td>';
echo '<td>'
    . (
        $effectiveBearing !== null
            ? resultspack_weather_format_number(
                $effectiveBearing,
                1
            ) . '°'
            : 'Not available'
    )
    . '</td>';
echo '</tr>';

echo '<tr>';
echo '<td class="Bold">Verification method</td>';
echo '<td>'
    . htmlspecialchars(
        resultspack_weather_direction_verification_label(
            $session['direction_verification']
        )
    )
    . '</td>';
echo '</tr>';

echo '<tr>';
echo '<td class="Bold">Current correction</td>';
echo '<td>'
    . resultspack_weather_format_number(
        $session['direction_correction'],
        1
    )
    . '°</td>';
echo '</tr>';

echo '<tr>';
echo '<td class="Bold">Corrections recorded</td>';
echo '<td>'
    . count($directionCorrections)
    . '</td>';
echo '</tr>';

echo '</table>';

echo '<br>';

echo '<table class="Tabella freeWidth">';

echo '<tr>';
echo '<th class="Main" colspan="7">';
echo 'Direction correction audit trail';
echo ' (' . count($directionCorrections) . ')';
echo '</th>';
echo '</tr>';

echo '<tr>';
echo '<th class="Title">Recorded</th>';
echo '<th class="Title">Recorded bearing</th>';
echo '<th class="Title">Verified bearing</th>';
echo '<th class="Title">Previous correction</th>';
echo '<th class="Title">New correction</th>';
echo '<th class="Title">Verification</th>';
echo '<th class="Title">Reason</th>';
echo '</tr>';

if (!$directionCorrections) {
    echo '<tr>';

    echo '<td colspan="7">';
    echo 'No direction corrections have been recorded for this session.';
    echo '</td>';

    echo '</tr>';
} else {
    foreach ($directionCorrections as $correction) {
        echo '<tr>';

        echo '<td>'
            . htmlspecialchars(
                $correction['created']
            )
            . '</td>';

        echo '<td>'
            . (
                $correction['recorded_bearing'] !== null
                    ? resultspack_weather_format_number(
                        $correction['recorded_bearing'],
                        1
                    ) . '°'
                    : 'Not recorded'
            )
            . '</td>';

        echo '<td>'
            . resultspack_weather_format_number(
                $correction['verified_bearing'],
                1
            )
            . '°</td>';

        echo '<td>'
            . resultspack_weather_format_number(
                $correction['old_correction'],
                1
            )
            . '°</td>';

        echo '<td>'
            . resultspack_weather_format_number(
                $correction['new_correction'],
                1
            )
            . '°</td>';

        echo '<td>'
            . htmlspecialchars(
                resultspack_weather_direction_verification_label(
                    $correction['verification']
                )
            )
            . '</td>';

        echo '<td>'
            . nl2br(
                htmlspecialchars(
                    $correction['reason']
                )
            )
            . '</td>';

        echo '</tr>';
    }
}

echo '</table>';

// New direction correction form.
    echo '<br>';

    echo '<form id="direction-correction-form" '
        . 'method="post" action="DirectionReferenceSave.php">';

    echo '<input type="hidden" name="session_id" value="'
        . (int) $session['id']
        . '">';

    echo '<input type="hidden" name="form_token" value="'
        . htmlspecialchars(
            $_SESSION['archeryweather_direction_token'],
            ENT_QUOTES,
            'UTF-8'
        )
        . '">';

    echo '<input type="hidden" name="expected_correction" value="'
        . htmlspecialchars(
            (string) $session['direction_correction'],
            ENT_QUOTES,
            'UTF-8'
        )
        . '">';

    echo '<input type="hidden" name="expected_verification" value="'
        . htmlspecialchars(
            $session['direction_verification'],
            ENT_QUOTES,
            'UTF-8'
        )
        . '">';

    echo '<input type="hidden" name="expected_audit_id" value="'
        . (
            $directionCorrections
                ? (int) $directionCorrections[0]['id']
                : 0
        )
        . '">';

    echo '<table class="Tabella freeWidth">';

    echo '<tr>';
    echo '<th class="Main" colspan="2">';
    echo 'New Direction Correction';
    echo '</th>';
    echo '</tr>';

    echo '<tr><td colspan="2">';
    echo 'Enter the independently verified shooting bearing: ';
    echo 'the direction from the shooting line towards the targets. ';
    echo 'Enter the bearing itself, not the correction angle. ';
    echo 'The correction will be calculated from the original recorded bearing ';
    echo 'and applied to both the shooting reference and displayed wind directions. ';
    echo 'Raw observations remain unchanged.';
    echo '</td></tr>';

    if ($session['ended_epoch'] === null) {
        echo '<tr><td colspan="2">';
        echo '<strong>End this session before correcting its direction reference.</strong>';
        echo '</td></tr>';
    } elseif ($session['shooting_bearing'] === null) {
        echo '<tr><td colspan="2">';
        echo '<strong>This session has no original shooting bearing to correct.</strong>';
        echo '</td></tr>';
    }

    echo '<tr>';
    echo '<td class="Bold">Verified shooting bearing</td>';
    echo '<td>';
    echo '<input type="number" ';
    echo 'name="verified_bearing" ';
    echo 'min="0" max="359.99" step="0.01" ';
    echo 'value="'
        . htmlspecialchars($verifiedInput, ENT_QUOTES, 'UTF-8')
        . '" ';
    echo 'required> °';
    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td class="Bold">Verification method</td>';
    echo '<td>';

    $verificationOptions = array(
        '' => 'Choose verification method...',
        'map_satellite' => 'Map / satellite',
        'second_compass' => 'Second compass',
        'known_site_alignment' => 'Known site alignment',
        'surveyed_bearing' => 'Surveyed bearing',
        'other' => 'Other independent method',
    );

    echo '<select name="verification" required>';

    foreach ($verificationOptions as $value => $label) {
        echo '<option value="'
            . htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
            . '"'
            . ($verificationInput === $value ? ' selected' : '')
            . '>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</option>';
    }

    echo '</select>';

    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td class="Bold">Reason for correction</td>';
    echo '<td>';

    echo '<textarea ';
    echo 'name="correction_reason" ';
    echo 'rows="3" maxlength="2000" ';
    echo 'required ';
    echo 'placeholder="Explain why this correction is necessary">';
    echo htmlspecialchars($reasonInput, ENT_QUOTES, 'UTF-8');
    echo '</textarea>';

    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td colspan="2">';

    $canCorrectDirection =
        $session['ended_epoch'] !== null
        && $session['shooting_bearing'] !== null;

    echo '<input type="submit" '
        . 'value="Save direction correction"'
        . ($canCorrectDirection ? '' : ' disabled')
        . '>';

    echo '</td>';
    echo '</tr>';

    echo '</table>';
    echo '</form>';

    echo <<<'HTML'
    <p id="direction-save-progress" role="status" hidden>
        Please wait: saving the direction correction and audit entry…
    </p>

    <script>
    (() => {
        const form = document.getElementById('direction-correction-form');
        const button = form.querySelector('input[type="submit"]');
        const progress = document.getElementById('direction-save-progress');
        const initiallyDisabled = button.disabled;

        form.addEventListener('submit', (event) => {
            if (initiallyDisabled || form.dataset.saving === 'yes') {
                event.preventDefault();
                return;
            }

            form.dataset.saving = 'yes';
            button.disabled = true;
            button.value = 'Saving…';
            progress.hidden = false;
        });

        window.addEventListener('pageshow', () => {
            delete form.dataset.saving;
            button.disabled = initiallyDisabled;
            button.value = 'Save direction correction';
            progress.hidden = true;
        });
    })();
    </script>
    HTML;

    if ($directionFeedback) {
        $feedbackColour = !empty($directionFeedback['error'])
            ? '#a12622'
            : '#245b2a';

        echo '<div id="direction-feedback" tabindex="-1" role="status" '
            . 'style="padding:12px;margin:12px 0;border:2px solid '
            . $feedbackColour . ';color:' . $feedbackColour . ';">'
            . htmlspecialchars(
                $directionFeedback['message'],
                ENT_QUOTES,
                'UTF-8'
            )
            . '</div>';

        echo '<script>'
            . 'document.getElementById("direction-feedback").focus();'
            . 'document.getElementById("direction-feedback").scrollIntoView({block:"center"});'
            . '</script>';
    }

include('Common/Templates/tail.php');