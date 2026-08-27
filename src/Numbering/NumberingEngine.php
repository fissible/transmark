<?php

declare(strict_types=1);

namespace Fissible\Transmark\Numbering;

use Fissible\Transmark\Contracts\NumberingEngineInterface;
use Fissible\Transmark\Document;

final class NumberingEngine implements NumberingEngineInterface
{
    public function resolve(Document $document): NumberingLabelMap
    {
        /** @var array<int, array<int, int>> $counters */
        $counters = [];
        $labels = [];
        /** @var array<int, array{ilvl: int, value: int}> $countersByParagraph */
        $countersByParagraph = [];

        foreach (ParagraphWalker::paragraphsIn($document->content()) as $paragraph) {
            $numbering = $paragraph->numbering();

            if ($numbering === null) {
                continue;
            }

            $numId = $numbering->numId();
            $ilvl = $numbering->ilvl();
            $level = $document->numbering()->levelFor($numId, $ilvl);

            if ($level === null) {
                continue;
            }

            $num = $document->numbering()->num($numId);
            $levelOverrides = $num?->levelOverrides() ?? [];
            $start = $levelOverrides[$ilvl] ?? $level->start();
            $counters[$numId][$ilvl] = ($counters[$numId][$ilvl] ?? $start - 1) + 1;

            foreach (array_keys($counters[$numId]) as $deeperIlvl) {
                $deeperLevel = $document->numbering()->levelFor($numId, $deeperIlvl);

                if ($deeperLevel?->restartsAfter($ilvl) === true) {
                    unset($counters[$numId][$deeperIlvl]);
                }
            }

            $paragraphObjectId = spl_object_id($paragraph);
            $labels[$paragraphObjectId] = $this->renderLabel(
                $document->numbering(),
                $numId,
                $level,
                $counters[$numId],
            );
            $countersByParagraph[$paragraphObjectId] = [
                'ilvl' => $ilvl,
                'value' => $counters[$numId][$ilvl],
            ];
        }

        return new NumberingLabelMap($labels, $countersByParagraph);
    }

    /**
     * @param array<int, int> $counters
     */
    private function renderLabel(
        NumberingDefinitions $definitions,
        int $numId,
        Level $currentLevel,
        array $counters,
    ): string {
        return preg_replace_callback(
            '/%(\d)/',
            static function (array $matches) use ($definitions, $numId, $currentLevel, $counters): string {
                $referencedIlvl = ((int) $matches[1]) - 1;
                $referencedLevel = $definitions->levelFor($numId, $referencedIlvl);

                if ($referencedLevel === null) {
                    return '';
                }

                $format = $currentLevel->isLegal()
                    ? NumberFormat::Decimal
                    : $referencedLevel->format();

                // A counter that has not started yet (an ancestor or deeper
                // level never incremented before this paragraph) renders as
                // its effective start value, the way Word fills in the
                // missing label components of a deep-level item.
                if (!array_key_exists($referencedIlvl, $counters)) {
                    $startOverride = $definitions->num($numId)?->levelOverrides()[$referencedIlvl] ?? null;
                    $value = $startOverride ?? $referencedLevel->start();

                    return $format->render($value);
                }

                return $format->render($counters[$referencedIlvl]);
            },
            $currentLevel->lvlText(),
        ) ?? $currentLevel->lvlText();
    }
}
