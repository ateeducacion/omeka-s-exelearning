<?php

/**
 * CLI entry point for the architecture record validator.
 *
 * Usage:
 *   php scripts/architecture-records.php list    # print the record index
 *   php scripts/architecture-records.php check   # validate, non-zero on failure
 *
 * Kept separate from the class it drives so that no file both declares a symbol
 * and executes logic, and so the checks stay unit-testable without spawning a
 * process. The validator has no Composer dependencies: `make architecture-check`
 * must work in a checkout where `composer install` has not run.
 *
 * @see scripts/ArchitectureRecords.php
 * @see docs/architecture/adr/README.md
 */

declare(strict_types=1);

require_once __DIR__ . '/ArchitectureRecords.php';

$mode = isset($argv[1]) ? $argv[1] : '';
if ($mode !== 'list' && $mode !== 'check') {
    fwrite(STDERR, "Usage: php scripts/architecture-records.php <list|check>\n");
    exit(2);
}

exit(ExeLearningTools\ArchitectureRecords::run($mode, dirname(__DIR__)));
