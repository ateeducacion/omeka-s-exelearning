<?php

declare(strict_types=1);

namespace Omeka\Mvc\Controller\Plugin;

/**
 * Test stub for Omeka's flash-messenger controller plugin.
 *
 * The real plugin writes into the session container. Module.php only ever
 * constructs one and calls addSuccess(), so the stub records messages in
 * memory where a test can read them back.
 */
class Messenger
{
    const ERROR = 'error';
    const SUCCESS = 'success';
    const WARNING = 'warning';
    const NOTICE = 'notice';

    /** @var array<string, array<int, mixed>> */
    private static $messages = [];

    /**
     * @param mixed $message
     */
    public function addSuccess($message): void
    {
        self::$messages[self::SUCCESS][] = $message;
    }

    /**
     * @param mixed $message
     */
    public function addError($message): void
    {
        self::$messages[self::ERROR][] = $message;
    }

    /**
     * @param mixed $message
     */
    public function addWarning($message): void
    {
        self::$messages[self::WARNING][] = $message;
    }

    /**
     * @param mixed $message
     */
    public function addNotice($message): void
    {
        self::$messages[self::NOTICE][] = $message;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function get(): array
    {
        return self::$messages;
    }

    /**
     * Drop everything recorded so far. Tests call this in setUp() because the
     * store is static, mirroring the real plugin's per-session persistence.
     */
    public static function reset(): void
    {
        self::$messages = [];
    }
}
