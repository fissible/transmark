<?php

declare(strict_types=1);

namespace Fissible\Transmark\Nodes\Inline;

use Fissible\Transmark\Attributes;
use Fissible\Transmark\Contracts\BlockInterface;
use Fissible\Transmark\Nodes\AbstractInline;

final class Footnote extends AbstractInline
{
    /**
     * @param BlockInterface[] $content
     */
    public function __construct(
        private readonly string $identifier,
        private readonly array $content,
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($attributes);
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    /**
     * @return BlockInterface[]
     */
    public function content(): array
    {
        return $this->content;
    }
}
