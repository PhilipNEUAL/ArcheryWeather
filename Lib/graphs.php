<?php

function resultspack_weather_render_wind_graph(
    array $observations,
    array $events,
    array $session
) {

//Wind graph.
echo '<br>';

echo '<table class="Tabella freeWidth">';
echo '<tr><th class="Main">Wind across the session</th></tr>';
echo '<tr><td>';

$windObservations = array();

foreach ($observations as $observation) {
    if (
        $observation['timestamp'] > 0
        && (
            $observation['wind_lull'] !== null
            || $observation['wind_avg'] !== null
            || $observation['wind_gust'] !== null
        )
    ) {
        $windObservations[] = $observation;
    }
}

if (count($windObservations) < 2) {
    echo 'Not enough locally stored wind observations to draw a graph.';
} else {
    //SVG dimensions
    $graphWidth = 1000;
    $graphHeight = 360;

    $marginLeft = 70;
    $marginRight = 25;
    $marginTop = 30;
    $marginBottom = 55;

    $plotWidth =
        $graphWidth - $marginLeft - $marginRight;

    $plotHeight =
        $graphHeight - $marginTop - $marginBottom;

    //Work out the time range.
    $firstTimestamp =
        (int) $windObservations[0]['timestamp'];

    $lastTimestamp =
        (int) $windObservations[count($windObservations) - 1]['timestamp'];

    $timeRange =
        max(1, $lastTimestamp - $firstTimestamp);

    //Raw Tempest wind observations are stored in mph.
    //Convert them to m/s for presentation in the viewer.
    $windMphToMs = 0.44704;

    /*
     * Build one clean local series in m/s. Keeping the conversion here means
     * the stored research data remains untouched while every part of this graph
     * works in the same display unit.
     */
    $windSeries = array();

    $maximumWind = 0;
    $maximumGustMs = null;
    $maximumGustTimestamp = null;

    foreach ($windObservations as $observation) {
        $lullMs =
            $observation['wind_lull'] !== null
                ? (float) $observation['wind_lull'] * $windMphToMs
                : null;

        $averageMs =
            $observation['wind_avg'] !== null
                ? (float) $observation['wind_avg'] * $windMphToMs
                : null;

        $gustMs =
            $observation['wind_gust'] !== null
                ? (float) $observation['wind_gust'] * $windMphToMs
                : null;

        foreach (array($lullMs, $averageMs, $gustMs) as $candidate) {
            if ($candidate !== null && $candidate > $maximumWind) {
                $maximumWind = $candidate;
            }
        }

        if (
            $gustMs !== null
            && (
                $maximumGustMs === null
                || $gustMs > $maximumGustMs
            )
        ) {
            $maximumGustMs = $gustMs;
            $maximumGustTimestamp =
                (int) $observation['timestamp'];
        }

        $windSeries[] = array(
            'timestamp' => (int) $observation['timestamp'],
            'lull_ms' => $lullMs,
            'average_ms' => $averageMs,
            'gust_ms' => $gustMs,
        );
    }

    //Choose a reader-friendly Y-axis interval.
    if ($maximumWind <= 2.5) {
        $yTickInterval = 0.5;
    } elseif ($maximumWind <= 5) {
        $yTickInterval = 1;
    } elseif ($maximumWind <= 12) {
        $yTickInterval = 2;
    } else {
        $yTickInterval = 5;
    }

    //Round the top of the graph up to the next complete tick.
    $yMaximum =
        max(
            $yTickInterval,
            ceil($maximumWind / $yTickInterval)
            * $yTickInterval
        );

    /*
     * Build:
     * - the upper and lower edges of the lull-to-gust envelope;
     * - a centred five-observation moving average of the minute averages.
     *
     * With the Tempest one-minute data this is effectively a five-minute
     * smoothed average for normal sessions.
     */
    $gustBoundaryPoints = array();
    $lullBoundaryPoints = array();
    $bandUpperPoints = array();
    $bandLowerPoints = array();
    $smoothedAveragePoints = array();

    foreach ($windSeries as $index => $point) {
        $x =
            $marginLeft
            + (
                (($point['timestamp'] - $firstTimestamp) / $timeRange)
                * $plotWidth
            );

        if ($point['gust_ms'] !== null) {
            $gustY =
                $marginTop
                + $plotHeight
                - (
                    ($point['gust_ms'] / $yMaximum)
                    * $plotHeight
                );

            $gustPoint =
                round($x, 2) . ',' . round($gustY, 2);

            $gustBoundaryPoints[] =
                $gustPoint;

            if ($point['lull_ms'] !== null) {
                $bandUpperPoints[] =
                    $gustPoint;
            }
        }

        if ($point['lull_ms'] !== null) {
            $lullY =
                $marginTop
                + $plotHeight
                - (
                    ($point['lull_ms'] / $yMaximum)
                    * $plotHeight
                );

            $lullPoint =
                round($x, 2) . ',' . round($lullY, 2);

            $lullBoundaryPoints[] =
                $lullPoint;

            if ($point['gust_ms'] !== null) {
                $bandLowerPoints[] =
                    $lullPoint;
            }
        }

        //Centred five-point moving average: two readings either side.
        if ($point['average_ms'] !== null) {
            $windowStart =
                max(0, $index - 2);

            $windowEnd =
                min(count($windSeries) - 1, $index + 2);

            $windowTotal = 0.0;
            $windowCount = 0;

            for (
                $windowIndex = $windowStart;
                $windowIndex <= $windowEnd;
                $windowIndex++
            ) {
                if ($windSeries[$windowIndex]['average_ms'] !== null) {
                    $windowTotal +=
                        $windSeries[$windowIndex]['average_ms'];

                    $windowCount++;
                }
            }

            if ($windowCount > 0) {
                $smoothedAverage =
                    $windowTotal / $windowCount;

                $smoothedY =
                    $marginTop
                    + $plotHeight
                    - (
                        ($smoothedAverage / $yMaximum)
                        * $plotHeight
                    );

                $smoothedAveragePoints[] =
                    round($x, 2)
                    . ','
                    . round($smoothedY, 2);
            }
        }
    }

    echo '<div style="max-width:1100px">';

    echo '<svg '
        . 'viewBox="0 0 ' . $graphWidth . ' ' . $graphHeight . '" '
        . 'style="width:100%;height:auto;background:white;border:1px solid #ccc" '
        . 'role="img" '
        . 'aria-label="Lull to gust wind range with a five-observation smoothed average across the weather session">';

    //Horizontal grid lines and Y-axis labels.
    for (
        $value = 0;
        $value <= $yMaximum;
        $value += $yTickInterval
    ) {
        $y =
            $marginTop
            + $plotHeight
            - (($value / $yMaximum) * $plotHeight);

        echo '<line '
            . 'x1="' . $marginLeft . '" '
            . 'y1="' . round($y, 2) . '" '
            . 'x2="' . ($graphWidth - $marginRight) . '" '
            . 'y2="' . round($y, 2) . '" '
            . 'stroke="#dddddd" '
            . 'stroke-width="1" />';

        echo '<text '
            . 'x="' . ($marginLeft - 10) . '" '
            . 'y="' . (round($y, 2) + 5) . '" '
            . 'text-anchor="end" '
            . 'font-size="14">'
            . htmlspecialchars(
                number_format(
                    $value,
                    $yTickInterval < 1 ? 1 : 0
                )
            )
            . '</text>';
    }

    //Y axis title.
    echo '<text '
        . 'x="18" '
        . 'y="' . ($marginTop + ($plotHeight / 2)) . '" '
        . 'transform="rotate(-90 18 '
        . ($marginTop + ($plotHeight / 2))
        . ')" '
        . 'text-anchor="middle" '
        . 'font-size="14">'
        . 'Wind speed (m/s)'
        . '</text>';

    //Create useful clock-time markers.
    if ($timeRange <= (30 * 60)) {
        $timeTickMinutes = 5;
    } elseif ($timeRange <= 3600) {
        $timeTickMinutes = 10;
    } elseif ($timeRange <= (3 * 3600)) {
        $timeTickMinutes = 30;
    } elseif ($timeRange <= (8 * 3600)) {
        $timeTickMinutes = 60;
    } else {
        $timeTickMinutes = 120;
    }

    $timeLabels = array(
        $firstTimestamp
    );

    try {
        $graphTimezone = new DateTimeZone(
            $session['timezone']
                ?: resultspack_weather_timezone()
        );

        $firstLocal =
            (new DateTimeImmutable('@' . $firstTimestamp))
            ->setTimezone($graphTimezone);

        $hourStart =
            $firstLocal->setTime(
                (int) $firstLocal->format('H'),
                0,
                0
            );

        $minutesIntoHour =
            (int) $firstLocal->format('i');

        $stepsIntoNextTick =
            intdiv(
                $minutesIntoHour,
                $timeTickMinutes
            ) + 1;

        $minutesToNextTick =
            $stepsIntoNextTick
            * $timeTickMinutes;

        $nextTick =
            $hourStart->modify(
                '+' . $minutesToNextTick . ' minutes'
            );

        //Avoid round-time labels extremely close to either endpoint.
        $minimumLabelGap =
            min(10 * 60, $timeRange / 8);

        while ($nextTick->getTimestamp() < $lastTimestamp) {
            $tickTimestamp =
                $nextTick->getTimestamp();

            if (
                ($tickTimestamp - $firstTimestamp)
                    >= $minimumLabelGap
                && ($lastTimestamp - $tickTimestamp)
                    >= $minimumLabelGap
            ) {
                $timeLabels[] =
                    $tickTimestamp;
            }

            $nextTick =
                $nextTick->modify(
                    '+' . $timeTickMinutes . ' minutes'
                );
        }
    } catch (Exception $e) {
        //Start and end labels are still sufficient if timezone parsing fails.
    }

    $timeLabels[] =
        $lastTimestamp;

    foreach ($timeLabels as $index => $timestamp) {
        $x =
            $marginLeft
            + (
                (($timestamp - $firstTimestamp) / $timeRange)
                * $plotWidth
            );

        $isFirst =
            $index === 0;

        $isLast =
            $index === count($timeLabels) - 1;

        //Add a light vertical guide for regular clock-time markers.
        if (!$isFirst && !$isLast) {
            echo '<line '
                . 'x1="' . round($x, 2) . '" '
                . 'y1="' . $marginTop . '" '
                . 'x2="' . round($x, 2) . '" '
                . 'y2="' . ($marginTop + $plotHeight) . '" '
                . 'stroke="#eeeeee" '
                . 'stroke-width="1" />';
        }

        $anchor =
            $isFirst
                ? 'start'
                : ($isLast ? 'end' : 'middle');

        echo '<text '
            . 'x="' . round($x, 2) . '" '
            . 'y="' . ($graphHeight - 20) . '" '
            . 'text-anchor="' . $anchor . '" '
            . 'font-size="14">'
            . htmlspecialchars(
                resultspack_weather_format_timestamp(
                    $timestamp,
                    $session['timezone'],
                    'H:i'
                )
            )
            . '</text>';
    }

    /*
     * Draw the lull-to-gust envelope first so the smoothed average remains
     * clearly visible on top.
     */
    if (
        count($bandUpperPoints) >= 2
        && count($bandLowerPoints) >= 2
    ) {
        $bandPoints =
            array_merge(
                $bandUpperPoints,
                array_reverse($bandLowerPoints)
            );

        echo '<polygon '
            . 'points="' . implode(' ', $bandPoints) . '" '
            . 'fill="#90caf9" '
            . 'fill-opacity="0.30" '
            . 'stroke="none" />';
    }

    //Subtle boundary lines make the top (gust) and bottom (lull) of the band clear.
    if ($gustBoundaryPoints) {
        echo '<polyline '
            . 'points="' . implode(' ', $gustBoundaryPoints) . '" '
            . 'fill="none" '
            . 'stroke="#c62828" '
            . 'stroke-width="1.5" '
            . 'stroke-opacity="0.75" '
            . 'stroke-linejoin="round" '
            . 'stroke-linecap="round" />';
    }

    if ($lullBoundaryPoints) {
        echo '<polyline '
            . 'points="' . implode(' ', $lullBoundaryPoints) . '" '
            . 'fill="none" '
            . 'stroke="#607d8b" '
            . 'stroke-width="1.5" '
            . 'stroke-opacity="0.75" '
            . 'stroke-linejoin="round" '
            . 'stroke-linecap="round" />';
    }

    if ($smoothedAveragePoints) {
        echo '<polyline '
            . 'points="' . implode(' ', $smoothedAveragePoints) . '" '
            . 'fill="none" '
            . 'stroke="#1565c0" '
            . 'stroke-width="3.5" '
            . 'stroke-linejoin="round" '
            . 'stroke-linecap="round" />';
    }

    //Mark the maximum recorded gust directly on the graph.
    if (
        $maximumGustMs !== null
        && $maximumGustTimestamp !== null
    ) {
        $maximumGustX =
            $marginLeft
            + (
                (
                    $maximumGustTimestamp
                    - $firstTimestamp
                )
                / $timeRange
            )
            * $plotWidth;

        $maximumGustY =
            $marginTop
            + $plotHeight
            - (
                ($maximumGustMs / $yMaximum)
                * $plotHeight
            );

        echo '<circle '
            . 'cx="' . round($maximumGustX, 2) . '" '
            . 'cy="' . round($maximumGustY, 2) . '" '
            . 'r="5" '
            . 'fill="#c62828" />';

        // Place the label to the left when close to the right edge.
        $gustLabelOnLeft =
            $maximumGustX > ($graphWidth - $marginRight - 110);

        $gustLabelX = $maximumGustX
            + ($gustLabelOnLeft ? -9 : 9);

        $gustLabelAnchor = $gustLabelOnLeft ? 'end' : 'start';

        echo '<text '
            . 'x="' . round($gustLabelX, 2) . '" '
            . 'text-anchor="' . $gustLabelAnchor . '" '
            . 'y="' . (round($maximumGustY, 2) - 9) . '" '
            . 'font-size="13" '
            . 'font-weight="bold" '
            . 'fill="#c62828">'
            . htmlspecialchars(
                number_format($maximumGustMs, 1)
                . ' m/s'
            )
            . '</text>';
    }

    //Judge-decision markers.
    $eventMarkerNumber = 0;

    foreach ($events as $event) {
        $eventTimestamp = (int) $event['timestamp'];

        //Only plot events which fall inside the observation window shown.
        if (
            $eventTimestamp < $firstTimestamp
            || $eventTimestamp > $lastTimestamp
        ) {
            continue;
        }

        $eventX =
            $marginLeft
            + (
                (($eventTimestamp - $firstTimestamp) / $timeRange)
                * $plotWidth
            );

        switch ($event['action']) {
            case 'suspend':
                $eventColour = '#c62828';
                break;

            case 'resume':
                $eventColour = '#2e7d32';
                break;

            case 'abandon':
                $eventColour = '#6a1b9a';
                break;

            case 'delay':
                $eventColour = '#ef6c00';
                break;

            default:
                $eventColour = '#555555';
                break;
        }

        $labelY =
            $marginTop
            + 18
            + (($eventMarkerNumber % 3) * 18);

        echo '<line '
            . 'x1="' . round($eventX, 2) . '" '
            . 'y1="' . $marginTop . '" '
            . 'x2="' . round($eventX, 2) . '" '
            . 'y2="' . ($marginTop + $plotHeight) . '" '
            . 'stroke="' . $eventColour . '" '
            . 'stroke-width="2" '
            . 'stroke-dasharray="6,4" />';

        $eventLabel =
            strtoupper($event['action'])
            . ' '
            . resultspack_weather_format_timestamp(
                $eventTimestamp,
                $session['timezone'],
                'H:i:s'
            );

        echo '<text '
            . 'x="' . (round($eventX, 2) + 5) . '" '
            . 'y="' . $labelY . '" '
            . 'font-size="12" '
            . 'font-weight="bold" '
            . 'fill="' . $eventColour . '">'
            . htmlspecialchars($eventLabel)
            . '</text>';

        $eventMarkerNumber++;
    }

    echo '</svg>';

    //Legend.
    echo '<div style="margin-top:8px">';

    echo '<span style="margin-right:20px">'
        . '<span style="display:inline-block;width:24px;height:10px;'
        . 'background:#90caf9;border:1px solid #78909c;'
        . 'vertical-align:middle;margin-right:6px"></span>'
        . 'Wind range (bottom = lull, top = gust)'
        . '</span>';

    echo '<span>'
        . '<span style="display:inline-block;width:24px;'
        . 'border-top:4px solid #1565c0;vertical-align:middle;'
        . 'margin-right:6px"></span>'
        . '5-observation smoothed average'
        . '</span>';

    echo '</div>';

    echo '<div class="resultspack-muted" style="margin-top:6px">'
        . 'The shaded band shows the minute-by-minute range between lull and gust. '
        . 'The blue line smooths the recorded average across five observations to show the underlying trend.'
        . '</div>';

    echo '</div>';
}

echo '</td></tr>';
echo '</table>';
}