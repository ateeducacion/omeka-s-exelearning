---
name: add-route
description: Add a new route and controller action to this Omeka S module. Invoke with a short description, e.g. /add-route admin export endpoint
---

Add a new route and controller action for: $ARGUMENTS

## Steps

### 1. Add the route in `config/module.config.php`

**Public/API route** (standalone):
```php
'router' => [
    'routes' => [
        'exelearning-myroute' => [
            'type' => \Laminas\Router\Http\Segment::class,
            'options' => [
                'route' => '/exelearning/my-route[/:id]',
                'constraints' => ['id' => '\d+'],
                'defaults' => [
                    '__NAMESPACE__' => 'ExeLearning\Controller',
                    'controller' => 'MyController',
                    'action' => 'myAction',
                ],
            ],
        ],
    ],
],
```

**Admin child route** (under `/admin`):
```php
'admin' => [
    'child_routes' => [
        'exelearning-myroute' => [
            'type' => \Laminas\Router\Http\Segment::class,
            'options' => [
                'route' => '/exelearning/my-route[/:id]',
                'constraints' => ['id' => '\d+'],
                'defaults' => [
                    '__NAMESPACE__' => 'ExeLearning\Controller',
                    'controller' => 'MyController',
                    'action' => 'myAction',
                ],
            ],
        ],
    ],
],
```

Use `Literal` for fixed paths, `Segment` for paths with `:param`, `Regex` for paths needing slashes inside a segment (like `exelearning-content`).

### 2. Add the action to the controller

In `src/Controller/MyController.php`:
```php
public function myActionAction()
{
    // Check ACL if needed:
    $acl = $this->getServiceLocator()->get('Omeka\Acl');
    if (!$acl->userIsAllowed('Omeka\Entity\Media', 'read')) {
        return $this->redirect()->toRoute('login');
    }

    $id = $this->params('id');
    // ...
    return new \Laminas\View\Model\ViewModel(['data' => $data]);
}
```

For JSON responses: `return new \Laminas\View\Model\JsonModel(['key' => 'value']);`

### 3. Add the view template (if not JSON)

Create `view/exe-learning/my-controller/my-action.phtml`.

### 4. Generate the URL in views/JS

PHP: `$this->url('exelearning-myroute', ['id' => $id])`
JS: Use `$request->getBasePath()` prefix — the module supports playground prefix environments.

### 5. Write a unit test

Add a controller test in `test/ExeLearningTest/Controller/` following `ApiControllerTest.php` as the example.
