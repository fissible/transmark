<?php

declare(strict_types=1);

namespace Fissible\Transmark\Writers\Exception;

use Fissible\Transmark\Contracts\NodeInterface;

final class UnsupportedNodeException extends DocxWriteException
{
    public static function at(NodeInterface $node, string $path): self
    {
        return new self(sprintf(
            'DocxWriter does not support %s at %s.',
            $node::class,
            $path,
        ));
    }
}
