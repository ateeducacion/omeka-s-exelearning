---
name: omeka-s-testing
description: "Use when writing or fixing tests for this Omeka S module: the test/Stubs harness, when to add a stub versus a double, how to test controllers and Module event handlers without an Omeka runtime, and what the coverage gate measures."
compatibility: "PHPUnit 9.6, PHP >= 7.4. Runs with no database, no web server and no Omeka installation."
---

# Testing This Omeka S Module

## When to use

Before adding a test, changing `test/phpunit.xml`, adding anything to
`test/Stubs/`, or diagnosing a coverage number that looks wrong.

## The core constraint

The suite runs with **no Omeka installation** — no database, no HTTP server, no
Omeka autoloader. Every Omeka and Laminas class the production code touches must
therefore either come from a real Composer package (`laminas-form`,
`laminas-servicemanager`, `laminas-validator`, `laminas-stdlib` and their
dependencies) or from a hand-written stub.

`test/bootstrap.php` registers a small autoloader that maps `Omeka\*`,
`Laminas\*` and `Doctrine\*` onto `test/Stubs/<path>.php`, plus one explicit
branch for `ExeLearning\Module`, which lives at the repository root and is
outside Composer's PSR-4 map.

## Stub or double?

| | Put it in | Why |
| --- | --- | --- |
| A framework class the production code **type-hints or extends** | `test/Stubs/` | It must exist under its real FQCN or PHP cannot load the file at all |
| A collaborator resolved **by service name** | `test/ExeLearningTest/Doubles/` | The container returns whatever you register; the real type is irrelevant |
| Behaviour specific to one test | an anonymous class in the test | Keeps the blast radius at one method |

`ElpFileService` is the clearest example of the middle row: `Module` asks the
container for `ElpFileService::class` as a *string key*, so `FakeElpFileService`
needs no inheritance. Reserve real stubs for cases where PHP itself demands the
type — `Module extends Omeka\Module\AbstractModule`, `handleConfigForm()`
type-hints `Laminas\Mvc\Controller\AbstractController`.

Keep stubs minimal and faithful. A stub that invents behaviour the real class
does not have produces green tests and a broken module.

## Testing protected methods

Subclass rather than reflect. `TestableModule` and `TestableStylesController`
promote protected helpers to public `callX()` wrappers and inject collaborators
the framework would otherwise supply. The subclass must not change behaviour —
every wrapper forwards to its parent, and any override (like
`TestableModule::getDataPath()`) exists only to redirect filesystem writes into a
temporary directory.

## Testing Module event handlers

Omeka handlers take a `Laminas\EventManager\Event` and communicate through
params, the target, and `echo`. So:

```php
$view = new PhpRenderer();          // the stub; carries view vars via __get/__set
$view->resource = $media;
$module = new TestableModule(new TestServiceLocator([
    'Omeka\Logger' => new Logger(),
    ElpFileService::class => new FakeElpFileService('hash', true, true, true),
]));

ob_start();
$module->handleAdminMediaShow(new Event('view.show.after', $view));
$output = (string) ob_get_clean();
```

Register **only** the services the branch under test should reach.
`TestServiceLocator::get()` throws on an unregistered name, so a handler that
takes an unintended path fails loudly instead of quietly asserting nothing.
Handlers render through `$view->partial()`, which the stub records in
`$view->partials` instead of resolving a real `.phtml`.

## The coverage gate

`make test-coverage` enforces `MIN_COVERAGE` (90) on **line** coverage and fails
on any failing test. The measured set is `src/` plus the root `Module.php`,
excluding `*Factory.php`. See ADR-0002 for why the gate is shaped this way.

Two traps:

- **Do not pipe PHPUnit into another command in the Make target.** `/bin/sh` has
  no portable `pipefail`, so the recipe would report the pipe's exit status and
  a failing suite would pass. This is exactly the bug ADR-0002 records.
- **`pcov.directory` must be pinned to the repository root.** Left to
  auto-detect, pcov silently omits the root-level `Module.php`, so a pcov
  machine and an xdebug machine (what CI uses) gate on different numbers. The
  Make target passes `-d pcov.directory=$(CURDIR)`.

If a line is genuinely unreachable in a unit test, exclude that *method* with
`@codeCoverageIgnore` and a comment saying why — never widen the exclusion to a
whole file, which is how `Module.php` went 985 lines without measurement.

## Verification

```bash
make test                                   # fast, no coverage
make test-coverage                          # the gate CI runs
vendor/bin/phpunit -c test/phpunit.xml --filter SomeTest --no-coverage
```

Run the whole suite before claiming a fix: several failures here only appear in
combination, because a `static` in production code outlives a single test.

## Failure modes

- **"Class ... not found" during report generation**: a class reachable from the
  coverage include set has no stub. `processUncoveredFiles="true"` loads every
  included file even when no test touches it.
- **A test passes alone and fails in the suite**: shared static state. Prefer
  keying such state on the object it belongs to (see
  `DownloadFormats::enqueueDownloadAssets`) over adding a test-only reset.
- **Coverage differs between your machine and CI**: different driver. Check
  `php -m | grep -iE 'xdebug|pcov'`.
