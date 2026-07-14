<?php
declare(strict_types=1);

namespace ExeLearningTest\Controller;

use ExeLearning\Controller\PreviewController;

class TestablePreviewController extends PreviewController
{
    protected function csrfTokenIsValid(string $token): bool
    {
        return $token === 'valid';
    }
}
