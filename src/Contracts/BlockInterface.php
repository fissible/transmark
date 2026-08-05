<?php

declare(strict_types=1);

namespace Fissible\Transmark\Contracts;

/**
 * Marker interface for a structural node that owns a vertical
 * slice of the document (paragraph, heading, table, list item...).
 */
interface BlockInterface extends NodeInterface
{
}
