<?php

declare(strict_types=1);

namespace Fissible\Transmark\Nodes;

use Fissible\Transmark\Attributes;
use Fissible\Transmark\Contracts\NodeInterface;

abstract class Node implements NodeInterface
{
    public function __construct(
        protected Attributes $attributes = new Attributes(),
    ) {
    }

    public function attributes(): Attributes
    {
        return $this->attributes;
    }
}
