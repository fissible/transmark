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
    private bool $closed = false;

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

    public function rawPart(string $partPath): ?string
    {
        if ($this->closed) {
            throw new InvalidPackageException(sprintf(
                'Cannot read "%s": package "%s" is already closed.',
                $partPath,
                $this->path,
            ));
        }

        $contents = $this->zip->getFromName($partPath);

        return $contents === false ? null : $contents;
    }

    public function part(string $partPath): ?\DOMDocument
    {
        $xml = $this->rawPart($partPath);

        if ($xml === null) {
            return null;
        }

        if ($xml === '') {
            throw new InvalidPackageException(sprintf(
                'Part "%s" in "%s" is empty and cannot be parsed as XML.',
                $partPath,
                $this->path,
            ));
        }

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = true;

        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            $firstError = $errors[0] ?? null;

            if ($firstError !== null) {
                throw new InvalidPackageException(sprintf(
                    'Part "%s" in "%s" is not well-formed XML: %s (line %d).',
                    $partPath,
                    $this->path,
                    trim($firstError->message),
                    $firstError->line,
                ));
            }

            throw new InvalidPackageException(sprintf(
                'Part "%s" in "%s" is not well-formed XML.',
                $partPath,
                $this->path,
            ));
        }

        return $dom;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->zip->close();
        $this->closed = true;
    }
}
