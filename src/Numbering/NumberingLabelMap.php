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
     * @param array<int, array{ilvl: int, value: int}> $counters keyed by
     *   spl_object_id(Paragraph): the paragraph's counter value at its own
     *   numbering level, exposed so writers rendering native list syntax
     *   (e.g. <ol start>) use the same counters the labels came from.
     */
    public function __construct(
        private readonly array $labels,
        private readonly array $counters = [],
    ) {
    }

    public function labelFor(Paragraph $paragraph): ?string
    {
        return $this->labels[spl_object_id($paragraph)] ?? null;
    }

    public function counterFor(Paragraph $paragraph): ?int
    {
        return $this->counters[spl_object_id($paragraph)]['value'] ?? null;
    }
}
