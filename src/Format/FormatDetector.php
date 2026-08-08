<?php

declare(strict_types=1);

namespace Fissible\Transmark\Format;

use Fissible\Transmark\Format\Exception\FormatMismatchException;

/**
 * Content-based format detection. Extension is treated as a secondary,
 * non-authoritative signal only — trusted just enough to catch a mismatch
 * against detected content (e.g. a renamed/mislabeled file), never as the
 * primary source of truth.
 */
final class FormatDetector
{
    /**
     * @var array<string, Format>
     */
    private const EXTENSION_MAP = [
        'docx' => Format::Docx,
    ];

    public static function detect(string $content): Format
    {
        if (!str_starts_with($content, "PK\x03\x04")) {
            return Format::Unknown;
        }

        $path = tempnam(sys_get_temp_dir(), 'transmark-format-');
        file_put_contents($path, $content);

        try {
            $zip = new \ZipArchive();

            if ($zip->open($path) !== true) {
                return Format::Unknown;
            }

            try {
                return $zip->locateName('word/document.xml') !== false
                    ? Format::Docx
                    : Format::Unknown;
            } finally {
                $zip->close();
            }
        } finally {
            unlink($path);
        }
    }

    /**
     * Detects the format of $content and, when $filename is given, cross-checks
     * it against the extension. Throws when the two disagree on a known
     * extension; an unrecognized or missing extension is not a claim, so it
     * cannot conflict.
     */
    public static function validate(string $content, ?string $filename = null): Format
    {
        $detected = self::detect($content);

        if ($filename === null) {
            return $detected;
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $claimed = self::EXTENSION_MAP[$extension] ?? null;

        if ($claimed !== null && $claimed !== $detected) {
            throw new FormatMismatchException($detected, $claimed, $extension);
        }

        return $detected;
    }
}
