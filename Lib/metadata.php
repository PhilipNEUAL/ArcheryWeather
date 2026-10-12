<?php

// Choices shared by the metadata editor and its save handler.
function resultspack_weather_metadata_options()
{
    return array(
        'ground_surface' => array(
            '' => 'Not recorded',
            'grass' => 'Grass',
            'artificial_turf' => 'Artificial turf',
            'hardstanding' => 'Hardstanding',
            'indoor_floor' => 'Indoor floor',
            'mixed' => 'Mixed',
            'other' => 'Other',
        ),
        'exposure' => array(
            '' => 'Not recorded',
            'open' => 'Open',
            'partly_sheltered' => 'Partly sheltered',
            'sheltered' => 'Sheltered',
            'indoor' => 'Indoor',
            'other' => 'Other',
        ),
    );
}

// Represent the six editable fields consistently.
// Accepts the session arrays returned by our existing session getter.
function resultspack_weather_metadata_snapshot(array $session)
{
    $snapshot = array();

    foreach (
        array('sensor_height', 'forward_offset', 'lateral_offset')
        as $field
    ) {
        $value = $session[$field] ?? null;

        $snapshot[$field] = $value === null
            ? null
            : number_format((float) $value, 2, '.', '');
    }

    foreach (
        array('ground_surface', 'exposure', 'position_notes')
        as $field
    ) {
        $snapshot[$field] = (string) ($session[$field] ?? '');
    }

    return $snapshot;
}

// Detect changes to the editable metadata since the form was opened.
function resultspack_weather_metadata_fingerprint(array $session)
{
    return hash(
        'sha256',
        json_encode(
            resultspack_weather_metadata_snapshot($session),
            JSON_THROW_ON_ERROR
        )
    );
}

// Validate submitted fields without changing any database records.
function resultspack_weather_metadata_validate(
    array $input,
    array $current = array()
)
{
    $clean = array();

    $numberRules = array(
        'sensor_height' => array('Sensor height', 0.01, 20),
        'forward_offset' => array('Fore/aft position', -1000, 1000),
        'lateral_offset' => array('Lateral position', -1000, 1000),
    );

    foreach ($numberRules as $field => $rule) {
        if (
            !array_key_exists($field, $input)
            || !is_string($input[$field])
        ) {
            throw new InvalidArgumentException(
                $rule[0] . ' must be a number or left blank.'
            );
        }

        $value = trim($input[$field]);

        if ($value === '') {
            $clean[$field] = null;
            continue;
        }

        // Accept ordinary decimals with at most two decimal places.
        if (
            preg_match(
                '/\A[+-]?(?:[0-9]+(?:\.[0-9]{1,2})?|\.[0-9]{1,2})\z/',
                $value
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                $rule[0] . ' must use no more than two decimal places.'
            );
        }

        $number = (float) $value;

        if (
            !is_finite($number)
            || $number < $rule[1]
            || $number > $rule[2]
        ) {
            throw new InvalidArgumentException(
                $rule[0] . ' must be between '
                . $rule[1] . ' and ' . $rule[2]
                . ' metres, or left blank if unknown.'
            );
        }

        $clean[$field] = number_format($number, 2, '.', '');
    }

    foreach (resultspack_weather_metadata_options() as $field => $options) {
        $value = $input[$field] ?? null;

        if (
            !is_string($value)
            || (
                !array_key_exists($value, $options)
                && $value !== ($current[$field] ?? null)
            )
        ) {
            throw new InvalidArgumentException(
                'Choose a valid '
                . ($field === 'ground_surface' ? 'ground surface' : 'site exposure')
                . '.'
            );
        }

        $clean[$field] = $value;
    }

    $notes = $input['position_notes'] ?? null;

    if (
        !is_string($notes)
        || preg_match('/\A.{0,2000}\z/us', $notes) !== 1
    ) {
        throw new InvalidArgumentException(
            'Position notes must contain valid text of no more than 2,000 characters.'
        );
    }

    $clean['position_notes'] = $notes;

    return $clean;
}

// Render the station setup editor.
function resultspack_weather_metadata_render_editor(
    array $session,
    $token,
    $feedback = null
) {
    $escape = function ($value) {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    };

    $values = resultspack_weather_metadata_snapshot($session);

    // Retain submitted values if a save is rejected.
    if (is_array($feedback) && isset($feedback['inputs'])) {
        foreach ($values as $field => $value) {
            if (
                isset($feedback['inputs'][$field])
                && is_string($feedback['inputs'][$field])
            ) {
                $values[$field] = $feedback['inputs'][$field];
            }
        }
    }

    echo '<details style="margin:16px 0;"'
        . ($feedback !== null ? ' open' : '') . '>';

    echo '<summary style="cursor:pointer;">'
        . '<strong>Edit station setup metadata</strong>'
        . '</summary>';

    if (is_array($feedback)) {
        echo '<p role="status" style="color:'
            . (!empty($feedback['ok']) ? '#216b32' : '#a12622')
            . '"><strong>'
            . $escape($feedback['message'] ?? '')
            . '</strong></p>';
    }

    echo '<form method="post" action="SessionMetadataSave.php">';

    echo '<input type="hidden" name="session_id" value="'
        . (int) $session['id'] . '">';

    echo '<input type="hidden" name="form_token" value="'
        . $escape($token) . '">';

    echo '<input type="hidden" name="expected_fingerprint" value="'
        . $escape(resultspack_weather_metadata_fingerprint($session))
        . '">';

    echo '<p>Leave unknown measurements blank. '
        . 'A zero offset means the station is on the reference line.</p>';

    echo '<table class="Tabella freeWidth">';

    $numberFields = array(
        'sensor_height' => array(
            'Sensor height',
            'm above ground',
            '0.01',
            '20',
        ),
        'forward_offset' => array(
            'Station fore/aft position',
            'm from the shooting line',
            '-1000',
            '1000',
        ),
        'lateral_offset' => array(
            'Station lateral position',
            'm from the field centre line',
            '-1000',
            '1000',
        ),
    );

    foreach ($numberFields as $field => $definition) {
        $inputId = 'metadata-' . $field;

        echo '<tr><td><label for="' . $inputId . '">'
            . $escape($definition[0])
            . '</label></td><td>';

        echo '<input type="number" id="' . $inputId . '" '
            . 'name="' . $field . '" '
            . 'min="' . $definition[2] . '" '
            . 'max="' . $definition[3] . '" '
            . 'step="0.01" value="'
            . $escape($values[$field] ?? '') . '"> '
            . $escape($definition[1]);

        echo '</td></tr>';
    }

    foreach (resultspack_weather_metadata_options() as $field => $options) {
        $inputId = 'metadata-' . $field;
        $label = $field === 'ground_surface'
            ? 'Ground surface'
            : 'Site exposure';

        // Show unfamiliar imported values.
        if (!array_key_exists($values[$field], $options)) {
            $options[$values[$field]] =
                'Current imported value: ' . $values[$field];
        }

        echo '<tr><td><label for="' . $inputId . '">'
            . $label . '</label></td><td>';

        echo '<select id="' . $inputId . '" name="' . $field . '">';

        foreach ($options as $value => $optionLabel) {
            echo '<option value="' . $escape($value) . '"'
                . ($values[$field] === $value ? ' selected' : '')
                . '>' . $escape($optionLabel) . '</option>';
        }

        echo '</select></td></tr>';
    }

    echo '<tr><td><label for="metadata-position-notes">'
        . 'Position notes</label></td><td>';

    echo '<textarea id="metadata-position-notes" name="position_notes" '
        . 'rows="4" cols="55" maxlength="2000" style="max-width:100%;">'
        . $escape($values['position_notes'])
        . '</textarea>';

    echo '</td></tr></table>';

    echo '<p><button type="submit">'
        . 'Save station setup metadata'
        . '</button></p>';

    echo '<p>This updates the recorded station setup for the session. '
        . 'Previous metadata values are not retained in an audit history.</p>';

    echo '</form></details>';
}