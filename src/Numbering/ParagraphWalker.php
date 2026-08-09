<?php

declare(strict_types=1);

namespace Fissible\Transmark\Numbering;

use Fissible\Transmark\Contracts\BlockInterface;
use Fissible\Transmark\Nodes\Block\BlockQuote;
use Fissible\Transmark\Nodes\Block\ListItem;
use Fissible\Transmark\Nodes\Block\ListNode;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Block\Table;
use Fissible\Transmark\Nodes\Block\TableCell;
use Fissible\Transmark\Nodes\Block\TableRow;

/**
 * Recursively walks every Paragraph reachable from a block list, descending
 * into the container types that can hold nested paragraphs (Table/TableRow/
 * TableCell, ListNode/ListItem, BlockQuote). Shared by NumberingEngine
 * (label computation) and NumberingShapeClassifier (simple-vs-legal
 * classification) so a numbered paragraph nested inside e.g. a table cell
 * is treated identically by both - a numId used only inside a container
 * must still be found and classified correctly, not silently skipped.
 */
final class ParagraphWalker
{
    /**
     * @param BlockInterface[] $blocks
     *
     * @return iterable<Paragraph>
     */
    public static function paragraphsIn(array $blocks): iterable
    {
        foreach ($blocks as $block) {
            if ($block instanceof Paragraph) {
                yield $block;

                continue;
            }

            yield from self::paragraphsIn(self::childBlocksOf($block));
        }
    }

    /**
     * @return BlockInterface[]
     */
    private static function childBlocksOf(BlockInterface $block): array
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
