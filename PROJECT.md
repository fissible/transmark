# Transmark — Project Roadmap

A pure-PHP document conversion library: a canonical typed document model with
faithful multi-level numbering resolution, and pluggable readers/writers for
DOCX, Markdown, and HTML — no system binaries required.

This file is the source of truth for sequencing. It is stateless: readable in
a fresh session with no prior context. Update it when tasks complete or scope
changes.

## Design decisions locked in (see docs/architecture notes / conversation history)

- Canonical model is Word's flat model, not HTML's: a numbered `Paragraph`
  carries a `NumberingRef{numId, ilvl}` pointer; it does not live inside a
  nested list container.
- Legal outlines (`1.`, `7.1`, `(a)`) are numbered `Paragraph` nodes, never
  `Heading` — `Heading` is reserved for true semantic H1–H6 titles.
- `NumberingDefinitions` (abstractNums + nums) lives on `Document`, mirroring
  `word/numbering.xml`. Paragraphs only ever hold the pointer.
- Rendered labels ("1.1.3", "(a)") are computed by a single shared
  `NumberingEngine`, in one pass, and are never stored on the tree — this is
  what keeps a read → write round-trip convergent.
- `ListNode`/`ListItem` exist alongside `Paragraph`+`NumberingRef` for simple
  structural lists (e.g. Markdown bullets) that don't need Word's numbering
  fidelity — the two mechanisms serve different fidelity needs, not one
  replacing the other.
- Every node carries an `Attributes` bag (id, classes, arbitrary data) as a
  lossless escape hatch for format-specific data with no first-class node.
- Byte-for-byte round-tripping is explicitly not a goal (DOCX and Markdown
  are not canonical formats). The target is semantic idempotence: AST →
  format → AST returns an equivalent tree.
- Laravel Blade adapter and an xlsx converter are planned as separate
  packages sharing this package's OOXML/zip and document-model layers —
  out of scope here.

## Status: skeleton complete

`composer.json`, PSR-4 namespace layout (`Fissible\Transmark\`), and the full
node taxonomy + numbering data model exist as data-only classes (no reader,
writer, or numbering-resolution logic yet). Verified: `php -l` on every file,
`composer dump-autoload`, and a smoke script instantiating `Document` +
`Paragraph` + `NumberingRef` + `NumberingDefinitions` together.

```
src/
├── Attributes.php                   # lossless escape hatch on every node
├── Document.php                     # root: content[] + NumberingDefinitions + metadata
├── Contracts/
│   ├── NodeInterface.php
│   ├── BlockInterface.php
│   ├── InlineInterface.php
│   ├── ReaderInterface.php          # read(string $content): Document
│   ├── WriterInterface.php          # write(Document $document): string
│   └── NumberingEngineInterface.php # resolve(Document $document): NumberingLabelMap
├── Nodes/
│   ├── Node.php / AbstractBlock.php / AbstractInline.php
│   ├── Block/       Paragraph, Heading, ListNode, ListItem, Table,
│   │                TableRow, TableCell, BlockQuote, CodeBlock,
│   │                HorizontalRule, Image
│   └── Inline/      Text, Emphasis, Strong, Underline, Strike,
│                     Superscript, Subscript, Link, InlineImage,
│                     LineBreak, Code, Footnote, Comment
├── Numbering/
│   ├── NumberFormat.php (enum), Level, AbstractNum, Num,
│   │   NumberingDefinitions, NumberingRef, NumberingLabelMap
│   └── (NumberingEngine implementation — not yet built, see Task 1)
├── Readers/          # empty — DocxReader, MarkdownReader land here
└── Writers/          # empty — HtmlWriter, MarkdownWriter land here
```

## Dependency-ordered task list

Leaves first, most-widely-depended-on before its siblings. Each task should
get its own TDD implementation plan (`superpowers:writing-plans` +
`superpowers:subagent-driven-development` or `executing-plans`) when started
— this roadmap only sequences them.

| # | Task | Effort | Depends on | Issue | Status |
|---|------|--------|------------|-------|--------|
| 1a | `NumberingEngine` core resolution loop (counter state per numId) | M | Numbering data model (done) | [#1](https://github.com/fissible/transmark/issues/1) | Not started |
| 1b | `lvlText` templating and `NumberFormat` rendering | M | 1a | [#2](https://github.com/fissible/transmark/issues/2) | Not started |
| 1c | Level restart and override rules | S | 1a, 1b | [#3](https://github.com/fissible/transmark/issues/3) | Not started |
| 1d | NumberingEngine test suite: legal outlines, restarts, overrides | S | 1a–1c | [#4](https://github.com/fissible/transmark/issues/4) | Not started |
| 2a | Shared OOXML/zip extraction layer (docx + future xlsx) | S | none | [#5](https://github.com/fissible/transmark/issues/5) | Not started |
| 2b | `DocxReader`: `word/document.xml` → `Block`/`Inline` tree | XL | 2a | [#6](https://github.com/fissible/transmark/issues/6) | Not started |
| 2c | `DocxReader`: `word/numbering.xml` → `NumberingDefinitions` | L | 2a | [#7](https://github.com/fissible/transmark/issues/7) | Not started |
| 3 | `MarkdownReader`: CommonMark-subset parser → tree (`ListNode`/`ListItem`, not `NumberingRef`) | L | Node taxonomy (done) | [#8](https://github.com/fissible/transmark/issues/8) | Not started |
| 4a | `HtmlWriter`: semantic `<ol>` nesting for simple lists | M | none | [#9](https://github.com/fissible/transmark/issues/9) | Not started |
| 4b | `HtmlWriter`: flat + rendered-label strategy for legal outlines | M | 1a–1c | [#10](https://github.com/fissible/transmark/issues/10) | Not started |
| 5 | `MarkdownWriter`: tree → Markdown | M | Task 3 (node coverage) | [#11](https://github.com/fissible/transmark/issues/11) | Not started |
| 6 | Semantic-idempotence test harness: generate/hand-write ASTs, round-trip through each reader/writer pair, assert tree equality | M | Tasks 3, 5 (minimum) | [#12](https://github.com/fissible/transmark/issues/12) | Not started |
| 7 | `fissible/transmark-blade` (separate package): Blade adapter consuming this package's `Document`/writers | M | Tasks 4a–4b | [#13](https://github.com/fissible/transmark/issues/13) | Not started |
| 8 | `fissible/transmark-xlsx` (separate package): xlsx reader/writer sharing the OOXML/zip layer | XL | Task 2a | [#14](https://github.com/fissible/transmark/issues/14) | Not started |

## Session handoff notes

**Completed (2026-08-04):** Package skeleton — `composer.json` (PSR-4
`Fissible\Transmark\` → `src/`, PHP ^8.2), full directory layout, and every
node/numbering data class listed above.

**Completed (2026-08-05):** Repo created at
[github.com/fissible/transmark](https://github.com/fissible/transmark)
(currently **private**), PHPUnit + php-cs-fixer wired up with real passing
smoke tests, full public-release scaffolding (README, LICENSE, CONTRIBUTING,
CODE_OF_CONDUCT, PR/issue templates, PHP CI workflow), and the fissible
org-standard release pipeline (`release.sh`, `.cliff.toml`, `VERSION`,
reusable release workflow). Tagged and released **v0.1.0** — CI and the
release workflow both ran green. All 14 roadmap tasks below filed as GitHub
issues (#1–#14).

**Next task:** #1 (`NumberingEngine` core resolution loop). This is the
actual differentiator and the hard problem (per earlier design discussion,
mammoth.js issue #74 is the canonical example of getting this wrong) — give
it its own `superpowers:writing-plans` pass before touching code, since the
counter/reset/restart semantics need to be nailed down precisely before
writing tests.

**Decisions made this session:** repo stays **private** until enough issues
exist to give the public release some depth (explicit user request) — don't
flip visibility to public without asking first.

**Blockers:** none.
