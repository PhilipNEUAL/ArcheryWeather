<?php

if (!isset($ret['ARCHERYWEATHER'])) {
    $ret['ARCHERYWEATHER'][] = 'ArcheryWeather';
}

$ret['ARCHERYWEATHER'][] =
    'New|'
    . $CFG->ROOT_DIR
    . 'Modules/Custom/ArcheryWeather/NewSession.php';

$ret['ARCHERYWEATHER'][] =
    'View|'
    . $CFG->ROOT_DIR
    . 'Modules/Custom/ArcheryWeather/Sessions.php';

$ret['ARCHERYWEATHER'][] =
    'Import|'
    . $CFG->ROOT_DIR
    . 'Modules/Custom/ArcheryWeather/WeatherTransfer.php';

$ret['ARCHERYWEATHER'][] =
    'Delete|'
    . $CFG->ROOT_DIR
    . 'Modules/Custom/ArcheryWeather/SessionCleanup.php';