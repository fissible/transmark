# Contributing to Transmark

## Commit messages — Conventional Commits

All commits must follow [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/).

```
<type>: <short description>

[optional body]

[optional footer: BREAKING CHANGE: ...]
```

### Types

| Type | When to use | Version bump |
|---|---|---|
| `feat` | New feature or capability | minor |
| `fix` | Bug fix | patch |
| `refactor` | Code change that isn't a fix or feature | none |
| `test` | Adding or updating tests | none |
| `docs` | Documentation only | none |
| `chore` | Tooling, deps, release commits | none |
| `perf` | Performance improvement | none |
| `style` | Formatting, whitespace | none |
| `feat!` / `BREAKING CHANGE:` | Incompatible API change | major |

### Examples

```
feat: resolve multi-level legal outline numbering in NumberingEngine
fix: correct restart-after-ilvl handling for overridden levels
docs: add DocxReader usage example to README
chore: release v0.2.0
```

---

## Branching

- `main` is always releasable
- Work on feature branches: `feat/<name>`, `fix/<name>`, `chore/<name>`
- Open a PR to merge into `main`; don't push directly

---

## Test-driven development

Write the test before the implementation. No exceptions.

1. Write a failing test that describes the desired behaviour
2. Confirm it fails for the right reason
3. Write the minimal code to pass it
4. Confirm all tests pass
5. Refactor if needed — keep tests green

Tests live in `tests/`, mirroring the `src/` namespace. Run the full suite with:

```bash
composer install
composer test        # vendor/bin/phpunit
composer cs          # vendor/bin/php-cs-fixer fix --dry-run --diff
```

A PR without tests for new behaviour will not be merged.

## Design invariants

These are load-bearing decisions, not style preferences — see `PROJECT.md` for the
full rationale. Changes to them need a design discussion first, not just a PR:

- The canonical numbering model is flat: a numbered `Paragraph` carries a
  `NumberingRef{numId, ilvl}` pointer into `Document::numbering()`. It does not
  live inside a nested list container.
- Computed labels ("1.1.3", "(a)") live only in a `NumberingLabelMap` returned by
  `NumberingEngine::resolve()` — never stored on the tree.
- Legal outline paragraphs (`1.`, `7.1`, `(a)`) are `Paragraph` nodes, not `Heading`.
- Byte-for-byte round-tripping is not a goal. Semantic idempotence
  (`AST -> format -> AST` yields an equivalent tree) is the target, and should be
  covered by the round-trip test harness as readers/writers land.

---

## Releasing

See [fissible/.github's RELEASE.md](https://github.com/fissible/.github/blob/main/RELEASE.md)
for the full release process. Use `release.sh` at the root of this repo to cut a release.
