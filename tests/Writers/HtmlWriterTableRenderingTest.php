<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Writers;

use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Block\Table;
use Fissible\Transmark\Nodes\Block\TableCell;
use Fissible\Transmark\Nodes\Block\TableRow;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Numbering\AbstractNum;
use Fissible\Transmark\Numbering\Level;
use Fissible\Transmark\Numbering\Num;
use Fissible\Transmark\Numbering\NumberFormat;
use Fissible\Transmark\Numbering\NumberingDefinitions;
use Fissible\Transmark\Numbering\NumberingRef;
use Fissible\Transmark\Writers\HtmlWriter;
use PHPUnit\Framework\TestCase;

final class HtmlWriterTableRenderingTest extends TestCase
{
    private function cell(string $text): TableCell
    {
        return new TableCell([new Paragraph([new Text($text)])]);
    }

    private function row(string ...$texts): TableRow
    {
        return new TableRow(array_map($this->cell(...), $texts));
    }

    public function test_renders_a_table_with_header_and_rows(): void
    {
        $table = new Table(
            rows: [$this->row('Alice', '30'), $this->row('Bob', '25')],
            header: $this->row('Name', 'Age'),
        );

        $html = (new HtmlWriter())->write(new Document([$table]));

        self::assertSame(
            '<table>'
            .'<thead><tr><th><p>Name</p></th><th><p>Age</p></th></tr></thead>'
            .'<tbody>'
            .'<tr><td><p>Alice</p></td><td><p>30</p></td></tr>'
            .'<tr><td><p>Bob</p></td><td><p>25</p></td></tr>'
            .'</tbody>'
            .'</table>',
            $html,
        );
    }

    public function test_renders_a_table_without_a_header_and_with_no_empty_thead(): void
    {
        $table = new Table(rows: [$this->row('A'), $this->row('B')]);

        $html = (new HtmlWriter())->write(new Document([$table]));

        self::assertStringNotContainsString('<thead>', $html);
        self::assertSame(
            '<table><tbody><tr><td><p>A</p></td></tr><tr><td><p>B</p></td></tr></tbody></table>',
            $html,
        );
    }

    public function test_renders_colspan_and_rowspan_attributes(): void
    {
        $table = new Table(rows: [
            new TableRow([new TableCell([new Paragraph([new Text('Merged')])], colspan: 2, rowspan: 3)]),
        ]);

        $html = (new HtmlWriter())->write(new Document([$table]));

        self::assertStringContainsString('<td colspan="2" rowspan="3">', $html);
    }

    public function test_does_not_emit_colspan_or_rowspan_attributes_when_both_are_1(): void
    {
        $table = new Table(rows: [$this->row('Plain')]);

        $html = (new HtmlWriter())->write(new Document([$table]));

        self::assertStringNotContainsString('colspan', $html);
        self::assertStringNotContainsString('rowspan', $html);
    }

    public function test_escapes_cell_content(): void
    {
        $table = new Table(rows: [$this->row('<script>alert(1)</script>')]);

        $html = (new HtmlWriter())->write(new Document([$table]));

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_a_numbered_paragraph_inside_a_cell_renders_its_resolved_label_exactly_as_outside_a_table(): void
    {
        // numId 20 uses ilvl 1 (multi-placeholder lvlText) at the top level,
        // which is what makes it "legal" overall - the whole point of this
        // test is that the SAME numId's ilvl-0 counter must keep counting
        // correctly for a paragraph nested inside a table cell, exactly as
        // NumberingEngine already does outside one.
        $document = new Document(
            content: [
                new Paragraph([new Text('Legal parent')], numbering: new NumberingRef(20, 0)),
                new Paragraph([new Text('Legal child')], numbering: new NumberingRef(20, 1)),
                new Table(rows: [
                    new TableRow([new TableCell([
                        new Paragraph([new Text('Another item')], numbering: new NumberingRef(20, 0)),
                    ])]),
                ]),
            ],
            numbering: new NumberingDefinitions(
                abstractNums: [
                    2 => new AbstractNum(2, [
                        0 => new Level(0, NumberFormat::Decimal, '%1.'),
                        1 => new Level(1, NumberFormat::Decimal, '%1.%2.'),
                    ]),
                ],
                nums: [20 => new Num(20, 2)],
            ),
        );

        $html = (new HtmlWriter())->write($document);

        self::assertStringContainsString(
            '<p class="numbered-paragraph legal-level-0">1. Legal parent</p>'
            .'<p class="numbered-paragraph legal-level-1">1.1. Legal child</p><table>',
            $html,
        );
        self::assertStringContainsString(
            '<td><p class="numbered-paragraph legal-level-0">2. Another item</p></td>',
            $html,
        );
    }

    public function test_a_simple_numbered_paragraph_used_only_inside_a_cell_still_renders_as_a_list(): void
    {
        // Regression test: NumberingShapeClassifier must find this
        // paragraph via the same recursive walk NumberingEngine uses, or
        // it silently mis-classifies as "legal" purely because its only
        // usage is nested inside a table cell rather than at the top level.
        $document = new Document(
            content: [
                new Table(rows: [
                    new TableRow([new TableCell([
                        new Paragraph([new Text('Item one')], numbering: new NumberingRef(30, 0)),
                    ])]),
                ]),
            ],
            numbering: new NumberingDefinitions(
                abstractNums: [3 => new AbstractNum(3, [
                    0 => new Level(0, NumberFormat::Decimal, '%1.'),
                ])],
                nums: [30 => new Num(30, 3)],
            ),
        );

        $html = (new HtmlWriter())->write($document);

        self::assertStringContainsString('<td><ol><li>Item one</li></ol></td>', $html);
        self::assertStringNotContainsString('numbered-paragraph', $html);
    }
}
