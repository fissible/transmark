<?php

declare(strict_types=1);

namespace Fissible\Transmark\Contracts;

/**
 * Marker interface for a node that flows within a block
 * (text run, emphasis, link, line break...).
 */
interface InlineInterface extends NodeInterface
{
}
