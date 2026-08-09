<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Console;

use Fissible\Transmark\Console\ConvertCommand;
use Fissible\Transmark\Readers\DocxReader;
use Fissible\Transmark\Writers\HtmlWriter;
use PHPUnit\Framework\TestCase;

final class ConvertCommandTest extends TestCase
{
    /** @var string[] paths created during a test, cleaned up in tearDown */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempPaths = [];
    }

    private function runCommand(array $args): array
    {
        $out = '';
        $err = '';
        $command = new ConvertCommand(
            static function (string $message) use (&$out): void {
                $out .= $message;
            },
            static function (string $message) use (&$err): void {
                $err .= $message;
            },
        );

        $exitCode = $command->run($args);

        return [$exitCode, $out, $err];
    }

    private function tempPath(string $suffix): string
    {
        $path = tempnam(sys_get_temp_dir(), 'transmark-cli-test-').$suffix;
        $this->tempPaths[] = $path;

        return $path;
    }

    private function writeFile(string $suffix, string $contents): string
    {
        $path = $this->tempPath($suffix);
        file_put_contents($path, $contents);

        return $path;
    }

    private function docxFixture(): string
    {
        $path = $this->tempPath('.docx');
        touch($path);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body><w:p><w:r><w:t>Hello from the CLI</w:t></w:r></w:p></w:body>'
            .'</w:document>',
        ));
        self::assertTrue($zip->close());

        return $path;
    }

    public function test_converts_docx_to_html_matching_direct_reader_writer_call(): void
    {
        $inputPath = $this->docxFixture();
        $outputPath = $this->tempPath('.html');

        [$exitCode] = $this->runCommand(['convert', $inputPath, $outputPath]);

        self::assertSame(0, $exitCode);

        $expected = (new HtmlWriter())->write((new DocxReader())->read(file_get_contents($inputPath)));
        self::assertSame($expected, file_get_contents($outputPath));
    }

    public function test_converts_markdown_to_html(): void
    {
        $inputPath = $this->writeFile('.md', '# Title'.PHP_EOL.PHP_EOL.'Body **text**.');
        $outputPath = $this->tempPath('.html');

        [$exitCode] = $this->runCommand(['convert', $inputPath, $outputPath]);

        self::assertSame(0, $exitCode);
        $html = file_get_contents($outputPath);
        self::assertStringContainsString('<h1>Title</h1>', $html);
        self::assertStringContainsString('<strong>text</strong>', $html);
    }

    public function test_infers_format_from_uppercase_extension(): void
    {
        $inputPath = $this->writeFile('.MD', 'Plain paragraph.');
        $outputPath = $this->tempPath('.HTML');

        [$exitCode] = $this->runCommand(['convert', $inputPath, $outputPath]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('<p>Plain paragraph.</p>', file_get_contents($outputPath));
    }

    public function test_from_and_to_flags_override_extension_detection(): void
    {
        $inputPath = $this->writeFile('.txt', 'Plain paragraph.');
        $outputPath = $this->tempPath('.txt');

        [$exitCode] = $this->runCommand(['convert', '--from=md', '--to=html', $inputPath, $outputPath]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('<p>Plain paragraph.</p>', file_get_contents($outputPath));
    }

    public function test_unsupported_input_extension_produces_a_clear_error_and_nonzero_exit(): void
    {
        $inputPath = $this->writeFile('.pdf', 'not really a pdf');
        $outputPath = $this->tempPath('.html');

        [$exitCode, , $err] = $this->runCommand(['convert', $inputPath, $outputPath]);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('pdf', $err);
        self::assertStringNotContainsString('Stack trace', $err);
        self::assertFalse(is_file($outputPath));
    }

    public function test_unsupported_output_extension_produces_a_clear_error_and_nonzero_exit(): void
    {
        $inputPath = $this->writeFile('.md', 'Plain paragraph.');
        $outputPath = $this->tempPath('.pdf');

        [$exitCode, , $err] = $this->runCommand(['convert', $inputPath, $outputPath]);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('pdf', $err);
        self::assertFalse(is_file($outputPath));
    }

    public function test_missing_positional_arguments_shows_usage_and_nonzero_exit(): void
    {
        [$exitCode, , $err] = $this->runCommand(['convert', 'only-one-arg.md']);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('Usage', $err);
    }

    public function test_unrecognized_subcommand_shows_usage_and_nonzero_exit(): void
    {
        [$exitCode, , $err] = $this->runCommand(['not-a-real-subcommand']);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('Usage', $err);
    }

    public function test_nonexistent_input_file_produces_a_clear_error_and_nonzero_exit(): void
    {
        $outputPath = $this->tempPath('.html');

        [$exitCode, , $err] = $this->runCommand(['convert', '/no/such/file.md', $outputPath]);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('/no/such/file.md', $err);
        self::assertFalse(is_file($outputPath));
    }

    public function test_reader_failure_is_caught_as_a_clean_error_not_a_stack_trace(): void
    {
        // A .docx file that isn't a valid zip at all - DocxReader will fail
        // deep inside OoxmlPackage. The CLI must not let that exception
        // (or its stack trace) reach the user directly.
        $inputPath = $this->writeFile('.docx', 'this is not a zip archive');
        $outputPath = $this->tempPath('.html');

        [$exitCode, , $err] = $this->runCommand(['convert', $inputPath, $outputPath]);

        self::assertNotSame(0, $exitCode);
        self::assertStringNotContainsString('Stack trace', $err);
        self::assertStringNotContainsString('#0 ', $err);
        self::assertFalse(is_file($outputPath));
    }

    public function test_successful_conversion_prints_a_confirmation_to_stdout(): void
    {
        $inputPath = $this->writeFile('.md', 'Plain paragraph.');
        $outputPath = $this->tempPath('.html');

        [$exitCode, $out] = $this->runCommand(['convert', $inputPath, $outputPath]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString($outputPath, $out);
    }
}
