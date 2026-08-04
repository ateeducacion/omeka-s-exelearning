<?php

declare(strict_types=1);

namespace ExeLearningTest;

// Pre-load stubs for missing vendor classes before Composer autoload
// This is needed because some Laminas packages reference optional dependencies
$stubsToPreload = [
    'Laminas\\Session\\Container' => __DIR__ . '/Stubs/Laminas/Session/Container.php',
];

foreach ($stubsToPreload as $class => $file) {
    if (!class_exists($class, false) && is_file($file)) {
        require $file;
    }
}

// Composer autoload (if present)
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require dirname(__DIR__) . '/vendor/autoload.php';
}

// Lightweight PSR-4 autoloader for tests and stubs
spl_autoload_register(function (string $class): void {
    // The module class sits at the repository root, per Omeka's module layout,
    // so Composer's ExeLearning\ -> src/ mapping cannot reach it. Omeka loads
    // it directly at bootstrap; tests and the coverage report need the same.
    if ($class === 'ExeLearning\\Module') {
        require dirname(__DIR__) . '/Module.php';
        return;
    }
    // Omeka test stubs
    if (strpos($class, 'Omeka\\') === 0) {
        $file = __DIR__ . '/Stubs/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }
    // Laminas stubs used by the renderers (PhpRenderer)
    if (strpos($class, 'Laminas\\') === 0) {
        $file = __DIR__ . '/Stubs/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }
    // Doctrine stubs
    if (strpos($class, 'Doctrine\\') === 0) {
        $file = __DIR__ . '/Stubs/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }
    // Test helpers/doubles under ExeLearningTest namespace
    if (strpos($class, __NAMESPACE__ . '\\') === 0) {
        $file = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});
