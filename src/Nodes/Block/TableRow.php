<?php

declare(strict_types=1);

namespace Fissible\Transmark\Nodes\Block;

use Fissible\Transmark\Attributes;
use Fissible\Transmark\Nodes\AbstractBlock;

final class TableRow extends AbstractBlock
{
    /**
     * @param TableCell[] $cells
     */
    public function __construct(
        private readonly array $cells = [],
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($attributes);
    }

    /**
     * @return TableCell[]
     */
    public function cells(): array
    {
        return $this->cells;
    }
}
