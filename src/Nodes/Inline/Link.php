<?php

declare(strict_types=1);

namespace Fissible\Transmark\Nodes\Inline;

use Fissible\Transmark\Attributes;
use Fissible\Transmark\Contracts\InlineInterface;
use Fissible\Transmark\Nodes\AbstractInline;

final class Link extends AbstractInline
{
    use HasInlineChildren;

    /**
     * @param InlineInterface[] $children
     */
    public function __construct(
        private readonly string $href,
        array $children,
        private readonly ?string $title = null,
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($attributes);
        $this->setChildren($children);
    }

    public function href(): string
    {
        return $this->href;
    }

    public function title(): ?string
    {
        return $this->title;
    }
}
