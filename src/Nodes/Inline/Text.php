<?php

declare(strict_types=1);

namespace Fissible\Transmark\Nodes\Inline;

use Fissible\Transmark\Attributes;
use Fissible\Transmark\Nodes\AbstractInline;

final class Text extends AbstractInline
{
    public function __construct(
        private readonly string $content,
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($attributes);
    }

    public function content(): string
    {
        return $this->content;
    }
}
