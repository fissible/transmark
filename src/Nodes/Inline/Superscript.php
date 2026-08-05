<?php

declare(strict_types=1);

namespace Fissible\Transmark\Nodes\Inline;

use Fissible\Transmark\Attributes;
use Fissible\Transmark\Contracts\InlineInterface;
use Fissible\Transmark\Nodes\AbstractInline;

final class Superscript extends AbstractInline
{
    use HasInlineChildren;

    /**
     * @param InlineInterface[] $children
     */
    public function __construct(array $children, Attributes $attributes = new Attributes())
    {
        parent::__construct($attributes);
        $this->setChildren($children);
    }
}
