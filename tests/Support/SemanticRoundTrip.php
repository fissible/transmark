<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Support;

use Fissible\Transmark\Contracts\ReaderInterface;
use Fissible\Transmark\Contracts\WriterInterface;
use Fissible\Transmark\Document;

final class SemanticRoundTrip
{
    public static function through(
        Document $document,
        WriterInterface $writer,
        ReaderInterface $reader,
    ): Document {
        return $reader->read($writer->write($document));
    }
}
