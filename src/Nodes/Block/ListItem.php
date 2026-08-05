<?php

declare(strict_types=1);

namespace Fissible\Transmark\Nodes\Block;

use Fissible\Transmark\Attributes;
use Fissible\Transmark\Contracts\BlockInterface;
use Fissible\Transmark\Nodes\AbstractBlock;

final class ListItem extends AbstractBlock
{
    /**
     * @param BlockInterface[] $content
     */
    public function __construct(
        private readonly array $content = [],
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
}
