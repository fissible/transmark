<?php

declare(strict_types=1);

/**
 * Spike: parse the hand-crafted true-legal-outline fixture with a real
 * DOMDocument (not a hand simulation), build it into the project's actual
 * src/Numbering/* value objects, then run a prototype resolution loop to
 * confirm the existing data model (Level::isLegal(), Level::lvlText(),
 * Level::restartAfterIlvl()) can represent and resolve concatenated
 * legal-outline numbering end to end.
 *
 * This is a throwaway prototype of the resolution algorithm for Issue #1
 * (NumberingEngine core resolution loop) — not the shipped implementation.
 */

require __DIR__.'/../../vendor/autoload.php';

use Fissible\Transmark\Numbering\AbstractNum;
use Fissible\Transmark\Numbering\Level;
use Fissible\Transmark\Numbering\Num;
use Fissible\Transmark\Numbering\NumberFormat;
use Fissible\Transmark\Numbering\NumberingDefinitions;

// --- 1. Parse numbering.xml with a real DOMDocument ---

$numberingDom = new DOMDocument();
$numberingDom->load(__DIR__.'/../../tests/fixtures/numbering/legal-outline/numbering.xml');
$ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

$abstractNums = [];
foreach ($numberingDom->getElementsByTagNameNS($ns, 'abstractNum') as $abstractNumEl) {
    $abstractNumId = (int) $abstractNumEl->getAttributeNS($ns, 'abstractNumId');
    $levels = [];

    foreach ($abstractNumEl->getElementsByTagNameNS($ns, 'lvl') as $lvlEl) {
        $ilvl = (int) $lvlEl->getAttributeNS($ns, 'ilvl');
        $start = 1;
        $numFmt = NumberFormat::Decimal;
        $lvlText = '';
        $isLgl = false;

        foreach ($lvlEl->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            match ($child->localName) {
                'start' => $start = (int) $child->getAttributeNS($ns, 'val'),
                'numFmt' => $numFmt = NumberFormat::from($child->getAttributeNS($ns, 'val')),
                'lvlText' => $lvlText = $child->getAttributeNS($ns, 'val'),
                'isLgl' => $isLgl = true,
                default => null,
            };
        }

        $levels[$ilvl] = new Level($ilvl, $numFmt, $lvlText, $start, $isLgl);
    }

    $abstractNums[$abstractNumId] = new AbstractNum($abstractNumId, $levels);
}

$nums = [];
foreach ($numberingDom->getElementsByTagNameNS($ns, 'num') as $numEl) {
    $numId = (int) $numEl->getAttributeNS($ns, 'numId');
    $abstractNumIdEl = $numEl->getElementsByTagNameNS($ns, 'abstractNumId')->item(0);
    $abstractNumId = (int) $abstractNumIdEl->getAttributeNS($ns, 'val');
    $nums[$numId] = new Num($numId, $abstractNumId);
}

$definitions = new NumberingDefinitions($abstractNums, $nums);

// --- 2. Parse document.xml paragraphs with a real DOMDocument ---

$documentDom = new DOMDocument();
$documentDom->load(__DIR__.'/../../tests/fixtures/numbering/legal-outline/document.xml');

$paragraphs = [];
foreach ($documentDom->getElementsByTagNameNS($ns, 'p') as $pEl) {
    $numPrList = $pEl->getElementsByTagNameNS($ns, 'numPr');
    if ($numPrList->length === 0) {
        continue;
    }
    $numPr = $numPrList->item(0);
    $ilvl = (int) $numPr->getElementsByTagNameNS($ns, 'ilvl')->item(0)->getAttributeNS($ns, 'val');
    $numId = (int) $numPr->getElementsByTagNameNS($ns, 'numId')->item(0)->getAttributeNS($ns, 'val');
    $text = trim($pEl->getElementsByTagNameNS($ns, 't')->item(0)->textContent);

    $paragraphs[] = ['text' => $text, 'ilvl' => $ilvl, 'numId' => $numId];
}

// --- 3. Prototype resolution loop ---

function formatCounter(int $value, NumberFormat $format): string
{
    return match ($format) {
        NumberFormat::Decimal => (string) $value,
        NumberFormat::LowerLetter => strtolower(numberToAlpha($value)),
        NumberFormat::UpperLetter => numberToAlpha($value),
        NumberFormat::LowerRoman => strtolower(numberToRoman($value)),
        NumberFormat::UpperRoman => numberToRoman($value),
        NumberFormat::Bullet, NumberFormat::None => '',
    };
}

function numberToAlpha(int $value): string
{
    $letters = '';
    while ($value > 0) {
        $value--;
        $letters = chr(65 + ($value % 26)).$letters;
        $value = intdiv($value, 26);
    }
    return $letters;
}

function numberToRoman(int $value): string
{
    $map = [1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C', 90 => 'XC', 50 => 'L', 40 => 'XL', 10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I'];
    $result = '';
    foreach ($map as $num => $symbol) {
        while ($value >= $num) {
            $result .= $symbol;
            $value -= $num;
        }
    }
    return $result;
}

// counters[ilvl] tracks the current value at that level for the active numId
$counters = [];

foreach ($paragraphs as $p) {
    $numId = $p['numId'];
    $ilvl = $p['ilvl'];
    $level = $definitions->levelFor($numId, $ilvl);

    if (!isset($counters[$numId])) {
        $counters[$numId] = [];
    }

    // Increment this level's counter (default OOXML lvlRestart behavior:
    // descending into/through a level restarts it if not already active).
    $counters[$numId][$ilvl] = ($counters[$numId][$ilvl] ?? ($level->start() - 1)) + 1;

    // Any deeper level counters restart because an ancestor just advanced.
    foreach (array_keys($counters[$numId]) as $deeperIlvl) {
        if ($deeperIlvl > $ilvl) {
            unset($counters[$numId][$deeperIlvl]);
        }
    }

    // Assemble the label by substituting %N placeholders in lvlText.
    $label = preg_replace_callback('/%(\d)/', function (array $m) use ($definitions, $numId, $ilvl, $level, $counters) {
        $refIlvl = ((int) $m[1]) - 1;
        $refLevel = $definitions->levelFor($numId, $refIlvl);
        $refCounter = $counters[$numId][$refIlvl];
        // isLgl on the CURRENT level forces every referenced placeholder to
        // decimal, regardless of the referenced level's own numFmt.
        $format = $level->isLegal() ? NumberFormat::Decimal : $refLevel->format();
        return formatCounter($refCounter, $format);
    }, $level->lvlText());

    printf("ilvl=%d numId=%-4d %-24s => %s\n", $ilvl, $numId, $p['text'], $label);
}
