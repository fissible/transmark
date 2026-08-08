<?php

declare(strict_types=1);

namespace Fissible\Transmark\Format\Exception;

use Fissible\Transmark\Format\Format;

/**
 * Thrown when a file's detected content and its claimed extension disagree.
 * Carries both signals so the caller decides policy (reject, proceed, ask the
 * user) instead of the library silently picking a side.
 */
final class FormatMismatchException extends \RuntimeException
{
    public function __construct(
        public readonly Format $detected,
        public readonly Format $claimedByExtension,
        public readonly string $extension,
    ) {
        parent::__construct(sprintf(
            'File extension ".%s" claims %s, but the content was detected as %s.',
            $extension,
            $claimedByExtension->value,
            $detected->value,
        ));
    }
}
