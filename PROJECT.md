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

**Added after the requirements/spike pass (2026-08-04):**

- **`DocxReader` is uniform, never classifies.** Real OOXML numbering comes
  in two structurally distinct shapes — independent `numId` per nesting
  depth with single-placeholder `lvlText` ("simple", what pandoc produces)
  vs. one `numId` spanning all depths with concatenated `lvlText` +/-
  `isLgl` ("legal") — see `tests/fixtures/numbering/README.md` for the
  validated ground truth. `DocxReader` always builds `Paragraph`+
  `NumberingRef` regardless of which shape it sees; it never tries to
  detect "this looks like a simple list" and rebuild a `ListNode` tree.
  Classification is a **writer** concern (see next point).
- **Writers classify simple-vs-legal per `numId`, not the reader.** A
  `numId` is "simple" if every level it uses has non-`isLgl`,
  single-placeholder `lvlText` (browsers/Markdown can auto-count it
  natively); otherwise it's "legal" and must be rendered as flat
  paragraphs with a literal, fully-computed label string, since neither
  HTML nor Markdown has native support for concatenated cross-level
  counters. `HtmlWriter` is split into two issues along exactly this line
  (#9 simple, #10 legal); `MarkdownWriter` (#11) reuses the same
  classification and should share the logic rather than duplicate it.
- **`league/commonmark` (^2.4) is a real runtime dependency**, adopted as
  `MarkdownReader`'s underlying parser rather than hand-rolling a
  CommonMark-subset parser. Validated via spike
  (`docs/spikes/commonmark-ast-mapping.php`): its parsed AST
  (`Document > Heading/ListBlock/Paragraph > ListItem > ... > Text/Strong/Emphasis`)
  maps cleanly onto this project's own `Block`/`Inline` taxonomy with zero
  numbering-model involvement, confirming Markdown lists are exactly the
  `ListNode`/`ListItem` case above. BSD-3-Clause, lightweight transitive
  deps (`league/config`, `psr/event-dispatcher`, symfony polyfill/
  deprecation shims), no system-binary requirement.
- **`phpoffice/phpword` is explicitly rejected** as a `DocxReader`
  foundation. Confirmed by reading its `Reader\Word2007\Numbering::readLevel()`
  source directly: it captures `start`/`numFmt`/`lvlRestart`/`suffix`/
  `lvlText`/etc. but **never reads `w:isLgl` at all** — it would silently
  drop the exact fidelity feature this project exists to preserve.
  `DocxReader` (#6, #7) is hand-rolled against `DOMDocument`, reusing only
  the zip-extraction technique (not the package) via a small
  format-agnostic `Ooxml\OoxmlPackage` (#5) shared with the future
  `transmark-xlsx` package.

## Status: DOCX to HTML and Markdown I/O core complete

The canonical model, full numbering engine, OOXML package layer,
`DocxReader`, `MarkdownReader`, `HtmlWriter`, and `MarkdownWriter` are
implemented. Ground-truth fixtures verify simple nested lists and genuine
legal outlines end to end, while Markdown support maps CommonMark/GFM ASTs
without introducing Word numbering references. The remaining core roadmap is
the semantic-idempotence harness (#12).

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
├── Numbering/        # definitions, formats, restart rules, engine + label map
├── Ooxml/            # shared zip + DOM package layer
├── Readers/          # DocxReader, MarkdownReader
└── Writers/          # HtmlWriter, MarkdownWriter

tests/fixtures/numbering/
├── README.md                  # provenance + expected labels for both fixtures
├── simple-nested-lists/       # pandoc-generated: independent numId per depth
└── legal-outline/             # hand-crafted: concatenated lvlText + isLgl

docs/spikes/
├── legal-outline-resolve.php  # validated prototype of the resolution algorithm
└── commonmark-ast-mapping.php # validated league/commonmark AST walk
```

## Dependency-ordered task list

Leaves first, most-widely-depended-on before its siblings. Each task should
get its own TDD implementation plan (`superpowers:writing-plans` +
`superpowers:subagent-driven-development` or `executing-plans`) when started
— this roadmap only sequences them. All 14 issues below were rewritten
(2026-08-04) into fully fleshed-out, pickup-ready tickets after a
requirements/spike pass — see the design decisions above and each issue's
own body for full context, acceptance criteria, and test suites.

The DOCX-to-HTML and Markdown reader/writer chains are complete. The
round-trip harness (#12) is the next unblocked core task. The adapter-package
stubs (#13/#14) can now be re-scoped against the implemented HTML and OOXML
conventions.

| # | Task | Effort | Depends on | Issue | Status |
|---|------|--------|------------|-------|--------|
| 1a | `NumberingEngine` core counter/restart resolution loop | M | Numbering data model (done) | [#1](https://github.com/fissible/transmark/issues/1) | Done |
| 1b | `lvlText` templating, `NumberFormat` rendering, `isLgl` | M | #1 | [#2](https://github.com/fissible/transmark/issues/2) | Done |
| 1c | Explicit `lvlRestart` (3-state) and per-`numId` start overrides | S | #1, #2 | [#3](https://github.com/fissible/transmark/issues/3) | Done |
| 1d | End-to-end engine test suite against committed ground-truth fixtures | S | #1–#3 | [#4](https://github.com/fissible/transmark/issues/4) | Done |
| 2a | Shared OOXML package layer (`Ooxml\OoxmlPackage`: zip + DOM, docx/xlsx-agnostic) | S | none | [#5](https://github.com/fissible/transmark/issues/5) | Done |
| 2b | `DocxReader`: `word/document.xml` → `Block`/`Inline` tree (uniform `Paragraph`+`NumberingRef`, no classification) | XL | #5 | [#6](https://github.com/fissible/transmark/issues/6) | Done |
| 2c | `DocxReader`: `word/numbering.xml` → `NumberingDefinitions` | L | #5 | [#7](https://github.com/fissible/transmark/issues/7) | Done |
| 3 | `MarkdownReader`: `league/commonmark` AST → tree (`ListNode`/`ListItem`, never `NumberingRef`) | L | Node taxonomy (done); `league/commonmark` dependency already added | [#8](https://github.com/fissible/transmark/issues/8) | Done |
| 4a | `HtmlWriter`: semantic `<ol>`/`<ul>` for `ListNode` trees + "simple" `numId` runs | M | none for `ListNode`; #6/#7 for the numbered-paragraph case | [#9](https://github.com/fissible/transmark/issues/9) | Done |
| 4b | `HtmlWriter`: flat + literal-label strategy for "legal" `numId` runs | M | #1–#4, #9 (classification logic) | [#10](https://github.com/fissible/transmark/issues/10) | Done |
| 5 | `MarkdownWriter`: tree → Markdown, reusing #9/#10's simple-vs-legal classification | M | #8 (node coverage); #1–#4 and #9/#10 (classification) for numbered-paragraph fallback | [#11](https://github.com/fissible/transmark/issues/11) | Done |
| 6 | Semantic-idempotence test harness: hand-write ASTs, round-trip through each reader/writer pair, assert tree equality (with explicit "expected lossy" markers, e.g. legal outlines through Markdown) | M | #8, #11 (minimum, Markdown ⇄ Markdown) | [#12](https://github.com/fissible/transmark/issues/12) | Done |
| 7 | `fissible/transmark-blade` (separate package, stub only — re-scope once #9/#10's `HtmlWriter` output conventions settle) | M (re-scope pending) | #9, #10 | [#13](https://github.com/fissible/transmark/issues/13) | Stub — needs re-scoping |
| 8 | `fissible/transmark-xlsx` (separate package, stub only — re-scope once `OoxmlPackage` is validated by real usage) | XL (re-scope pending) | #5 (and validation from #6/#7) | [#14](https://github.com/fissible/transmark/issues/14) | Stub — needs re-scoping |

## Session handoff notes

**Completed (2026-08-04):** Package skeleton — `composer.json` (PSR-4
`Fissible\Transmark\` → `src/`, PHP ^8.2), full directory layout, and every
node/numbering data class listed above.

**Completed (2026-08-05):** Repo created at
[github.com/fissible/transmark](https://github.com/fissible/transmark)
(initially **private**), PHPUnit + php-cs-fixer wired up with real passing
smoke tests, full public-release scaffolding (README, LICENSE, CONTRIBUTING,
CODE_OF_CONDUCT, PR/issue templates, PHP CI workflow), and the fissible
org-standard release pipeline (`release.sh`, `.cliff.toml`, `VERSION`,
reusable release workflow). Tagged and released **v0.1.0** — CI and the
release workflow both ran green. All 14 roadmap tasks below filed as GitHub
issues (#1–#14).

**Completed (2026-08-04, requirements/spike pass):** Ran real spike scripts
(not just discussion) to close every open design gap before finalizing the
roadmap:
- Hand-crafted a genuine legal-outline OOXML fixture (concatenated
  `lvlText` + `isLgl`) since pandoc can't produce this pattern from
  Markdown, and validated the existing `Level`/`AbstractNum` model
  resolves it correctly end-to-end via a working prototype engine
  (`docs/spikes/legal-outline-resolve.php`).
- Confirmed via spec research (ECMA-376 §17.9.4, §17.9.11) the precise
  semantics of `w:isLgl` and the 3-state `w:lvlRestart` (absent = restart
  on immediate parent; `val="N"` = restart on a specific ancestor;
  `val="0"` = never restart) — the latter is a real gap in the current
  `Level` model, flagged explicitly in issue #3.
- Ran a build-vs-buy spike for `MarkdownReader`: adopted
  `league/commonmark` (already added to `composer.json`) after confirming
  its AST maps cleanly onto this project's own node taxonomy
  (`docs/spikes/commonmark-ast-mapping.php`).
- Ran a build-vs-buy spike for `DocxReader`: rejected `phpoffice/phpword`
  after reading its numbering reader source directly and confirming it
  silently drops `w:isLgl` — the exact fidelity feature this project
  exists for.
- Committed both fixture sets + provenance docs at
  `tests/fixtures/numbering/` so every relevant issue can reference real
  ground truth instead of asking each contributor to regenerate it.
- Rewrote all 14 GitHub issues into fully fleshed-out, pickup-ready
  tickets with concrete acceptance criteria, test suites, and explicit
  cross-issue dependency notes (including corrections to the original
  dependency graph — e.g. #6 and #7 turned out to be independent of each
  other and of #1–#4, and #9's `ListNode` half needs no numbering
  dependency at all). #13 and #14 were deliberately left as re-scope-later
  stubs since their target API surface depends on decisions #9/#10 and
  #5–#7 haven't made yet.

**Completed (2026-08-05, via PR #15, merged to `main`):** #1 (`NumberingEngine`
core counter/restart resolution loop — all 6 acceptance criteria verified
by `tests/Numbering/NumberingEngineTest.php`, naive decimal-join label
rendering, #2's scope) and #5 (`Ooxml\OoxmlPackage` — `open()`/`part()`/
`rawPart()`/`close()`, built via subagent-driven development with a final
whole-branch review fix wave, then manually verified against a real
Word-generated `.docx` in addition to the fixture-based test suite).
Full suite: 24/24 passing on `main`.

**Completed (2026-08-05, issues #2–#7, #9–#10):** Implemented and fixture-
validated the full numbering semantics, DOCX package/reader pipeline, and
HTML writer's native-simple-list and literal-legal-outline paths. The core
DOCX-to-HTML value proposition is ready for the v0.2.0 developer preview.

**Next task:** Re-scope the downstream adapter-package stubs (#13/#14) or
add the planned native `DocxWriter` roadmap item.

**Release decision:** publish the repository and cut v0.2.0 as a pre-alpha
developer preview. Packagist publication is a separate final step.

**Blockers:** none.
