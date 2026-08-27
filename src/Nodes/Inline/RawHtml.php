<?php

declare(strict_types=1);

namespace Fissible\Transmark\Nodes\Inline;

use Fissible\Transmark\Attributes;
use Fissible\Transmark\Nodes\AbstractInline;

/**
 * Literal raw HTML that has no first-class representation in the tree —
 * e.g. a <div> block or a <br> tag embedded in Markdown. Writers emit
 * the content verbatim, bypassing their normal escaping: this is
 * author-controlled content, and passing it through is the point (the
 * same behavior as pandoc and GitHub). DocxWriter has no equivalent and
 * throws an unsupported-node exception.
 */
final class RawHtml extends AbstractInline
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
