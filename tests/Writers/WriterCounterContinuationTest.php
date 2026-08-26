<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Writers;

use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Numbering\AbstractNum;
use Fissible\Transmark\Numbering\Level;
use Fissible\Transmark\Numbering\Num;
use Fissible\Transmark\Numbering\NumberFormat;
use Fissible\Transmark\Numbering\NumberingDefinitions;
use Fissible\Transmark\Numbering\NumberingRef;
use Fissible\Transmark\Writers\HtmlWriter;
use Fissible\Transmark\Writers\MarkdownWriter;
use PHPUnit\Framework\TestCase;

/**
 * Word continues a numId's counter across intervening non-list paragraphs.
 * A plain paragraph between numbered ones must not restart the visible
 * numbering: HTML reopens the list with the engine-computed start, and
 * Markdown emits literal markers from the same counters.
 */
final class WriterCounterContinuationTest extends TestCase
{
    public function test_html_list_continues_numbering_after_an_interruption(): void
    {
        $document = new Document($this->interruptedRun(), $this->definitions());

        self::assertSame(
            '<ol><li>first</li><li>second</li></ol><p>interruption</p>'
            .'<ol start="3"><li>third</li></ol>',
            (new HtmlWriter())->write($document),
        );
    }

    public function test_markdown_list_continues_numbering_after_an_interruption(): void
    {
        $document = new Document($this->interruptedRun(), $this->definitions());

        self::assertSame(
            "1. first\n2. second\n\ninterruption\n\n3. third\n",
            (new MarkdownWriter())->write($document),
        );
    }

    /**
     * @return BlockInterface[]|array<int, Paragraph>
     */
    private function interruptedRun(): array
    {
        return [
            $this->numbered('first'),
            $this->numbered('second'),
            new Paragraph([new Text('interruption')]),
            $this->numbered('third'),
        ];
    }

    private function definitions(): NumberingDefinitions
    {
        return (new NumberingDefinitions())
            ->withAbstractNum(new AbstractNum(10, [
                new Level(0, NumberFormat::Decimal, '%1.'),
            ]))
            ->withNum(new Num(5, 10));
    }

    private function numbered(string $text): Paragraph
    {
        return new Paragraph([new Text($text)], numbering: new NumberingRef(5, 0));
    }
}
