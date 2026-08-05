<?php

declare(strict_types=1);

namespace Fissible\Transmark\Nodes\Inline;

use Fissible\Transmark\Contracts\InlineInterface;

trait HasInlineChildren
{
    /** @var InlineInterface[] */
    private array $children;

    /**
     * @param InlineInterface[] $children
     */
    private function setChildren(array $children): void
    {
        $this->children = $children;
    }

    /**
     * @return InlineInterface[]
     */
    public function children(): array
    {
        return $this->children;
    }
}
