<?php

declare(strict_types=1);

namespace Fissible\Transmark\Nodes\Inline;

use Fissible\Transmark\Attributes;
use Fissible\Transmark\Nodes\AbstractInline;

final class InlineImage extends AbstractInline
{
    public function __construct(
        private readonly string $src,
        private readonly string $alt = '',
        private readonly ?string $title = null,
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($attributes);
    }

    public function src(): string
    {
        return $this->src;
    }

    public function alt(): string
    {
        return $this->alt;
    }

    public function title(): ?string
    {
        return $this->title;
    }
}
