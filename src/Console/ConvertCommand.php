<?php

declare(strict_types=1);

namespace Fissible\Transmark\Console;

use Fissible\Transmark\Contracts\ReaderInterface;
use Fissible\Transmark\Contracts\WriterInterface;
use Fissible\Transmark\Readers\DocxReader;
use Fissible\Transmark\Readers\HtmlReader;
use Fissible\Transmark\Readers\MarkdownReader;
use Fissible\Transmark\Writers\DocxWriter;
use Fissible\Transmark\Writers\HtmlWriter;
use Fissible\Transmark\Writers\MarkdownWriter;

/**
 * The `convert` subcommand behind `bin/transmark`: picks a reader/writer
 * pair by file extension (or an explicit --from/--to override) and runs
 * $writer->write($reader->read($contents)).
 *
 * Output is delegated to injected closures rather than writing directly to
 * STDOUT/STDERR, so this class is testable without spawning a subprocess;
 * `bin/transmark` wires the closures to the real streams.
 */
final class ConvertCommand
{
    /** @var array<string, class-string<ReaderInterface>> */
    private const READERS = [
        'docx' => DocxReader::class,
        'html' => HtmlReader::class,
        'htm' => HtmlReader::class,
        'md' => MarkdownReader::class,
        'markdown' => MarkdownReader::class,
    ];

    /** @var array<string, class-string<WriterInterface>> */
    private const WRITERS = [
        'docx' => DocxWriter::class,
        'html' => HtmlWriter::class,
        'htm' => HtmlWriter::class,
        'md' => MarkdownWriter::class,
        'markdown' => MarkdownWriter::class,
    ];

    private const USAGE = 'Usage: transmark convert [--from=FORMAT] [--to=FORMAT] <input> <output>'.PHP_EOL
        .'  FORMAT is one of: docx, html, md'.PHP_EOL
        .'  Without --from/--to, the format is inferred from each path\'s extension.'.PHP_EOL;

    public function __construct(
        private readonly \Closure $writeOut,
        private readonly \Closure $writeErr,
    ) {
    }

    /**
     * @param string[] $args argv without the script name, e.g. ['convert', 'in.docx', 'out.html']
     */
    public function run(array $args): int
    {
        if (($args[0] ?? null) !== 'convert') {
            ($this->writeErr)(self::USAGE);

            return 1;
        }

        [$positional, $flags] = $this->parseArgs(array_slice($args, 1));

        if (count($positional) !== 2) {
            ($this->writeErr)(self::USAGE);

            return 1;
        }

        [$inputPath, $outputPath] = $positional;

        $fromFormat = $flags['from'] ?? $this->extensionOf($inputPath);
        $toFormat = $flags['to'] ?? $this->extensionOf($outputPath);

        $readerClass = self::READERS[$fromFormat] ?? null;
        if ($readerClass === null) {
            ($this->writeErr)($this->unsupportedFormatMessage('input', $fromFormat));

            return 1;
        }

        $writerClass = self::WRITERS[$toFormat] ?? null;
        if ($writerClass === null) {
            ($this->writeErr)($this->unsupportedFormatMessage('output', $toFormat));

            return 1;
        }

        if (!is_file($inputPath)) {
            ($this->writeErr)(sprintf('Input file not found: "%s".'.PHP_EOL, $inputPath));

            return 1;
        }

        $content = file_get_contents($inputPath);
        if ($content === false) {
            ($this->writeErr)(sprintf('Unable to read input file: "%s".'.PHP_EOL, $inputPath));

            return 1;
        }

        try {
            $document = (new $readerClass())->read($content);
            $output = (new $writerClass())->write($document);
        } catch (\Throwable $exception) {
            ($this->writeErr)(sprintf('Conversion failed: %s'.PHP_EOL, $exception->getMessage()));

            return 1;
        }

        if (file_put_contents($outputPath, $output) === false) {
            ($this->writeErr)(sprintf('Unable to write output file: "%s".'.PHP_EOL, $outputPath));

            return 1;
        }

        ($this->writeOut)(sprintf('Wrote %s'.PHP_EOL, $outputPath));

        return 0;
    }

    /**
     * @param string[] $args
     *
     * @return array{0: string[], 1: array<string, string>}
     */
    private function parseArgs(array $args): array
    {
        $positional = [];
        $flags = [];

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
                [$name, $value] = explode('=', substr($arg, 2), 2);
                $flags[$name] = strtolower($value);

                continue;
            }

            $positional[] = $arg;
        }

        return [$positional, $flags];
    }

    private function extensionOf(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    private function unsupportedFormatMessage(string $direction, string $format): string
    {
        if ($format === '') {
            return sprintf(
                'Unable to determine the %s format (no file extension). Use --from/--to to specify one of: %s.'.PHP_EOL,
                $direction,
                implode(', ', array_keys(self::READERS)),
            );
        }

        return sprintf('Unsupported %s format "%s".'.PHP_EOL, $direction, $format);
    }
}
