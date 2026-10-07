<?php

// Split observations into continuous sections.
// Missing values or gaps over 90 seconds break a section.
function resultspack_weather_graph_segments(
    array $observations,
    array $requiredFields,
    $maximumGapSeconds = 90
) {
    // Work on a sorted copy.
    usort($observations, function ($a, $b) {
        return (int) ($a['timestamp'] ?? 0)
            <=> (int) ($b['timestamp'] ?? 0);
    });

    $segments = array();
    $current = array();
    $previousTimestamp = null;

    foreach ($observations as $observation) {
        $timestamp = (int) ($observation['timestamp'] ?? 0);
        $valid = $timestamp > 0;

        foreach ($requiredFields as $field) {
            if (
                !isset($observation[$field])
                || !is_numeric($observation[$field])
            ) {
                $valid = false;
                break;
            }
        }

        $timeBreak = $previousTimestamp !== null
            && (
                $timestamp <= $previousTimestamp
                || $timestamp - $previousTimestamp > $maximumGapSeconds
            );

        if (!$valid || $timeBreak) {
            if ($current) {
                $segments[] = $current;
                $current = array();
            }

            $previousTimestamp = null;
        }

        if (!$valid) {
            continue;
        }

        $current[] = $observation;
        $previousTimestamp = $timestamp;
    }

    if ($current) {
        $segments[] = $current;
    }

    return $segments;
}

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

    // Convert timestamp and speed into an SVG coordinate.
    $graphPoint = function ($timestamp, $value) use (
        $marginLeft,
        $marginTop,
        $plotWidth,
        $plotHeight,
        $firstTimestamp,
        $timeRange,
        $yMaximum
    ) {
        $x = $marginLeft
            + (($timestamp - $firstTimestamp) / $timeRange)
            * $plotWidth;

        $y = $marginTop + $plotHeight
            - ($value / $yMaximum) * $plotHeight;

        return round($x, 2) . ',' . round($y, 2);
    };

    // Build each line independently, preserving gaps in its own readings.
    $lineSections = array();

    foreach (array('gust_ms', 'lull_ms', 'average_ms') as $field) {
        $lineSections[$field] = array();

        $segments = resultspack_weather_graph_segments(
            $windSeries,
            array($field)
        );

        foreach ($segments as $segment) {
            $points = array();
            $segmentCount = count($segment);

            foreach ($segment as $index => $point) {
                $value = (float) $point[$field];

                if ($field === 'average_ms') {
                    // Smooth only within this continuous section.
                    $windowStart = max(0, $index - 2);
                    $windowEnd = min($segmentCount - 1, $index + 2);
                    $total = 0.0;
                    $count = 0;

                    for ($i = $windowStart; $i <= $windowEnd; $i++) {
                        $total += (float) $segment[$i][$field];
                        $count++;
                    }

                    $value = $total / $count;
                }

                $points[] = $graphPoint(
                    $point['timestamp'],
                    $value
                );
            }

            $lineSections[$field][] = $points;
        }
    }

    // A shaded section requires both lull and gust readings.
    $bandSections = array();

    $bandSegments = resultspack_weather_graph_segments(
        $windSeries,
        array('lull_ms', 'gust_ms')
    );

    foreach ($bandSegments as $segment) {
        if (count($segment) < 2) {
            continue;
        }

        $upper = array();
        $lower = array();

        foreach ($segment as $point) {
            $upper[] = $graphPoint(
                $point['timestamp'],
                $point['gust_ms']
            );

            $lower[] = $graphPoint(
                $point['timestamp'],
                $point['lull_ms']
            );
        }

        $bandSections[] = array_merge(
            $upper,
            array_reverse($lower)
        );
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

    // Draw each continuous shaded section separately.
    foreach ($bandSections as $points) {
        echo '<polygon '
            . 'points="' . implode(' ', $points) . '" '
            . 'fill="#90caf9" '
            . 'fill-opacity="0.30" '
            . 'stroke="none" />';
    }

    $lineStyles = array(
        'gust_ms' => array(
            'colour' => '#c62828',
            'width' => 1.5,
            'opacity' => 0.75,
        ),
        'lull_ms' => array(
            'colour' => '#607d8b',
            'width' => 1.5,
            'opacity' => 0.75,
        ),
        'average_ms' => array(
            'colour' => '#1565c0',
            'width' => 3.5,
            'opacity' => 1,
        ),
    );

    foreach ($lineStyles as $field => $style) {
        foreach ($lineSections[$field] as $points) {
            if (count($points) === 1) {
                // Keep isolated readings visible as dots.
                list($pointX, $pointY) = explode(',', $points[0]);

                echo '<circle '
                    . 'cx="' . $pointX . '" '
                    . 'cy="' . $pointY . '" '
                    . 'r="2.5" '
                    . 'fill="' . $style['colour'] . '" '
                    . 'fill-opacity="' . $style['opacity'] . '" />';

                continue;
            }

            echo '<polyline '
                . 'points="' . implode(' ', $points) . '" '
                . 'fill="none" '
                . 'stroke="' . $style['colour'] . '" '
                . 'stroke-width="' . $style['width'] . '" '
                . 'stroke-opacity="' . $style['opacity'] . '" '
                . 'stroke-linejoin="round" '
                . 'stroke-linecap="round" />';
        }
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

//Wind rose graph
function resultspack_weather_viewer_render_wind_rose($observations, $session)
{
    echo '<br>';
    echo '<table class="Tabella freeWidth">';
    echo '<tr><th class="Main">Wind direction rose</th></tr>';
    echo '<tr><td>';

    $labels = array(
        'N', 'NNE', 'NE', 'ENE',
        'E', 'ESE', 'SE', 'SSE',
        'S', 'SSW', 'SW', 'WSW',
        'W', 'WNW', 'NW', 'NNW'
    );

    $sectors = array();

    for ($i = 0; $i < 16; $i++) {
        $sectors[$i] = array(
            'count' => 0,
            'speed_sum_kmh' => 0.0,
            'speed_count' => 0,
        );
    }

    $directionObservationCount = 0;
    $directionVectorX = 0.0;
    $directionVectorY = 0.0;

    foreach ($observations as $observation) {
        if (
            !isset($observation['wind_dir'])
            || $observation['wind_dir'] === null
            || !is_numeric($observation['wind_dir'])
        ) {
            continue;
        }

        $direction =
            resultspack_weather_effective_wind_direction(
                $observation['wind_dir'],
                $session
            );

        if ($direction === null) {
            continue;
        }

        //Build a circular mean of the recorded FROM directions
        $directionRadians = deg2rad($direction);

        $directionVectorX += sin($directionRadians);
        $directionVectorY += cos($directionRadians);

        $sectorIndex =
            ((int) floor(($direction + 11.25) / 22.5)) % 16;

        $sectors[$sectorIndex]['count']++;
        $directionObservationCount++;

        if (
            isset($observation['wind_avg'])
            && $observation['wind_avg'] !== null
            && is_numeric($observation['wind_avg'])
        ) {
            // Raw Tempest values are stored in mph.
            // Convert only for display.
            $sectors[$sectorIndex]['speed_sum_kmh'] +=
                ((float) $observation['wind_avg']) * 1.609344;

            $sectors[$sectorIndex]['speed_count']++;
        }

        $prevailingWindFrom = null;

        if (
            $directionObservationCount > 0
            && (
                abs($directionVectorX) > 0.000001
                || abs($directionVectorY) > 0.000001
            )
        ) {
            $prevailingWindFrom =
                rad2deg(
                    atan2(
                        $directionVectorX,
                        $directionVectorY
                    )
                );

            if ($prevailingWindFrom < 0) {
                $prevailingWindFrom += 360;
            }
        }
    }

    // Use the same corrected direction and variability threshold as the environmental summary.
    $windContext = resultspack_weather_wind_context(
        $observations,
        $session
    );

    $prevailingWindFrom = $windContext['prevailing_direction'];
    
    if ($directionObservationCount === 0) {
        echo 'No recorded wind-direction observations are available for this session.';
        echo '</td></tr></table>';
        return;
    }

    $maximumSectorPercent = 0.0;

    foreach ($sectors as $sector) {
        $percent =
            ($sector['count'] / $directionObservationCount) * 100;

        if ($percent > $maximumSectorPercent) {
            $maximumSectorPercent = $percent;
        }
    }

    // Round the outer scale to 5 percentage points
    $scaleMaximum =
        max(5, ceil($maximumSectorPercent / 5) * 5);

    $graphWidth = 680;
    $graphHeight = 680;

    $centreX = 340;
    $centreY = 340;

    $maximumRadius = 220;
    $labelRadius = 260;

    echo '<div style="max-width:760px">';

    echo '<svg '
        . 'viewBox="0 0 ' . $graphWidth . ' ' . $graphHeight . '" '
        . 'style="width:100%;height:auto;background:white;border:1px solid #ccc" '
        . 'role="img" '
        . 'aria-label="Wind direction frequency rose with shooting direction">';

    // Arrow for the shooting-direction marker
    echo '<defs>';

    echo '<marker '
        . 'id="resultspack-target-arrow" '
        . 'markerWidth="10" '
        . 'markerHeight="10" '
        . 'refX="8" '
        . 'refY="3" '
        . 'orient="auto" '
        . 'markerUnits="strokeWidth">';

    echo '<path d="M0,0 L0,6 L9,3 z" fill="#222222" />';

    echo '</marker>';

    echo '<marker '
        . 'id="resultspack-wind-flow-arrow" '
        . 'markerWidth="10" '
        . 'markerHeight="10" '
        . 'refX="8" '
        . 'refY="3" '
        . 'orient="auto" '
        . 'markerUnits="strokeWidth">';

    echo '<path d="M0,0 L0,6 L9,3 z" fill="#0d47a1" />';

    echo '</marker>';

    echo '</defs>';

    /* Concentric frequency rings
    for ($step = 1; $step <= 4; $step++) {
        $radius =
            $maximumRadius * ($step / 4);

        $ringPercent =
            $scaleMaximum * ($step / 4);

        echo '<circle '
            . 'cx="' . $centreX . '" '
            . 'cy="' . $centreY . '" '
            . 'r="' . round($radius, 2) . '" '
            . 'fill="none" '
            . 'stroke="#dddddd" '
            . 'stroke-width="1" />';

        echo '<text '
            . 'x="' . ($centreX + 6) . '" '
            . 'y="' . round($centreY - $radius + 15, 2) . '" '
            . 'font-size="12" '
            . 'fill="#666666">'
            . htmlspecialchars(number_format($ringPercent, 1))
            . '%</text>';
    }
    */

    // N / E / S / W guide lines
    foreach (array(0, 90, 180, 270) as $axisDirection) {
        $axisRadians =
            deg2rad($axisDirection - 90);

        $axisX =
            $centreX
            + ($maximumRadius * cos($axisRadians));

        $axisY =
            $centreY
            + ($maximumRadius * sin($axisRadians));

        echo '<line '
            . 'x1="' . $centreX . '" '
            . 'y1="' . $centreY . '" '
            . 'x2="' . round($axisX, 2) . '" '
            . 'y2="' . round($axisY, 2) . '" '
            . 'stroke="#eeeeee" '
            . 'stroke-width="1" />';
    }

    //Show the prevailing direction of travel. Tempest gives the direction the wind came FROM. The arrow therefore begins on that side of the compass, passes through the centre, and points towards where the air travelled TO.
    if ($prevailingWindFrom !== null) {
        $prevailingWindTo =
            fmod($prevailingWindFrom + 180, 360);

        $flowRadius = $maximumRadius * 0.88;

        $fromRadians =
            deg2rad($prevailingWindFrom - 90);

        $toRadians =
            deg2rad($prevailingWindTo - 90);

        $fromX =
            $centreX + ($flowRadius * cos($fromRadians));

        $fromY =
            $centreY + ($flowRadius * sin($fromRadians));

        $toX =
            $centreX + ($flowRadius * cos($toRadians));

        $toY =
            $centreY + ($flowRadius * sin($toRadians));

        echo '<line '
            . 'x1="' . round($fromX, 2) . '" '
            . 'y1="' . round($fromY, 2) . '" '
            . 'x2="' . round($toX, 2) . '" '
            . 'y2="' . round($toY, 2) . '" '
            . 'stroke="#0d47a1" '
            . 'stroke-width="4" '
            . 'opacity="0.9" '
            . 'marker-end="url(#resultspack-wind-flow-arrow)" />';

        $flowlabelRadius = $flowRadius + 24;

        $fromLabelX =
            $centreX + ($flowlabelRadius * cos($fromRadians));

        $fromLabelY =
            $centreY + ($flowlabelRadius * sin($fromRadians));

        $toLabelX =
            $centreX + ($flowlabelRadius * cos($toRadians));

        $toLabelY =
            $centreY + ($flowlabelRadius * sin($toRadians));

        /* echo '<text '
            . 'x="' . round($fromLabelX, 2) . '" '
            . 'y="' . round($fromLabelY + 5, 2) . '" '
            . 'text-anchor="middle" '
            . 'font-size="12" '
            . 'font-weight="bold" '
            . 'fill="#0d47a1">FROM</text>';

        echo '<text '
            . 'x="' . round($toLabelX, 2) . '" '
            . 'y="' . round($toLabelY + 5, 2) . '" '
            . 'text-anchor="middle" '
            . 'font-size="12" '
            . 'font-weight="bold" '
            . 'fill="#0d47a1">TO</text>'; */
    }

    // Compass label
    foreach ($labels as $index => $label) {
        $direction =
            $index * 22.5;

        $radians =
            deg2rad($direction - 90);

        $labelX =
            $centreX
            + ($labelRadius * cos($radians));

        $labelY =
            $centreY
            + ($labelRadius * sin($radians))
            + 5;

        echo '<text '
            . 'x="' . round($labelX, 2) . '" '
            . 'y="' . round($labelY, 2) . '" '
            . 'text-anchor="middle" '
            . 'font-size="13"'
            . (
                in_array(
                    $label,
                    array('N', 'E', 'S', 'W'),
                    true
                )
                    ? ' font-weight="bold"'
                    : ''
            )
            . '>'
            . htmlspecialchars($label)
            . '</text>';
    }

    // Shooting direction towards the targets
    $bearing =
        resultspack_weather_effective_shooting_bearing(
            $session
        );

    if ($bearing !== null) {
        $bearingRadians =
            deg2rad($bearing - 90);

        $arrowRadius =
            $maximumRadius - 14;
        
        // Thin line showing the shooting line
        $shootingLineDirectionA = fmod($bearing + 90, 360);
        $shootingLineDirectionB = fmod($bearing + 270, 360);

        $shootingLineRadiansA = deg2rad($shootingLineDirectionA - 90);
        $shootingLineRadiansB = deg2rad($shootingLineDirectionB - 90);

        $shootingLineRadius = $maximumRadius + 10;

        $shootingLineX1 =
            $centreX + ($shootingLineRadius * cos($shootingLineRadiansA));
        $shootingLineY1 =
            $centreY + ($shootingLineRadius * sin($shootingLineRadiansA));

        $shootingLineX2 =
            $centreX + ($shootingLineRadius * cos($shootingLineRadiansB));
        $shootingLineY2 =
            $centreY + ($shootingLineRadius * sin($shootingLineRadiansB));

        echo '<line '
            . 'x1="' . round($shootingLineX1, 2) . '" '
            . 'y1="' . round($shootingLineY1, 2) . '" '
            . 'x2="' . round($shootingLineX2, 2) . '" '
            . 'y2="' . round($shootingLineY2, 2) . '" '
            . 'stroke="#333333" '
            . 'stroke-width="1.0" '
            . 'opacity="0.5" />';

        $arrowX =
            $centreX
            + ($arrowRadius * cos($bearingRadians));

        $arrowY =
            $centreY
            + ($arrowRadius * sin($bearingRadians));

        echo '<line '
            . 'x1="' . $centreX . '" '
            . 'y1="' . $centreY . '" '
            . 'x2="' . round($arrowX, 2) . '" '
            . 'y2="' . round($arrowY, 2) . '" '
            . 'stroke="#222222" '
            . 'stroke-width="3" '
            . 'stroke-dasharray="7,5" '
            . 'marker-end="url(#resultspack-target-arrow)" />';
    }

    echo '<circle '
        . 'cx="' . $centreX . '" '
        . 'cy="' . $centreY . '" '
        . 'r="5" '
        . 'fill="#222222" />';

    echo '</svg>';

    echo '<div style="margin-top:8px">';

    echo '<span style="margin-right:20px">'
    . '<span style="display:inline-block;width:24px;'
    . 'border-top:3px solid #0d47a1;vertical-align:middle;'
    . 'margin-right:6px"></span>'
    . 'Prevailing wind flow (FROM → TO)'
    . '</span>';

    if ($bearing !== null) {
        echo '<span>'
            . '<span style="display:inline-block;width:24px;'
            . 'border-top:3px dashed #222222;'
            . 'vertical-align:middle;margin-right:6px"></span>'
            . 'Shooting direction towards targets ('
            . htmlspecialchars(
                number_format(
                    $bearing,
                    0
                )
            )
            . '°)'
            . '</span>';
    }

    echo '</div>';

    echo '<div style="margin-top:10px">';
        if ($prevailingWindFrom !== null) {
        echo 'The blue arrow points from where the wind comes from, towards where it travels. ';
    } else {
        echo 'Wind directions are too variable, so no prevailing direction can be shown. ';
    }
    echo 'The dashed black arrow shows the shooting direction towards the targets, ';
    echo 'and the thin black line shows the shooting line.';
    echo '</div>';

    echo '</div>';

    echo '</td></tr>';
    echo '</table>';
}

// Pretty colours.
function resultspack_weather_viewer_event_colour($action)
{
    switch ((string) $action) {
        case 'suspend':
            return '#c62828';
        case 'resume':
            return '#2e7d32';
        case 'abandon':
            return '#6a1b9a';
        case 'delay':
            return '#ef6c00';
        default:
            return '#555555';
    }
}

// This draws the other graphs.
function resultspack_weather_viewer_render_single_graph(
    $title,
    $ariaLabel,
    $observations,
    $valueKey,
    $events,
    $session,
    $yAxisLabel,
    $legendLabel,
    $colour,
    $decimals,
    $zeroBaseline,
    $minimumSpan,
    $roundTo
) {
    echo '<br>';
    echo '<table class="Tabella freeWidth">';
    echo '<tr><th class="Main">' . htmlspecialchars($title) . '</th></tr>';
    echo '<tr><td>';

    usort($observations, function ($a, $b) {
        return (int) ($a['timestamp'] ?? 0)
            <=> (int) ($b['timestamp'] ?? 0);
    });

    $series = array();

    foreach ($observations as $observation) {
        if (
            $observation['timestamp'] > 0
            && isset($observation[$valueKey])
            && is_numeric($observation[$valueKey])
        ) {
            $series[] = $observation;
        }
    }

    if (count($series) < 2) {
        echo 'Not enough observations to draw this graph.';
        echo '</td></tr></table>';
        return;
    }

    $graphWidth = 1000;
    $graphHeight = 360;
    $marginLeft = 75;
    $marginRight = 25;
    $marginTop = 30;
    $marginBottom = 55;
    $plotWidth = $graphWidth - $marginLeft - $marginRight;
    $plotHeight = $graphHeight - $marginTop - $marginBottom;

    $firstTimestamp = (int) $series[0]['timestamp'];
    $lastTimestamp = (int) $series[count($series) - 1]['timestamp'];
    $timeRange = max(1, $lastTimestamp - $firstTimestamp);

    $minimum = null;
    $maximum = null;

    foreach ($series as $observation) {
        $value = (float) $observation[$valueKey];
        if ($minimum === null || $value < $minimum) {
            $minimum = $value;
        }
        if ($maximum === null || $value > $maximum) {
            $maximum = $value;
        }
    }

    $roundTo = max(0.0001, (float) $roundTo);

    if ($zeroBaseline) {
        $yMinimum = 0;
        $upper = max($roundTo, $maximum * 1.10);
        $yMaximum = ceil($upper / $roundTo) * $roundTo;
    } else {
        $span = max(0.0001, $maximum - $minimum);
        $padding = max($roundTo, $span * 0.15);
        $yMinimum = floor(($minimum - $padding) / $roundTo) * $roundTo;
        $yMaximum = ceil(($maximum + $padding) / $roundTo) * $roundTo;

        if (($yMaximum - $yMinimum) < $minimumSpan) {
            $midpoint = ($maximum + $minimum) / 2;
            $half = $minimumSpan / 2;
            $yMinimum = floor(($midpoint - $half) / $roundTo) * $roundTo;
            $yMaximum = ceil(($midpoint + $half) / $roundTo) * $roundTo;
        }
    }

    // Choose readable tick sizes: 1, 2 or 5 times a power of ten.
    $targetStep = max($roundTo, ($yMaximum - $yMinimum) / 5);
    $magnitude = pow(10, floor(log10($targetStep)));
    $scaledStep = $targetStep / $magnitude;

    if ($scaledStep <= 1) {
        $tickStep = $magnitude;
    } elseif ($scaledStep <= 2) {
        $tickStep = 2 * $magnitude;
    } elseif ($scaledStep <= 5) {
        $tickStep = 5 * $magnitude;
    } else {
        $tickStep = 10 * $magnitude;
    }

    $yMinimum = floor($yMinimum / $tickStep) * $tickStep;
    $yMaximum = ceil($yMaximum / $tickStep) * $tickStep;
    $tickCount = (int) round(($yMaximum - $yMinimum) / $tickStep);
    
    $yRange = max(0.0001, $yMaximum - $yMinimum);
    $pointSections = array();

    // Pass all observations so missing values also break the line.
    $segments = resultspack_weather_graph_segments(
        $observations,
        array($valueKey)
    );

    foreach ($segments as $segment) {
        $points = array();

        foreach ($segment as $observation) {
            $x = $marginLeft
                + (($observation['timestamp'] - $firstTimestamp) / $timeRange)
                * $plotWidth;

            $y = $marginTop + $plotHeight
                - (((float) $observation[$valueKey] - $yMinimum) / $yRange)
                * $plotHeight;

            $points[] = round($x, 2) . ',' . round($y, 2);
        }

        $pointSections[] = $points;
    }

    echo '<div style="max-width:1100px">';
    echo '<svg viewBox="0 0 ' . $graphWidth . ' ' . $graphHeight . '" '
        . 'style="width:100%;height:auto;background:white;border:0.7px solid #ccc" '
        . 'role="img" aria-label="' . htmlspecialchars($ariaLabel) . '">';

    for ($step = 0; $step <= $tickCount; $step++) {
        $value = $yMinimum + ($step * $tickStep);
        $y = $marginTop + $plotHeight - ((($value - $yMinimum) / $yRange) * $plotHeight);

        echo '<line x1="' . $marginLeft . '" y1="' . round($y, 2) . '" '
            . 'x2="' . ($graphWidth - $marginRight) . '" y2="' . round($y, 2) . '" '
            . 'stroke="#dddddd" stroke-width="0.7" />';

        echo '<text x="' . ($marginLeft - 10) . '" y="' . (round($y, 2) + 5) . '" '
            . 'text-anchor="end" font-size="14">'
            . htmlspecialchars(number_format($value, (int) $decimals))
            . '</text>';
    }

    echo '<text x="18" y="' . ($marginTop + ($plotHeight / 2)) . '" '
        . 'transform="rotate(-90 18 ' . ($marginTop + ($plotHeight / 2)) . ')" '
        . 'text-anchor="middle" font-size="14">'
        . htmlspecialchars($yAxisLabel)
        . '</text>';

    // Create useful clock-time markers. 
    // This is a duplicate of the same section much further up.
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

    foreach ($pointSections as $points) {
        if (count($points) === 1) {
            list($pointX, $pointY) = explode(',', $points[0]);

            echo '<circle cx="' . $pointX . '" cy="' . $pointY . '" '
                . 'r="2.5" fill="' . htmlspecialchars($colour) . '" />';

            continue;
        }

        echo '<polyline points="' . implode(' ', $points) . '" fill="none" '
            . 'stroke="' . htmlspecialchars($colour) . '" stroke-width="3" '
            . 'stroke-linejoin="round" stroke-linecap="round" />';
    }

    $eventMarkerNumber = 0;

    foreach ($events as $event) {
        $eventTimestamp = (int) $event['timestamp'];
        if ($eventTimestamp < $firstTimestamp || $eventTimestamp > $lastTimestamp) {
            continue;
        }

        $eventX = $marginLeft
            + ((($eventTimestamp - $firstTimestamp) / $timeRange) * $plotWidth);
        $eventColour = resultspack_weather_viewer_event_colour($event['action']);
        $labelY = $marginTop + 18 + (($eventMarkerNumber % 3) * 18);

        echo '<line x1="' . round($eventX, 2) . '" y1="' . $marginTop . '" '
            . 'x2="' . round($eventX, 2) . '" y2="' . ($marginTop + $plotHeight) . '" '
            . 'stroke="' . $eventColour . '" stroke-width="2" stroke-dasharray="6,4" />';

        $eventLabel = strtoupper($event['action']) . ' '
            . resultspack_weather_format_timestamp(
                $eventTimestamp,
                $session['timezone'],
                'H:i:s'
            );

        $labelOnLeft =
            $eventX > ($graphWidth - $marginRight - 180);

        $eventLabelX = $eventX + ($labelOnLeft ? -5 : 5);
        $eventAnchor = $labelOnLeft ? 'end' : 'start';

        echo '<text x="' . round($eventLabelX, 2) . '" y="' . $labelY . '" '
            . 'text-anchor="' . $eventAnchor . '" '
            . 'font-size="12" font-weight="bold" fill="' . $eventColour . '">'
            . htmlspecialchars($eventLabel)
            . '</text>';

        $eventMarkerNumber++;
    }

    echo '</svg>';
    echo '<div style="margin-top:8px">'
        . '<span><span style="display:inline-block;width:24px;border-top:3px solid '
        . htmlspecialchars($colour)
        . ';vertical-align:middle;margin-right:6px"></span>'
        . htmlspecialchars($legendLabel)
        . '</span></div>';
    echo '</div>';
    echo '</td></tr>';
    echo '</table>';
}