<?php

declare(strict_types=1);

namespace Fissible\Transmark\Numbering;

use Fissible\Transmark\Nodes\Block\Paragraph;

/**
 * Output of NumberingEngine::resolve(): computed labels keyed by
 * paragraph identity, kept separate from the tree so writers can
 * consume them without the model ever caching derived state.
 */
final class NumberingLabelMap
{
    /**
     * @param array<int, string> $labels keyed by spl_object_id(Paragraph)
     */
    public function __construct(
        private readonly array $labels,
    ) {
    }

    public function labelFor(Paragraph $paragraph): ?string
    {
        return $this->labels[spl_object_id($paragraph)] ?? null;
    }
}
