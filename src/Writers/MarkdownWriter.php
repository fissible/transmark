<?php

declare(strict_types=1);

namespace Fissible\Transmark\Writers;

use Fissible\Transmark\Contracts\BlockInterface;
use Fissible\Transmark\Contracts\InlineInterface;
use Fissible\Transmark\Contracts\WriterInterface;
use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\BlockQuote;
use Fissible\Transmark\Nodes\Block\CodeBlock;
use Fissible\Transmark\Nodes\Block\Heading;
use Fissible\Transmark\Nodes\Block\HorizontalRule;
use Fissible\Transmark\Nodes\Block\Image;
use Fissible\Transmark\Nodes\Block\ListItem;
use Fissible\Transmark\Nodes\Block\ListNode;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Block\Table;
use Fissible\Transmark\Nodes\Block\TableCell;
use Fissible\Transmark\Nodes\Block\TableRow;
use Fissible\Transmark\Nodes\Inline\Code;
use Fissible\Transmark\Nodes\Inline\Emphasis;
use Fissible\Transmark\Nodes\Inline\InlineImage;
use Fissible\Transmark\Nodes\Inline\LineBreak;
use Fissible\Transmark\Nodes\Inline\Link;
use Fissible\Transmark\Nodes\Inline\Strike;
use Fissible\Transmark\Nodes\Inline\Strong;
use Fissible\Transmark\Nodes\Inline\Subscript;
use Fissible\Transmark\Nodes\Inline\Superscript;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Nodes\Inline\Underline;
use Fissible\Transmark\Numbering\NumberFormat;
use Fissible\Transmark\Numbering\NumberingEngine;
use Fissible\Transmark\Numbering\NumberingLabelMap;
use Fissible\Transmark\Numbering\NumberingShapeClassifier;

/**
 * Underline, superscript, and subscript use raw inline HTML because CommonMark
 * has no native syntax for them. Legal outline labels are emitted as literal
 * text, so neither representation can reconstruct the original OOXML metadata.
 */
final class MarkdownWriter implements WriterInterface
{
    public function write(Document $document): string
    {
        $markdown = $this->renderBlocks(
            $document->content(),
            $document,
            (new NumberingShapeClassifier())->classify($document),
            (new NumberingEngine())->resolve($document),
        );

        return $markdown === '' ? '' : rtrim($markdown)."\n";
    }

    /**
     * @param BlockInterface[] $blocks
     * @param array<int, bool> $simpleNumIds
     */
    private function renderBlocks(
        array $blocks,
        Document $document,
        array $simpleNumIds,
        NumberingLabelMap $labels,
    ): string {
        $rendered = [];
        $count = count($blocks);

        for ($index = 0; $index < $count; ++$index) {
            $block = $blocks[$index];

            if ($block instanceof Paragraph && $this->isSimpleNumbered($block, $simpleNumIds)) {
                $paragraphs = [];

                while (
                    $index < $count
                    && $blocks[$index] instanceof Paragraph
                    && $this->isSimpleNumbered($blocks[$index], $simpleNumIds)
                ) {
                    $paragraphs[] = $blocks[$index];
                    ++$index;
                }

                $rendered[] = $this->renderSimpleNumberedParagraphs($paragraphs, $document);
                --$index;

                continue;
            }

            $output = $this->renderBlock($block, $document, $simpleNumIds, $labels);
            if ($output !== '') {
                $rendered[] = $output;
            }
        }

        return implode("\n\n", $rendered);
    }

    /**
     * @param array<int, bool> $simpleNumIds
     */
    private function renderBlock(
        BlockInterface $block,
        Document $document,
        array $simpleNumIds,
        NumberingLabelMap $labels,
    ): string {
        return match (true) {
            $block instanceof Heading => str_repeat('#', $block->level()).' '
                .$this->renderInlines($block->inlines()),
            $block instanceof Paragraph => $this->renderParagraph($block, $simpleNumIds, $labels),
            $block instanceof ListNode => $this->renderStructuralList(
                $block,
                $document,
                $simpleNumIds,
                $labels,
            ),
            $block instanceof BlockQuote => $this->renderBlockQuote(
                $block,
                $document,
                $simpleNumIds,
                $labels,
            ),
            $block instanceof CodeBlock => $this->renderCodeBlock($block),
            $block instanceof HorizontalRule => '---',
            $block instanceof Table => $this->renderTable($block),
            $block instanceof Image => $this->renderImage($block),
            default => '',
        };
    }

    /**
     * @param array<int, bool> $simpleNumIds
     */
    private function renderParagraph(
        Paragraph $paragraph,
        array $simpleNumIds,
        NumberingLabelMap $labels,
    ): string {
        $numbering = $paragraph->numbering();
        if (
            $numbering !== null
            && ($simpleNumIds[$numbering->numId()] ?? null) === false
            && ($label = $labels->labelFor($paragraph)) !== null
        ) {
            $prefix = $label === '' ? '' : $this->escapeLegalLabel($label).' ';

            // No per-level indentation: four or more leading spaces parse as
            // an indented code block, so the label alone carries the depth.
            return $prefix.$this->renderInlines($paragraph->inlines());
        }

        return $this->renderInlines($paragraph->inlines());
    }

    /**
     * @param Paragraph[] $paragraphs
     */
    private function renderSimpleNumberedParagraphs(array $paragraphs, Document $document): string
    {
        $lines = [];
        $counters = [];
        $baseIlvl = [];
        $previousNumId = null;
        $previousIlvl = null;

        foreach ($paragraphs as $paragraph) {
            $numbering = $paragraph->numbering();
            if ($numbering === null) {
                continue;
            }

            $numId = $numbering->numId();
            $ilvl = $numbering->ilvl();
            $level = $document->numbering()->levelFor($numId, $ilvl);

            if ($previousNumId !== null && $previousNumId !== $numId && $ilvl <= ($previousIlvl ?? 0)) {
                $lines[] = '';
            }

            // A numId's first-seen ilvl in this run becomes its indent root,
            // not necessarily 0 - a "simple" list can start at ilvl > 0 (e.g.
            // its true parent belongs to a different, "legal"-classified
            // numId rendered as plain text elsewhere). Without this,
            // CommonMark sees indentation with no enclosing list item and
            // reads it as an indented code block instead of a nested list.
            $baseIlvl[$numId] ??= $ilvl;
            $relativeIlvl = max(0, $ilvl - $baseIlvl[$numId]);

            if ($level?->format() === NumberFormat::Bullet) {
                $marker = '-';
            } else {
                $num = $document->numbering()->num($numId);
                $levelOverrides = $num?->levelOverrides() ?? [];
                $start = $levelOverrides[$ilvl] ?? $level?->start() ?? 1;
                $counters[$numId][$ilvl] = ($counters[$numId][$ilvl] ?? $start - 1) + 1;
                $marker = $counters[$numId][$ilvl].'.';
            }

            foreach (array_keys($counters[$numId] ?? []) as $deeperIlvl) {
                if ($deeperIlvl > $ilvl) {
                    unset($counters[$numId][$deeperIlvl]);
                }
            }

            $lines[] = str_repeat(' ', $relativeIlvl * 4)
                .$marker.' '
                .$this->renderInlines($paragraph->inlines());
            $previousNumId = $numId;
            $previousIlvl = $ilvl;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, bool> $simpleNumIds
     */
    private function renderStructuralList(
        ListNode $list,
        Document $document,
        array $simpleNumIds,
        NumberingLabelMap $labels,
        int $indent = 0,
    ): string {
        $lines = [];

        foreach ($list->items() as $index => $item) {
            $marker = $list->type() === ListNode::TYPE_ORDERED
                ? ($list->start() + $index).'.'
                : '-';
            $lines[] = $this->renderListItem(
                $item,
                $marker,
                $indent,
                $document,
                $simpleNumIds,
                $labels,
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, bool> $simpleNumIds
     */
    private function renderListItem(
        ListItem $item,
        string $marker,
        int $indent,
        Document $document,
        array $simpleNumIds,
        NumberingLabelMap $labels,
    ): string {
        $content = $item->content();
        $prefix = str_repeat(' ', $indent).$marker.' ';

        if ($content === []) {
            return rtrim($prefix);
        }

        $first = array_shift($content);
        if ($first instanceof Paragraph && !$first->isNumbered()) {
            $output = $prefix.$this->renderInlines($first->inlines());
        } else {
            $renderedFirst = $this->renderBlock($first, $document, $simpleNumIds, $labels);
            $output = rtrim($prefix)."\n".$this->indentLines($renderedFirst, $indent + 4);
        }

        foreach ($content as $block) {
            if ($block instanceof ListNode) {
                $output .= "\n".$this->renderStructuralList(
                    $block,
                    $document,
                    $simpleNumIds,
                    $labels,
                    $indent + 4,
                );
                continue;
            }

            $rendered = $this->renderBlock($block, $document, $simpleNumIds, $labels);
            if ($rendered !== '') {
                $output .= "\n\n".$this->indentLines($rendered, $indent + 4);
            }
        }

        return $output;
    }

    /**
     * @param array<int, bool> $simpleNumIds
     */
    private function renderBlockQuote(
        BlockQuote $quote,
        Document $document,
        array $simpleNumIds,
        NumberingLabelMap $labels,
    ): string {
        $content = $this->renderBlocks($quote->content(), $document, $simpleNumIds, $labels);

        return implode("\n", array_map(
            static fn (string $line): string => $line === '' ? '>' : '> '.$line,
            explode("\n", $content),
        ));
    }

    private function renderCodeBlock(CodeBlock $block): string
    {
        preg_match_all('/`+/', $block->content(), $matches);
        $longest = 0;
        foreach ($matches[0] as $run) {
            $longest = max($longest, strlen($run));
        }

        $fence = str_repeat('`', max(3, $longest + 1));
        $info = $block->language() === null ? '' : $this->escapeInfoString($block->language());

        return $fence.$info."\n".rtrim($block->content(), "\n")."\n".$fence;
    }

    private function renderTable(Table $table): string
    {
        $header = $table->header();
        $rows = $table->rows();

        if ($header === null) {
            $header = array_shift($rows);
        }

        if ($header === null) {
            return '';
        }

        $lines = [$this->renderTableRow($header)];
        $separators = [];
        foreach ($header->cells() as $cell) {
            $separators[] = match ($cell->attributes()->get('alignment')) {
                'left' => ':---',
                'center' => ':---:',
                'right' => '---:',
                default => '---',
            };
        }
        $lines[] = '| '.implode(' | ', $separators).' |';

        foreach ($rows as $row) {
            $lines[] = $this->renderTableRow($row);
        }

        return implode("\n", $lines);
    }

    private function renderTableRow(TableRow $row): string
    {
        return '| '.implode(' | ', array_map(
            fn (TableCell $cell): string => $this->renderTableCell($cell),
            $row->cells(),
        )).' |';
    }

    private function renderTableCell(TableCell $cell): string
    {
        $parts = [];
        foreach ($cell->content() as $block) {
            if ($block instanceof Paragraph) {
                $parts[] = $this->renderInlines($block->inlines());
            }
        }

        return implode('<br>', $parts);
    }

    private function renderImage(Image $image): string
    {
        $title = $image->title() === null ? '' : ' "'.$this->escapeTitle($image->title()).'"';

        return '!['.$this->escapeText($image->alt()).']('
            .$this->escapeDestination($image->src()).$title.')';
    }

    /**
     * @param InlineInterface[] $inlines
     */
    private function renderInlines(array $inlines): string
    {
        $output = '';

        foreach ($inlines as $inline) {
            $output .= $this->renderInline($inline);
        }

        return $output;
    }

    private function renderInline(InlineInterface $inline): string
    {
        return match (true) {
            $inline instanceof Text => $this->escapeText($inline->content()),
            $inline instanceof Strong => '**'.$this->renderInlines($inline->children()).'**',
            $inline instanceof Emphasis => '*'.$this->renderInlines($inline->children()).'*',
            $inline instanceof Strike => '~~'.$this->renderInlines($inline->children()).'~~',
            $inline instanceof Underline => '<u>'.$this->renderInlines($inline->children()).'</u>',
            $inline instanceof Superscript => '<sup>'.$this->renderInlines($inline->children()).'</sup>',
            $inline instanceof Subscript => '<sub>'.$this->renderInlines($inline->children()).'</sub>',
            $inline instanceof Code => $this->renderCode($inline),
            $inline instanceof Link => $this->renderLink($inline),
            $inline instanceof InlineImage => $this->renderInlineImage($inline),
            $inline instanceof LineBreak => "  \n",
            default => '',
        };
    }

    private function renderCode(Code $code): string
    {
        preg_match_all('/`+/', $code->content(), $matches);
        $longest = 0;
        foreach ($matches[0] as $run) {
            $longest = max($longest, strlen($run));
        }

        $delimiter = str_repeat('`', max(1, $longest + 1));
        $padding = str_starts_with($code->content(), '`') || str_ends_with($code->content(), '`')
            ? ' '
            : '';

        return $delimiter.$padding.$code->content().$padding.$delimiter;
    }

    private function renderLink(Link $link): string
    {
        if (!$this->isSafeLinkHref($link->href())) {
            // Unsafe scheme: render link text without the anchor
            return $this->renderInlines($link->children());
        }

        $title = $link->title() === null ? '' : ' "'.$this->escapeTitle($link->title()).'"';

        return '['.$this->renderInlines($link->children()).']('
            .$this->escapeDestination($link->href()).$title.')';
    }

    private function renderInlineImage(InlineImage $image): string
    {
        if (!$this->isSafeImageSrc($image->src())) {
            // Unsafe scheme: render alt text
            return $this->escapeText($image->alt());
        }

        $title = $image->title() === null ? '' : ' "'.$this->escapeTitle($image->title()).'"';

        return '!['.$this->escapeText($image->alt()).']('
            .$this->escapeDestination($image->src()).$title.')';
    }

    private function isSafeLinkHref(string $href): bool
    {
        $scheme = $this->uriScheme($href);

        return $scheme === null
            || in_array($scheme, ['http', 'https', 'mailto'], true);
    }

    private function isSafeImageSrc(string $src): bool
    {
        $scheme = $this->uriScheme($src);

        if ($scheme === null) {
            return true;
        }

        return in_array($scheme, ['http', 'https'], true)
            || $scheme === 'data' && str_starts_with(strtolower($src), 'data:image/');
    }

    private function uriScheme(string $uri): ?string
    {
        $cleaned = preg_replace('/[\x00-\x20]/', '', $uri) ?? '';
        $colon = strpos($cleaned, ':');

        if ($colon === false || $colon === 0 || !preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*$/', substr($cleaned, 0, $colon))) {
            return null;
        }

        return strtolower(substr($cleaned, 0, $colon));
    }

    private function escapeText(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\',
            '`' => '\\`',
            '*' => '\\*',
            '_' => '\\_',
            '[' => '\\[',
            ']' => '\\]',
            '<' => '\\<',
            '>' => '\\>',
            '#' => '\\#',
            '|' => '\\|',
            '~' => '\\~',
        ]);
    }

    private function escapeDestination(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    private function escapeLegalLabel(string $value): string
    {
        return str_replace('.', '\\.', $this->escapeText($value));
    }

    private function escapeTitle(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', '"' => '\\"']);
    }

    private function escapeInfoString(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_+.-]/', '', $value) ?? '';
    }

    private function indentLines(string $value, int $spaces): string
    {
        $indent = str_repeat(' ', $spaces);

        return implode("\n", array_map(
            static fn (string $line): string => $line === '' ? '' : $indent.$line,
            explode("\n", $value),
        ));
    }

    /**
     * @param array<int, bool> $simpleNumIds
     */
    private function isSimpleNumbered(Paragraph $paragraph, array $simpleNumIds): bool
    {
        $numbering = $paragraph->numbering();

        return $numbering !== null && ($simpleNumIds[$numbering->numId()] ?? false);
    }
}
