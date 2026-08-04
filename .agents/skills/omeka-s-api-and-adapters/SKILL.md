---
name: omeka-s-api-and-adapters
description: "Use when working with Omeka S's API layer from this module: the api.* and rep.resource.json events, entity-versus-representation payloads, media data storage, and the module's own /api/exelearning endpoints."
compatibility: "Omeka S >= 3.0. Event identifiers and payload keys follow Omeka S 3.x."
---

# Omeka S API and Adapters

## When to use

Before touching an `api.*` listener in `Module.php`, changing what the module
stores on a media, or adding an endpoint under `/api/exelearning`.

## Two different objects called "media"

This is the single most common source of bugs in Omeka event code.

| Event | Payload | Type | Accessors |
| --- | --- | --- | --- |
| `api.hydrate.post` | `entity` | Doctrine **entity** | `getFilename()`, `getData()`, `setRenderer()` |
| `api.create.post` | `response` | wraps an **entity** | `getContent()->getId()` |
| `api.delete.pre` | `entity` | Doctrine **entity** | `getId()`, `getFilename()`, `getData()` |
| `rep.resource.json` | event *target* | **representation** | `filename()`, `id()`, `mediaData()` |
| `view.show.after` | view target | **representation** via `$view->resource` / `$view->item` | idem |

Entities use `getX()`; representations use `x()`. `handleMediaCreate()` bridges
the two deliberately — it takes the id off the entity, then re-reads a
representation through `Omeka\ApiManager` so downstream services get a
consistent interface. That read can fail (the entity may not be flushed as you
expect), so it is wrapped in a `try`/`catch` that logs and returns.

An entity in a hydrate listener may not have a stored filename yet, only a
source. `handleMediaHydrate()` probes with `method_exists()` and falls back to
`getSource()` for that reason. Keep the probes: the shape genuinely varies.

## Listener registration

All identifiers are attached in `Module::attachListeners()`:

- `Omeka\Api\Adapter\MediaAdapter` — `api.hydrate.post`, `api.create.post`,
  `api.delete.pre`
- `Omeka\Api\Representation\MediaRepresentation` — `rep.resource.json`
- `Omeka\Controller\Admin\Media`, `Omeka\Controller\Site\Item` —
  `view.show.after`
- `*` — `view.layout`

Adding a listener means adding a case to `ModuleTest::testAttachListenersRegistersEveryOmekaHook`,
which asserts the exact registration list in order.

Pick the event by what you need: `api.hydrate.post` to influence what gets
persisted (it runs before the flush), `api.create.post` for work that needs a
persisted id, `api.delete.pre` for cleanup that needs the row to still exist.
Cleaning up in `api.delete.post` is too late — the data you need to find the
files is gone.

## Media data

Per-media state lives in the entity's `data` array, read back through
`$media->mediaData()`:

| Key | Meaning |
| --- | --- |
| `exelearning_extracted_hash` | SHA1 naming the extraction directory |
| `exelearning_has_preview` | `'1'` when the package contains `index.html` |
| `exelearning_has_screenshot` | `'1'` when `screenshot.png` was extracted |
| `exelearning_teacher_mode_visible` | `'1'`/`'0'`, set from the admin edit form |

Values are stored as **strings**, and booleans arrive from HTML forms in many
spellings. Compare against an explicit falsy list
(`['0', 'false', 'no', 'off', '']`) rather than casting — `(bool) 'false'` is
`true`. A checkbox posts a hidden `0` followed by the checked `1`, so an array
value means "take the last one".

## Extending JSON-LD

`rep.resource.json` merges module keys into the representation's JSON-LD under a
vendor prefix:

```php
$jsonLd = $event->getParam('jsonLd', []);
$jsonLd['o-module-exelearning:screenshot'] = '/exelearning/content/' . $hash . '/screenshot.png';
$event->setParam('jsonLd', $jsonLd);
```

Read the existing array, add to it, set it back. Replacing it wholesale drops
everything Omeka and other modules already put there. Guard early: return before
touching any service when the media is not an eXeLearning file, so the handler
stays cheap on every other media in the installation.

## The module's own endpoints

`/api/exelearning/*` is a plain Laminas route tree served by `ApiController` —
it is **not** part of Omeka's REST API and gets none of its authentication.
Every state-changing action must therefore do both checks itself, in order:

```php
if (!$this->validateCsrf($request)) {            // CsrfValidationTrait
    return new ApiProblemResponse(new ApiProblem(403, 'Invalid CSRF token'));
}
$acl = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\Acl');
if (!$acl->userIsAllowed('Omeka\Entity\Media', 'update')) {
    return new ApiProblemResponse(new ApiProblem(403, 'Permission denied'));
}
```

The token is accepted from the `csrf` POST field, the `X-CSRF-Token` header, or
the `csrf` query parameter; an absent token is a rejection. CSRF proves origin,
the ACL proves authorisation — neither substitutes for the other.

## Verification

```bash
make test-coverage
```

New handler branches need tests; `Module.php` is inside the coverage gate
(ADR-0002). See `omeka-s-testing` for the event/double harness.

## Failure modes

- **`Call to undefined method ...::filename()`**: an entity is being treated as
  a representation. Check which event you are in.
- **Extraction directories accumulate**: cleanup keyed on `hasPreview` instead
  of the processed marker, so preview-less packages get re-extracted on every
  view. Gate on `isProcessed()`.
- **Other modules' JSON-LD disappears**: `setParam('jsonLd', ...)` called with a
  fresh array instead of the merged one.
- **An endpoint works in the browser but 403s from a script**: the CSRF token is
  being sent under the old `csrf_key` name, or not at all.
