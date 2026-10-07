<?php

if (!isset($ret['ARCHERYWEATHER'])) {
    $ret['ARCHERYWEATHER'][] = 'ArcheryWeather';
}

$ret['ARCHERYWEATHER'][] =
    'New session|'
    . $CFG->ROOT_DIR
    . 'Modules/Custom/ArcheryWeather/NewSession.php';

$ret['ARCHERYWEATHER'][] =
    'View sessions|'
    . $CFG->ROOT_DIR
    . 'Modules/Custom/ArcheryWeather/Sessions.php';

$ret['ARCHERYWEATHER'][] =
    'Diagnostics|'
    . $CFG->ROOT_DIR
    . 'Modules/Custom/ArcheryWeather/index.php';