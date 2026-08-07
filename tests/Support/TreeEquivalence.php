<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Support;

use Fissible\Transmark\Attributes;
use Fissible\Transmark\Contracts\BlockInterface;
use Fissible\Transmark\Contracts\InlineInterface;
use Fissible\Transmark\Contracts\NodeInterface;
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
use Fissible\Transmark\Nodes\Inline\Comment;
use Fissible\Transmark\Nodes\Inline\Emphasis;
use Fissible\Transmark\Nodes\Inline\Footnote;
use Fissible\Transmark\Nodes\Inline\InlineImage;
use Fissible\Transmark\Nodes\Inline\LineBreak;
use Fissible\Transmark\Nodes\Inline\Link;
use Fissible\Transmark\Nodes\Inline\Strike;
use Fissible\Transmark\Nodes\Inline\Strong;
use Fissible\Transmark\Nodes\Inline\Subscript;
use Fissible\Transmark\Nodes\Inline\Superscript;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Nodes\Inline\Underline;
use Fissible\Transmark\Numbering\NumberingDefinitions;
use Fissible\Transmark\Numbering\NumberingRef;
use PHPUnit\Framework\Assert;

final class TreeEquivalence
{
    public static function assertEquivalent(Document $expected, Document $actual): void
    {
        Assert::assertSame(
            self::document($expected),
            self::document($actual),
            'The document trees are not semantically equivalent.',
        );
    }

    /**
     * @param callable(Document): void $assertExpectedResult
     */
    public static function assertExpectedLoss(
        Document $expected,
        Document $actual,
        string $reason,
        callable $assertExpectedResult,
    ): void {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Expected-loss assertions require a documented reason.');
        }

        Assert::assertNotSame(
            self::document($expected),
            self::document($actual),
            'Expected a documented lossy conversion: '.$reason,
        );

        $assertExpectedResult($actual);
    }

    /**
     * @return array<string, mixed>
     */
    private static function document(Document $document): array
    {
        return [
            'content' => array_map(self::node(...), $document->content()),
            'numbering' => self::numbering($document->numbering()),
            'metadata' => $document->metadata(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function node(NodeInterface $node): array
    {
        $value = match (true) {
            $node instanceof Paragraph => [
                'inlines' => self::inlines($node->inlines()),
                'styleName' => $node->styleName(),
                'numbering' => self::numberingRef($node->numbering()),
            ],
            $node instanceof Heading => [
                'level' => $node->level(),
                'inlines' => self::inlines($node->inlines()),
            ],
            $node instanceof ListNode => [
                'type' => $node->type(),
                'start' => $node->start(),
                'items' => array_map(self::node(...), $node->items()),
            ],
            $node instanceof ListItem => ['content' => self::blocks($node->content())],
            $node instanceof BlockQuote => ['content' => self::blocks($node->content())],
            $node instanceof CodeBlock => [
                'content' => $node->content(),
                'language' => $node->language(),
            ],
            $node instanceof HorizontalRule => [],
            $node instanceof Table => [
                'header' => $node->header() === null ? null : self::node($node->header()),
                'rows' => array_map(self::node(...), $node->rows()),
            ],
            $node instanceof TableRow => ['cells' => array_map(self::node(...), $node->cells())],
            $node instanceof TableCell => [
                'content' => self::blocks($node->content()),
                'colspan' => $node->colspan(),
                'rowspan' => $node->rowspan(),
            ],
            $node instanceof Image => [
                'src' => $node->src(),
                'alt' => $node->alt(),
                'title' => $node->title(),
            ],
            $node instanceof Text => ['content' => $node->content()],
            $node instanceof Strong,
            $node instanceof Emphasis,
            $node instanceof Underline,
            $node instanceof Strike,
            $node instanceof Superscript,
            $node instanceof Subscript => ['children' => self::inlines($node->children())],
            $node instanceof Code => ['content' => $node->content()],
            $node instanceof Link => [
                'href' => $node->href(),
                'title' => $node->title(),
                'children' => self::inlines($node->children()),
            ],
            $node instanceof InlineImage => [
                'src' => $node->src(),
                'alt' => $node->alt(),
                'title' => $node->title(),
            ],
            $node instanceof LineBreak => [],
            $node instanceof Footnote => [
                'identifier' => $node->identifier(),
                'content' => self::blocks($node->content()),
            ],
            $node instanceof Comment => [
                'author' => $node->author(),
                'content' => self::blocks($node->content()),
            ],
            default => throw new \LogicException('Unsupported node in equivalence check: '.$node::class),
        };

        return [
            'type' => $node::class,
            'attributes' => self::attributes($node->attributes()),
            ...$value,
        ];
    }

    /**
     * @param BlockInterface[] $blocks
     * @return array<int, array<string, mixed>>
     */
    private static function blocks(array $blocks): array
    {
        return array_map(self::node(...), $blocks);
    }

    /**
     * @param InlineInterface[] $inlines
     * @return array<int, array<string, mixed>>
     */
    private static function inlines(array $inlines): array
    {
        return array_map(self::node(...), $inlines);
    }

    /**
     * @return array<string, mixed>
     */
    private static function attributes(Attributes $attributes): array
    {
        return [
            'id' => $attributes->id(),
            'classes' => $attributes->classes(),
            'data' => $attributes->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function numbering(NumberingDefinitions $definitions): array
    {
        $abstractNums = $definitions->abstractNums();
        $nums = $definitions->nums();
        ksort($abstractNums);
        ksort($nums);

        $abstractValues = [];
        foreach ($abstractNums as $id => $abstractNum) {
            $levels = $abstractNum->levels();
            ksort($levels);
            $levelValues = [];

            foreach ($levels as $ilvl => $level) {
                $levelValues[$ilvl] = [
                    'ilvl' => $level->ilvl(),
                    'format' => $level->format()->value,
                    'lvlText' => $level->lvlText(),
                    'start' => $level->start(),
                    'isLegal' => $level->isLegal(),
                    'restartRule' => $level->restartRule()->name,
                    'restartAfterIlvl' => $level->restartAfterIlvl(),
                ];
            }

            $abstractValues[$id] = [
                'id' => $abstractNum->id(),
                'multiLevelType' => $abstractNum->multiLevelType(),
                'levels' => $levelValues,
            ];
        }

        $numValues = [];
        foreach ($nums as $id => $num) {
            $overrides = $num->levelOverrides();
            ksort($overrides);
            $numValues[$id] = [
                'numId' => $num->numId(),
                'abstractNumId' => $num->abstractNumId(),
                'levelOverrides' => $overrides,
            ];
        }

        return ['abstractNums' => $abstractValues, 'nums' => $numValues];
    }

    /**
     * @return array{numId: int, ilvl: int}|null
     */
    private static function numberingRef(?NumberingRef $numbering): ?array
    {
        return $numbering === null
            ? null
            : ['numId' => $numbering->numId(), 'ilvl' => $numbering->ilvl()];
    }
}
