<?php

declare(strict_types=1);

namespace Fissible\Transmark\Nodes\Block;

use Fissible\Transmark\Attributes;
use Fissible\Transmark\Nodes\AbstractBlock;

final class CodeBlock extends AbstractBlock
{
    public function __construct(
        private readonly string $content,
        private readonly ?string $language = null,
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($attributes);
    }

    public function content(): string
    {
        return $this->content;
    }

    public function language(): ?string
    {
        return $this->language;
    }
}
