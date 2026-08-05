<?php

declare(strict_types=1);

namespace Fissible\Transmark\Nodes\Block;

use Fissible\Transmark\Attributes;
use Fissible\Transmark\Contracts\BlockInterface;
use Fissible\Transmark\Nodes\AbstractBlock;

final class TableCell extends AbstractBlock
{
    /**
     * @param BlockInterface[] $content
     */
    public function __construct(
        private readonly array $content = [],
        private readonly int $colspan = 1,
        private readonly int $rowspan = 1,
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($attributes);
    }

    /**
     * @return BlockInterface[]
     */
    public function content(): array
    {
        return $this->content;
    }

    public function colspan(): int
    {
        return $this->colspan;
    }

    public function rowspan(): int
    {
        return $this->rowspan;
    }
}
