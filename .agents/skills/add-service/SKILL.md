---
name: add-service
description: Add a new Laminas service (with factory) to this Omeka S module. Invoke with the service name, e.g. /add-service MyNewService
---

Add a new service called $ARGUMENTS following the patterns in this module.

## Steps

1. **Create `src/Service/$ARGUMENTS.php`** — the service class in namespace `ExeLearning\Service`.

2. **Create `src/Service/${ARGUMENTS}Factory.php`** — implements `Laminas\ServiceManager\Factory\FactoryInterface`:
   ```php
   namespace ExeLearning\Service;
   use Interop\Container\ContainerInterface;
   use Laminas\ServiceManager\Factory\FactoryInterface;

   class ${ARGUMENTS}Factory implements FactoryInterface
   {
       public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
       {
           // pull dependencies from $services:
           // $services->get('Omeka\ApiManager')
           // $services->get('Omeka\EntityManager')
           // $services->get('Omeka\Logger')
           // $services->get('Config')
           return new $ARGUMENTS(/* dependencies */);
       }
   }
   ```

3. **Register in `config/module.config.php`** under `service_manager.factories`:
   ```php
   'service_manager' => [
       'factories' => [
           Service\$ARGUMENTS::class => Service\${ARGUMENTS}Factory::class,
       ],
   ],
   ```

4. **Inject where needed** — in a controller factory retrieve it with:
   ```php
   $myService = $services->get(\ExeLearning\Service\$ARGUMENTS::class);
   ```

5. **Write a unit test** in `test/ExeLearningTest/Service/` using PHPUnit. Add stubs to `test/Stubs/` for any new Omeka/Laminas collaborators not already stubbed.

## Notes
- Factories are excluded from coverage requirements (see phpunit.xml).
- Common Omeka services: `Omeka\ApiManager`, `Omeka\EntityManager`, `Omeka\Logger`, `Omeka\Settings`, `Omeka\Acl`.
- Use `$services->get('Config')` for module config values.
