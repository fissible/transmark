<?php

declare(strict_types=1);

namespace Fissible\Transmark\Nodes\Block;

use Fissible\Transmark\Attributes;
use Fissible\Transmark\Nodes\AbstractBlock;

/**
 * For simple structural lists (e.g. Markdown bullet/ordered lists)
 * that don't need Word's numbering.xml fidelity. Legal outlines
 * still use Paragraph + NumberingRef, not this node.
 */
final class ListNode extends AbstractBlock
{
    public const TYPE_ORDERED = 'ordered';
    public const TYPE_UNORDERED = 'unordered';

    /**
     * @param ListItem[] $items
     */
    public function __construct(
        private readonly string $type,
        private readonly array $items = [],
        private readonly int $start = 1,
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($attributes);
    }

    public function type(): string
    {
        return $this->type;
    }

    /**
     * @return ListItem[]
     */
    public function items(): array
    {
        return $this->items;
    }

    public function start(): int
    {
        return $this->start;
    }
}
