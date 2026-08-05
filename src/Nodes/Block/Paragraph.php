<?php

declare(strict_types=1);

namespace Fissible\Transmark\Nodes\Block;

use Fissible\Transmark\Attributes;
use Fissible\Transmark\Contracts\InlineInterface;
use Fissible\Transmark\Nodes\AbstractBlock;
use Fissible\Transmark\Numbering\NumberingRef;

/**
 * Word's model, not HTML's: a numbered paragraph is flat and
 * carries a NumberingRef pointer rather than living inside a
 * nested list container. Legal outlines (1., 7.1, (a)) are
 * numbered paragraphs, not Heading nodes.
 */
final class Paragraph extends AbstractBlock
{
    /**
     * @param InlineInterface[] $inlines
     */
    public function __construct(
        private readonly array $inlines = [],
        private readonly ?string $styleName = null,
        private readonly ?NumberingRef $numbering = null,
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($attributes);
    }

    /**
     * @return InlineInterface[]
     */
    public function inlines(): array
    {
        return $this->inlines;
    }

    public function styleName(): ?string
    {
        return $this->styleName;
    }

    public function numbering(): ?NumberingRef
    {
        return $this->numbering;
    }

    public function isNumbered(): bool
    {
        return $this->numbering !== null;
    }
}
