<?php

declare(strict_types=1);

namespace Fissible\Transmark\Nodes\Inline;

use Fissible\Transmark\Attributes;
use Fissible\Transmark\Nodes\AbstractInline;

/**
 * `$src` is a URL/path reference for a linked image (e.g. from HTML). An
 * embedded image (e.g. from DOCX, which has no natural "src" of its own)
 * instead carries its raw bytes in `$data` with a declared `$mimeType` -
 * writers should prefer embedding `$data` when present and fall back to
 * `$src` as a plain reference otherwise.
 */
final class InlineImage extends AbstractInline
{
    public function __construct(
        private readonly string $src,
        private readonly string $alt = '',
        private readonly ?string $title = null,
        private readonly ?string $data = null,
        private readonly ?string $mimeType = null,
        private readonly ?int $width = null,
        private readonly ?int $height = null,
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

    public function data(): ?string
    {
        return $this->data;
    }

    public function mimeType(): ?string
    {
        return $this->mimeType;
    }

    public function width(): ?int
    {
        return $this->width;
    }

    public function height(): ?int
    {
        return $this->height;
    }
}
