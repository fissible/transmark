<?php

declare(strict_types=1);

namespace Fissible\Transmark\Writers\Exception;

use Fissible\Transmark\Contracts\NodeInterface;

final class UnsupportedHtmlNodeException extends HtmlWriteException
{
    public static function at(NodeInterface $node): self
    {
        return new self(sprintf(
            'HtmlWriter does not support %s.',
            $node::class,
        ));
    }
}
