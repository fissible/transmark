<?php

declare(strict_types=1);

namespace Fissible\Transmark\Numbering;

use Fissible\Transmark\Contracts\BlockInterface;
use Fissible\Transmark\Contracts\NumberingEngineInterface;
use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\BlockQuote;
use Fissible\Transmark\Nodes\Block\ListItem;
use Fissible\Transmark\Nodes\Block\ListNode;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Block\Table;
use Fissible\Transmark\Nodes\Block\TableCell;
use Fissible\Transmark\Nodes\Block\TableRow;

final class NumberingEngine implements NumberingEngineInterface
{
    public function resolve(Document $document): NumberingLabelMap
    {
        /** @var array<int, array<int, int>> $counters */
        $counters = [];
        $labels = [];

        foreach ($this->paragraphsIn($document->content()) as $paragraph) {
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

            $labels[spl_object_id($paragraph)] = $this->renderLabel(
                $document->numbering(),
                $numId,
                $level,
                $counters[$numId],
            );
        }

        return new NumberingLabelMap($labels);
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

                if ($referencedLevel === null || !array_key_exists($referencedIlvl, $counters)) {
                    return '';
                }

                $format = $currentLevel->isLegal()
                    ? NumberFormat::Decimal
                    : $referencedLevel->format();

                return $format->render($counters[$referencedIlvl]);
            },
            $currentLevel->lvlText(),
        ) ?? $currentLevel->lvlText();
    }

    /**
     * @param BlockInterface[] $blocks
     *
     * @return iterable<Paragraph>
     */
    private function paragraphsIn(array $blocks): iterable
    {
        foreach ($blocks as $block) {
            if ($block instanceof Paragraph) {
                yield $block;

                continue;
            }

            yield from $this->paragraphsIn($this->childBlocksOf($block));
        }
    }

    /**
     * @return BlockInterface[]
     */
    private function childBlocksOf(BlockInterface $block): array
    {
        if ($block instanceof Table) {
            return [
                ...($block->header() === null ? [] : [$block->header()]),
                ...$block->rows(),
            ];
        }

        return match (true) {
            $block instanceof ListNode => $block->items(),
            $block instanceof ListItem,
            $block instanceof TableCell,
            $block instanceof BlockQuote => $block->content(),
            $block instanceof TableRow => $block->cells(),
            default => [],
        };
    }
}
