<?php

declare(strict_types=1);

namespace Fissible\Transmark;

use Fissible\Transmark\Contracts\BlockInterface;
use Fissible\Transmark\Numbering\NumberingDefinitions;

final class Document
{
    /**
     * @param BlockInterface[] $content
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly array $content = [],
        private readonly NumberingDefinitions $numbering = new NumberingDefinitions(),
        private readonly array $metadata = [],
    ) {
    }

    /**
     * @return BlockInterface[]
     */
    public function content(): array
    {
        return $this->content;
    }

    public function numbering(): NumberingDefinitions
    {
        return $this->numbering;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function metadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }
}
