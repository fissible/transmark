<?php

declare(strict_types=1);

namespace Fissible\Transmark\Nodes\Block;

use Fissible\Transmark\Attributes;
use Fissible\Transmark\Nodes\AbstractBlock;

final class Table extends AbstractBlock
{
    /**
     * @param TableRow[] $rows
     */
    public function __construct(
        private readonly array $rows = [],
        private readonly ?TableRow $header = null,
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($attributes);
    }

    /**
     * @return TableRow[]
     */
    public function rows(): array
    {
        return $this->rows;
    }

    public function header(): ?TableRow
    {
        return $this->header;
    }
}
