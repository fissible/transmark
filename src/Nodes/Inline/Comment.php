<?php

declare(strict_types=1);

namespace Fissible\Transmark\Nodes\Inline;

use Fissible\Transmark\Attributes;
use Fissible\Transmark\Contracts\BlockInterface;
use Fissible\Transmark\Nodes\AbstractInline;

final class Comment extends AbstractInline
{
    /**
     * @param BlockInterface[] $content
     */
    public function __construct(
        private readonly array $content,
        private readonly ?string $author = null,
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

    public function author(): ?string
    {
        return $this->author;
    }
}
