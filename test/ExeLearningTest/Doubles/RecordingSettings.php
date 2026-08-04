<?php

declare(strict_types=1);

namespace ExeLearningTest\Doubles;

use Omeka\Settings\Settings;

/**
 * Settings that also remembers which keys were deleted, so the legacy-cleanup
 * tests can assert on the calls rather than on absence (a key that was never
 * set is indistinguishable from one that was deleted).
 */
class RecordingSettings extends Settings
{
    /** @var array<int, string> */
    public array $deleted = [];

    public function delete(string $key): void
    {
        $this->deleted[] = $key;
        parent::delete($key);
    }
}
