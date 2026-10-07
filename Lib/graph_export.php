<?php

// Wrap data plots with headings and explanatory text to return a complete SVG.
function resultspack_weather_build_graph_export(
    DOMDocument $plotDocument,
    array $headingLines,
    array $footerLines,
    $plotHeight = 360
) {
    $plotTop = 25 + count($headingLines) * 25;
    $footerTop = $plotTop + $plotHeight + 25;
    $exportHeight = $footerTop + count($footerLines) * 22 + 20;

    $svgNamespace = 'http://www.w3.org/2000/svg';
    $document = new DOMDocument('1.0', 'UTF-8');

    $root = $document->createElementNS($svgNamespace, 'svg');
    $root->setAttribute('width', '1000');
    $root->setAttribute('height', (string) $exportHeight);
    $root->setAttribute('viewBox', '0 0 1000 ' . $exportHeight);
    $document->appendChild($root);

    $title = $document->createElementNS($svgNamespace, 'title');
    $title->appendChild(
        $document->createTextNode(
            implode(' — ', $headingLines)
        )
    );
    $root->appendChild($title);

    $background = $document->createElementNS($svgNamespace, 'rect');
    $background->setAttribute('width', '100%');
    $background->setAttribute('height', '100%');
    $background->setAttribute('fill', 'white');
    $root->appendChild($background);

    $addText = function (
        $text,
        $x,
        $y,
        $size = 15,
        $bold = false
    ) use ($document, $root, $svgNamespace) {
        $node = $document->createElementNS($svgNamespace, 'text');
        $node->setAttribute('x', (string) $x);
        $node->setAttribute('y', (string) $y);
        $node->setAttribute('font-family', 'Arial, sans-serif');
        $node->setAttribute('font-size', (string) $size);
        $node->setAttribute('fill', '#222222');

        if ($bold) {
            $node->setAttribute('font-weight', 'bold');
        }

        $node->appendChild(
            $document->createTextNode((string) $text)
        );

        $root->appendChild($node);
    };

    foreach ($headingLines as $index => $line) {
        $addText(
            $line,
            25,
            30 + $index * 25,
            $index === 0 ? 22 : 15,
            $index === 0
        );
    }

    $plot = $document->importNode(
        $plotDocument->documentElement,
        true
    );

    $plot->removeAttribute('style');
    $plot->setAttribute('x', '0');
    $plot->setAttribute('y', (string) $plotTop);
    $plot->setAttribute('width', '1000');
    $plot->setAttribute('height', (string) $plotHeight);
    $plot->setAttribute('font-family', 'Arial, sans-serif');
    $root->appendChild($plot);

    foreach ($footerLines as $index => $line) {
        $addText(
            $line,
            25,
            $footerTop + $index * 22
        );
    }

    $svg = $document->saveXML();

    if ($svg === false) {
        throw new RuntimeException(
            'The SVG image could not be generated.'
        );
    }

    return $svg;
}