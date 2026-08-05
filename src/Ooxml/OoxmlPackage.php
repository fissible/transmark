<?php

declare(strict_types=1);

namespace Fissible\Transmark\Ooxml;

use Fissible\Transmark\Ooxml\Exception\InvalidPackageException;

/**
 * A thin zip + DOM reader for OOXML packages (DOCX, and eventually
 * XLSX) — format-agnostic on purpose, since both are a zip of XML
 * parts plus a relationships/content-types manifest.
 */
final class OoxmlPackage
{
    private function __construct(
        private readonly \ZipArchive $zip,
        private readonly string $path,
    ) {
    }

    public static function open(string $path): self
    {
        if (!is_file($path)) {
            throw new InvalidPackageException(sprintf('No such file: "%s".', $path));
        }

        $zip = new \ZipArchive();
        $status = $zip->open($path);

        if ($status !== true) {
            throw new InvalidPackageException(sprintf(
                '"%s" is not a valid zip archive (ZipArchive error code %d).',
                $path,
                $status,
            ));
        }

        return new self($zip, $path);
    }
}
