<?php

if (!isset($ret['ARCHERYWEATHER'])) {
    $ret['ARCHERYWEATHER'][] = 'ArcheryWeather';
}

$ret['ARCHERYWEATHER'][] =
    'Weather sessions|'
    . $CFG->ROOT_DIR
    . 'Modules/Custom/ArcheryWeather/Sessions.php';

$ret['ARCHERYWEATHER'][] =
    'Create a Test weather session|'
    . $CFG->ROOT_DIR
    . 'Modules/Custom/ArcheryWeather/NewSession.php';

$ret['ARCHERYWEATHER'][] =
    'Diagnostics|'
    . $CFG->ROOT_DIR
    . 'Modules/Custom/ArcheryWeather/index.php';