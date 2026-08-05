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

            $counters[$numId][$ilvl] = ($counters[$numId][$ilvl] ?? $level->start() - 1) + 1;

            foreach (array_keys($counters[$numId]) as $deeperIlvl) {
                if ($deeperIlvl > $ilvl) {
                    unset($counters[$numId][$deeperIlvl]);
                }
            }

            $labelParts = [];
            for ($ancestorIlvl = 0; $ancestorIlvl <= $ilvl; ++$ancestorIlvl) {
                if (isset($counters[$numId][$ancestorIlvl])) {
                    $labelParts[] = (string) $counters[$numId][$ancestorIlvl];
                }
            }

            $labels[spl_object_id($paragraph)] = implode('.', $labelParts);
        }

        return new NumberingLabelMap($labels);
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
