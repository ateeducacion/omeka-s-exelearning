<?php

declare(strict_types=1);

namespace Laminas\Mvc\Controller;

/**
 * Test stub for Laminas' controller base class.
 *
 * Module::handleConfigForm() only type-hints this and then calls
 * params()->fromPost(). params() is left undeclared so each test double can
 * supply its own posted data; declaring it here would force every double to
 * match a signature the production code never varies.
 */
abstract class AbstractController
{
}
