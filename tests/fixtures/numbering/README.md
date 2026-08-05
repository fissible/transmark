# Numbering fixtures

Ground-truth OOXML captured/crafted during requirements spikes, used by the
`NumberingEngine` and `DocxReader` test suites (see issues #1-#4, #6, #7).
These are real `word/numbering.xml` + `word/document.xml` pairs, not
hand-waved approximations — read them with `DOMDocument`, not a mock.

## `simple-nested-lists/`

Extracted from a real `.docx` produced by `pandoc` (installed locally,
**not** a runtime dependency of this library — used only to generate
ground-truth fixtures during development) converting a nested Markdown list:

```markdown
1. Term of Agreement
    1. Initial Term
    2. Renewal
        (a) Automatic renewal
        (b) Notice of non-renewal
            (i) Written notice
            (ii) Delivery method
2. Termination
```

**Finding:** pandoc's docx writer gives every nesting depth its own
independent `abstractNumId`/`numId` pair, each with **single-placeholder**
`lvlText` (e.g. `%1.`, `(%2)`). Depths do not share a `numId` and there is
no concatenation — `w:isLgl` never appears. Concretely:

| abstractNumId | format | lvlText (all levels) |
|---|---|---|
| 990 | bullet | ` ` (space, all 9 levels) |
| 99411 | decimal | `%1.`, `%2.`, ... `%9.` (independent per level) |
| 99731 | lowerLetter | `(%1)`, `(%2)`, ... `(%9)` |
| 99531 | lowerRoman | `(%1)`, `(%2)`, ... (per level) |

| numId | abstractNumId |
|---|---|
| 1000 | 990 |
| 1001 | 99411 |
| 1002 | 99411 |
| 1003 | 99731 |
| 1004 | 99531 |

| paragraph | ilvl | numId | rendered (per Word semantics) |
|---|---|---|---|
| Term of Agreement | 0 | 1001 | 1. |
| Initial Term | 1 | 1002 | 1. |
| Renewal | 1 | 1002 | 2. |
| Automatic renewal | 2 | 1003 | (a) |
| Notice of non-renewal | 2 | 1003 | (b) |
| Written notice | 3 | 1004 | (i) |
| Delivery method | 3 | 1004 | (ii) |
| Termination | 0 | 1001 | 2. |

Note "Initial Term"/"Renewal" restart at 1 independent of "Term of
Agreement"/"Termination" — each `numId` counts on its own, there is no
"1.1"/"1.2" relationship at the OOXML level even though visually nested.

## `legal-outline/`

**Hand-crafted** (pandoc cannot produce this pattern from Markdown input —
there is no Markdown syntax that maps to it). Models a genuine Word "legal
numbering" multilevel list: **one** `abstractNumId`/`numId` (`2000`) spans
all 4 depths, `lvlText` **concatenates** ancestor placeholders
(`%1.%2.%3.` etc.), and `w:isLgl` is set on levels 2-3 even though their
own `numFmt` is `lowerLetter`/`lowerRoman` — per ECMA-376 §17.9.4, `isLgl`
forces every placeholder referenced in that level's `lvlText` to render as
decimal, regardless of each referenced level's own format.

Validated end-to-end in `docs/spikes/legal-outline-resolve.php` (prototype
resolution loop built directly on `src/Numbering/*`, parsed with a real
`DOMDocument`) — expected output:

| paragraph | ilvl | label |
|---|---|---|
| Definitions | 0 | `1.` |
| Term of Agreement | 0 | `2.` |
| Initial Term | 1 | `2.1.` |
| Renewal | 1 | `2.2.` |
| Automatic renewal | 2 | `2.2.1.` |
| Written notice | 3 | `2.2.1.1.` |
| Termination | 0 | `3.` |

Note levels 2/3 render `1`/`1` (decimal), not `a`/`i` — that's `isLgl`
overriding their `lowerLetter`/`lowerRoman` `numFmt`. Without `isLgl` this
would render `2.2.a.` and `2.2.a.i.` instead.

## Why both fixtures matter

These are two structurally distinct OOXML patterns and a `DocxReader` /
`NumberingEngine` must handle both correctly:

- **Simple lists** — independent `numId` per depth, single-placeholder
  `lvlText`. Each level's label only needs its own counter.
- **True legal outlines** — one `numId` spans all depths, concatenated
  `lvlText`, often `isLgl`. A label needs every ancestor level's current
  counter, not just its own.

`DocxReader` does **not** need to detect which pattern it's looking at — it
always builds `Paragraph` + `NumberingRef` uniformly from `w:numPr`
(see issue #6). `NumberingEngine::resolve()` (issues #1-#4) handles both
patterns via the same counter/placeholder algorithm, since the simple
pattern is just a degenerate case (one placeholder, no `isLgl`) of the
general one. The pattern only matters to **writers**: `HtmlWriter` (and
`MarkdownWriter`) must classify each `numId` as simple-vs-legal to decide
whether to emit a native nested `<ol>` (simple) or a flat paragraph with
the fully-rendered label text (legal) — see issues #9/#10.
