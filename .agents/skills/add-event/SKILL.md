---
name: add-event
description: Attach a new Omeka S event listener in Module.php. Invoke with a description, e.g. /add-event log media updates
---

Attach a new event listener for: $ARGUMENTS

## Steps

### 1. Register in `Module::attachListeners()`

```php
public function attachListeners(SharedEventManagerInterface $sharedEventManager)
{
    // ... existing listeners ...

    $sharedEventManager->attach(
        'Omeka\Api\Adapter\MediaAdapter',  // identifier (see table below)
        'api.update.post',                  // event name
        [$this, 'handleMyEvent']
    );
}
```

### 2. Add the handler method on `Module`

```php
public function handleMyEvent(Event $event): void
{
    $services = $this->getServiceLocator();
    $logger = $services->get('Omeka\Logger');

    /** @var \Omeka\Api\Request $request */
    $request = $event->getParam('request');
    /** @var \Omeka\Entity\Media $entity */
    $entity = $event->getParam('entity');   // available on api.*.post events
    /** @var \Omeka\Api\Response $response */
    $response = $event->getParam('response'); // available on api.*.post events

    // your logic here
}
```

### Common event identifiers

| Identifier | Fires for |
|---|---|
| `Omeka\Api\Adapter\MediaAdapter` | Media CRUD operations |
| `Omeka\Api\Adapter\ItemAdapter` | Item CRUD operations |
| `Omeka\Controller\Admin\Media` | Admin media pages |
| `Omeka\Controller\Admin\Item` | Admin item pages |
| `Omeka\Controller\Site\Item` | Public item pages |
| `*` | All controllers (use sparingly) |

### Common event names

| Event | When | Key params |
|---|---|---|
| `api.hydrate.post` | After entity hydration from request | `request`, `entity` |
| `api.create.post` | After entity created | `request`, `entity`, `response` |
| `api.update.post` | After entity updated | `request`, `entity`, `response` |
| `api.delete.pre` | Before entity deleted | `request`, `entity` |
| `view.show.after` | After show view rendered | view renderer as target |
| `view.layout` | Every page layout | view renderer as target |

### Notes
- `$event->getTarget()` returns the view renderer on `view.*` events and the adapter on `api.*` events.
- Get the view in `view.*` handlers: `$view = $event->getTarget();`
- Always check the entity type before acting: `if (!$this->isExeLearningFile($media)) return;`
- Services available via `$this->getServiceLocator()` inside `Module` methods.
