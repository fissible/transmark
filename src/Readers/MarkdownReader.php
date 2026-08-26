<?php

declare(strict_types=1);

namespace Fissible\Transmark\Readers;

use Fissible\Transmark\Attributes;
use Fissible\Transmark\Contracts\BlockInterface;
use Fissible\Transmark\Contracts\InlineInterface;
use Fissible\Transmark\Contracts\ReaderInterface;
use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\BlockQuote;
use Fissible\Transmark\Nodes\Block\CodeBlock;
use Fissible\Transmark\Nodes\Block\Heading;
use Fissible\Transmark\Nodes\Block\HorizontalRule;
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
use Fissible\Transmark\Nodes\Inline\Text;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote as CommonMarkBlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading as CommonMarkHeading;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem as CommonMarkListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code as CommonMarkCode;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis as CommonMarkEmphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image as CommonMarkImage;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link as CommonMarkLink;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong as CommonMarkStrong;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\Strikethrough\Strikethrough;
use League\CommonMark\Extension\Table\Table as CommonMarkTable;
use League\CommonMark\Extension\Table\TableCell as CommonMarkTableCell;
use League\CommonMark\Extension\Table\TableRow as CommonMarkTableRow;
use League\CommonMark\Extension\Table\TableSection;
use League\CommonMark\Node\Block\Paragraph as CommonMarkParagraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text as CommonMarkText;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;

final class MarkdownReader implements ReaderInterface
{
    private readonly MarkdownParser $parser;

    public function __construct()
    {
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());

        $this->parser = new MarkdownParser($environment);
    }

    public function read(string $content): Document
    {
        $source = $this->parser->parse($content);
        $blocks = [];

        foreach ($source->children() as $child) {
            $block = $this->mapBlock($child);
            if ($block !== null) {
                $blocks[] = $block;
            }
        }

        return new Document($blocks);
    }

    private function mapBlock(Node $node): ?BlockInterface
    {
        return match (true) {
            $node instanceof CommonMarkHeading => new Heading(
                $node->getLevel(),
                $this->mapInlineChildren($node),
            ),
            $node instanceof CommonMarkParagraph => new Paragraph($this->mapInlineChildren($node)),
            $node instanceof ListBlock => $this->mapList($node),
            $node instanceof CommonMarkBlockQuote => new BlockQuote($this->mapBlockChildren($node)),
            $node instanceof FencedCode => new CodeBlock(
                $node->getLiteral(),
                $node->getInfoWords()[0] ?? null,
            ),
            $node instanceof IndentedCode => new CodeBlock($node->getLiteral()),
            $node instanceof ThematicBreak => new HorizontalRule(),
            $node instanceof CommonMarkTable => $this->mapTable($node),
            default => null,
        };
    }

    private function mapList(ListBlock $list): ListNode
    {
        $items = [];

        foreach ($list->children() as $child) {
            if ($child instanceof CommonMarkListItem) {
                $items[] = new ListItem($this->mapBlockChildren($child));
            }
        }

        $data = $list->getListData();

        return new ListNode(
            $data->type === ListBlock::TYPE_ORDERED
                ? ListNode::TYPE_ORDERED
                : ListNode::TYPE_UNORDERED,
            $items,
            $data->start ?? 1,
        );
    }

    private function mapTable(CommonMarkTable $table): Table
    {
        $header = null;
        $rows = [];

        foreach ($table->children() as $section) {
            if (!$section instanceof TableSection) {
                continue;
            }

            foreach ($section->children() as $row) {
                if (!$row instanceof CommonMarkTableRow) {
                    continue;
                }

                $mapped = $this->mapTableRow($row);
                if ($section->isHead() && $header === null) {
                    $header = $mapped;
                } else {
                    $rows[] = $mapped;
                }
            }
        }

        return new Table($rows, $header);
    }

    private function mapTableRow(CommonMarkTableRow $row): TableRow
    {
        $cells = [];

        foreach ($row->children() as $cell) {
            if (!$cell instanceof CommonMarkTableCell) {
                continue;
            }

            $attributes = $cell->getAlign() === null
                ? new Attributes()
                : new Attributes(data: ['alignment' => $cell->getAlign()]);
            $cells[] = new TableCell(
                [new Paragraph($this->mapInlineChildren($cell))],
                attributes: $attributes,
            );
        }

        return new TableRow($cells);
    }

    /**
     * @return BlockInterface[]
     */
    private function mapBlockChildren(Node $node): array
    {
        $blocks = [];

        foreach ($node->children() as $child) {
            $block = $this->mapBlock($child);
            if ($block !== null) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * @return InlineInterface[]
     */
    private function mapInlineChildren(Node $node): array
    {
        $inlines = [];

        foreach ($node->children() as $child) {
            $inline = $this->mapInline($child);
            if ($inline !== null) {
                $inlines[] = $inline;
            }
        }

        return $inlines;
    }

    private function mapInline(Node $node): ?InlineInterface
    {
        return match (true) {
            $node instanceof CommonMarkText => $this->mapText($node),
            $node instanceof CommonMarkStrong => new Strong($this->mapInlineChildren($node)),
            $node instanceof CommonMarkEmphasis => new Emphasis($this->mapInlineChildren($node)),
            $node instanceof Strikethrough => new Strike($this->mapInlineChildren($node)),
            $node instanceof CommonMarkLink => new Link(
                $node->getUrl(),
                $this->mapInlineChildren($node),
                $node->getTitle(),
            ),
            $node instanceof CommonMarkImage => new InlineImage(
                $node->getUrl(),
                $this->plainText($node),
                $node->getTitle(),
            ),
            $node instanceof CommonMarkCode => new Code($node->getLiteral()),
            $node instanceof Newline => $node->getType() === Newline::HARDBREAK
                ? new LineBreak()
                : new Text(' '),
            default => null,
        };
    }

    private function mapText(CommonMarkText $text): ?Text
    {
        return $text->getLiteral() === '' ? null : new Text($text->getLiteral());
    }

    private function plainText(Node $node): string
    {
        $text = '';

        foreach ($node->children() as $child) {
            if ($child instanceof CommonMarkText) {
                $text .= $child->getLiteral();
            } else {
                $text .= $this->plainText($child);
            }
        }

        return $text;
    }
}
