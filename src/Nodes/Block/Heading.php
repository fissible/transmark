<?php

declare(strict_types=1);

namespace Fissible\Transmark\Nodes\Block;

use Fissible\Transmark\Attributes;
use Fissible\Transmark\Contracts\InlineInterface;
use Fissible\Transmark\Nodes\AbstractBlock;

/**
 * A true semantic section title (H1-H6) only. Legal outline
 * numbering belongs on Paragraph, not here.
 */
final class Heading extends AbstractBlock
{
    /**
     * @param InlineInterface[] $inlines
     */
    public function __construct(
        private readonly int $level,
        private readonly array $inlines = [],
        Attributes $attributes = new Attributes(),
    ) {
        if ($level < 1 || $level > 6) {
            throw new \InvalidArgumentException("Heading level must be between 1 and 6, got {$level}.");
        }

        parent::__construct($attributes);
    }

    public function level(): int
    {
        return $this->level;
    }

    /**
     * @return InlineInterface[]
     */
    public function inlines(): array
    {
        return $this->inlines;
    }
}
