---
id: ADR-32-01
title: "Verification contract: what a green CI run means"
status: Accepted
date: 2026-08-04
tracking_issue: 32
legacy_id: ADR-0002
related:
  issues: []
  prs:
    - https://github.com/exelearning/omeka-s-exelearning/pull/32
  changes: []
  adrs:
    - ADR-28-01
supersedes: []
superseded_by: []
ai_assistance:
  tool: "Claude Code"
  model: "claude-opus-5"
---

# ADR-32-01: Verification contract: what a green CI run means

## Context

`make test-coverage` is the repository's only automated verification gate for
behaviour: CI runs it after `make lint`, and a green run is what reviewers and
the README badge treat as evidence that the module works. Three properties of
that gate were not what they appeared to be.

**The gate did not fail on failing tests.** The recipe piped PHPUnit into `tee`
(`Makefile` @ `708da60`):

```make
@XDEBUG_MODE=coverage "vendor/bin/phpunit" ... --coverage-text 2>&1 | tee /tmp/coverage-output.txt; \
COVERAGE=$$(... parse the file ...); \
```

A recipe's exit status is the status of the last command in a pipeline, so Make
observed `tee`'s success and only the parsed coverage percentage could fail the
build. `/bin/sh`, which Make uses, has no portable `pipefail`. The consequence
was live: the CI run for `f2d51e5` logged
`ERRORS! Tests: 462, Assertions: 884, Errors: 1, Failures: 1` and the job
concluded **success**. Two tests had been failing on `main` undetected —
`StylesServiceTest::testBuildOverridePublishesIconsFromIconsFolder`, whose
fixture was invalidated when the security-hardening commit added an extension
allowlist to `StylesService::isAllowedFilename()`, and
`DownloadFormatsTest::testEnqueueDownloadAssetsEnqueuesScriptAndI18n`, which
depended on execution order because the guard it exercised was a function-level
`static` no test could reset.

**The gate measured three quarters of the codebase.** `test/phpunit.xml`
@ `708da60` included only `../src`. `Module.php` — 985 lines at the repository
root holding every Omeka event hook, the lifecycle methods, the config form and
the URL helpers — was linted but never measured. Adding it to the include set
fataled with `Class "Omeka\Module\AbstractModule" not found`, because the test
harness had no stub for the class `Module` extends. The reported 92.11% was
therefore computed over 1217 of roughly 1590 executable lines; measuring the
whole set put the true figure at **71.24%**.

**Coverage was published nowhere.** `README.md` @ `708da60` carried a Codecov
badge, but no workflow ever uploaded a report, no `codecov.yml` existed, and the
Make target emitted only `--coverage-text`. The badge had always read *unknown*.

A related trap surfaced while fixing the include set: with pcov as the coverage
driver, `pcov.directory` auto-detects to a subdirectory and silently omits the
root-level `Module.php`, so a developer running pcov and CI running xdebug would
gate on different numbers from identical code.

## Problem

What must be true for a CI run to be green, where is that decided, and what role
does Codecov play?

## Decision drivers

- A green run must mean "no test failed", first and foremost.
- The gate must behave identically on a laptop and in CI.
- Coverage should measure the module, not a convenient subset of it.
- Exactly one blocking verdict, so two gates cannot disagree.
- No secret to rotate if it can be avoided.

## Alternatives considered

### Option 1: Add `set -o pipefail` to the recipe

- Pro: minimal diff.
- Con: not portable in `/bin/sh`; would need a `SHELL := /bin/bash` override in
  the Makefile, adding a dependency for one line. Rejected.

### Option 2: Check `${PIPESTATUS[0]}`

- Con: bash-only, same dependency, and easy to drop when the recipe is next
  edited. Rejected.

### Option 3: Remove the pipe (chosen)

- Pro: PHPUnit writes `coverage.txt`, `clover.xml` and the HTML report itself,
  the recipe parses the file afterwards, and the exit status propagates with no
  shell feature involved. The clover report is what Codecov needs anyway.

### Option 4: Keep `Module.php` outside the coverage set and document it

- Pro: no new stubs, threshold stays comfortable.
- Con: preserves a 90% badge over a codebase whose largest and most
  security-relevant file is unmeasured. Rejected.

### Option 5: Make Codecov's status blocking as well

- Con: two gates that can disagree; Codecov's project status also fails on
  unrelated base-commit resolution problems. Rejected in favour of one blocking
  gate in the Makefile.

## Decision

The verification contract is:

- **`make test-coverage` is the single blocking gate.** It fails when any test
  fails and when line coverage falls below `MIN_COVERAGE` (90). It contains no
  pipeline, so PHPUnit's exit status is the recipe's exit status.
- **The measured set is `src/` plus the root `Module.php`**, excluding
  `*Factory.php` (factories are wiring only). `Module.php` is loadable under
  PHPUnit through stubs for `Omeka\Module\AbstractModule`,
  `Omeka\Mvc\Controller\Plugin\Messenger`, `Omeka\Stdlib\Message`,
  `Laminas\EventManager\Event`, `Laminas\EventManager\SharedEventManagerInterface`
  and `Laminas\Mvc\Controller\AbstractController`, plus an explicit branch in
  `test/bootstrap.php` for the root-level class Composer's PSR-4 map cannot
  reach.
- **`pcov.directory` is pinned to the repository root** by the Make target, so
  pcov and xdebug measure the same files.
- **`MIN_COVERAGE` is a ratchet.** Raise it as coverage improves; never lower it
  to make a build pass. Narrowing the measured set to lift the percentage is the
  same move and equally forbidden.
- **Unreachable code is excluded per method**, with `@codeCoverageIgnore` and a
  comment giving the reason. File-level exclusion is what hid `Module.php`.
- **Codecov is informational.** CI uploads `clover.xml` under the `php` flag,
  authenticating with GitHub OIDC (`id-token: write`) rather than a
  `CODECOV_TOKEN` secret. Its statuses are `informational: true`: it annotates
  diffs and renders the badge, but never issues a second verdict.

## Consequences

### Positive

- A failing test now fails the build. The two dormant failures are fixed.
- 370 previously unmeasured lines of `Module.php` are covered at 99.73%, and the
  honest global figure is 94.46% — above the threshold, on the full set.
- The README badge reflects a real, published measurement.
- One gate, one number, identical locally and in CI.

### Negative

- The suite now depends on six more stubs, which must track the Omeka and
  Laminas signatures they stand in for. `omeka-s-testing` documents the rule
  that keeps them honest: minimal and faithful, never inventive.
- Coverage runs are slightly slower now that a 985-line file is instrumented.

### Neutral

- `make test` no longer computes a coverage report it never printed; report
  generation moved from `test/phpunit.xml` to the `test-coverage` command line.
- Enabling the repository on codecov.io is a one-time manual step; until it is
  done the upload step is a no-op and the badge stays *unknown*.

## Risks

- OIDC uploads need the repository enabled on Codecov and `id-token: write`
  granted. If uploads fail the badge remains stale, but the blocking gate is
  unaffected — which is the point of keeping Codecov informational.
- `renderEditorStatusSection()`'s bundle-present branch stays uncovered: proving
  it needs a real `dist/static/` in the checkout, and fabricating one risks
  deleting a developer's build during teardown. It is covered by the `make
  package` guards instead (ADR-28-01).

## Validation

- `make test-coverage` on `708da60` plus this change: 540 tests pass, line
  coverage 94.46%, `Module.php` at 369/370 lines.
- Deliberately failing a test makes the target exit non-zero — the property that
  regressed before.
- `artifacts/coverage/clover.xml` lists 15 files including `Module.php`.

## Follow-up work

- Enable the repository on codecov.io so uploads are accepted.
- Raise `MIN_COVERAGE` toward the achieved figure once it is stable.

## References

- `Makefile`, `test/phpunit.xml`, `.github/workflows/ci.yml`, `codecov.yml`.
- `.agents/skills/omeka-s-testing/SKILL.md` — the harness rules this ADR relies
  on.
- ADR-28-01 — why the editor bundle is absent in development checkouts.
- `make architecture-records` — prints the ADR index.
