<?php
declare(strict_types=1);

namespace ExeLearning\Controller;

use ExeLearning\Service\PreviewSessionStore;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;

/**
 * Authenticated, owner-scoped management API for editor preview sessions
 * (serving contract v2 — Omeka S adapter).
 *
 * This is the ONLY authenticated surface of the preview contract; the serving
 * route (PreviewController) is an authless capability URL. Every action requires
 * a logged-in identity and a valid CSRF token (via CsrfValidationTrait); the
 * session store enforces owner scoping (403/404). Wire endpoints:
 *
 *   POST   /api/exelearning/preview-session              create     -> 201
 *   POST   /api/exelearning/preview-session/:id/assets   upload     -> 200
 *   POST   /api/exelearning/preview-session/:id/revisions publish   -> 200
 *   DELETE /api/exelearning/preview-session/:id          delete     -> 200
 */
class PreviewSessionController extends AbstractActionController
{
    use CsrfValidationTrait;

    /** Protocol version advertised by create-session (mirrors eXe core). */
    private const PROTOCOL_VERSION = 2;

    /** @var PreviewSessionStore */
    private $store;

    public function __construct(PreviewSessionStore $store)
    {
        $this->store = $store;
    }

    /**
     * POST /api/exelearning/preview-session — create a session.
     *
     * @return JsonModel
     */
    public function createAction(): JsonModel
    {
        $guard = $this->guardWrite();
        if ($guard !== null) {
            return $guard;
        }

        $result = $this->store->createSession($this->ownerId());
        $this->getResponse()->setStatusCode(201);
        return new JsonModel([
            'previewId' => $result['previewId'],
            'protocolVersion' => self::PROTOCOL_VERSION,
            'revision' => 0,
            'limits' => $result['limits'],
        ]);
    }

    /**
     * POST /api/exelearning/preview-session/:id/assets — upload session assets.
     *
     * Multipart: `assets` = JSON array of `{ key, size }`, `files[]`
     * index-aligned.
     *
     * @return JsonModel
     */
    public function assetsAction(): JsonModel
    {
        $guard = $this->guardWrite();
        if ($guard !== null) {
            return $guard;
        }

        $request = $this->getRequest();
        $entries = $this->decodeJsonField($request, 'assets');
        if (!is_array($entries) || $this->hasInvalidAssetEntries($entries)) {
            return $this->error(400, 'assets must be an array of { key, size } entries');
        }
        $bytes = $this->collectFileBytes($request);
        if ($bytes === null || count($bytes) !== count($entries)) {
            return $this->error(400, 'assets and files must be index-aligned');
        }

        $storeEntries = [];
        foreach (array_values($entries) as $i => $entry) {
            $storeEntries[] = [
                'key' => (string) $entry['key'],
                'size' => (int) $entry['size'],
                'bytes' => $bytes[$i],
            ];
        }

        $result = $this->store->storeAssets($this->previewId(), $this->ownerId(), $storeEntries);
        if (isset($result['status'])) {
            return $this->ownershipError($result['status']);
        }
        return new JsonModel([
            'stored' => $result['stored'],
            'alreadyStored' => $result['alreadyStored'],
            'rejected' => $result['rejected'],
        ]);
    }

    /**
     * POST /api/exelearning/preview-session/:id/revisions — publish a revision.
     *
     * Multipart: `revision` = JSON meta, `files[]` index-aligned with
     * `meta.writes`.
     *
     * @return JsonModel
     */
    public function revisionsAction(): JsonModel
    {
        $guard = $this->guardWrite();
        if ($guard !== null) {
            return $guard;
        }

        $request = $this->getRequest();
        $meta = $this->decodeJsonField($request, 'revision');
        $error = $this->validateRevisionMeta($meta);
        if ($error !== null) {
            return $this->error(400, $error);
        }
        $bytes = $this->collectFileBytes($request);
        if ($bytes === null || count($bytes) !== count($meta['writes'])) {
            return $this->error(400, 'revision writes and files must be index-aligned');
        }

        $result = $this->store->applyRevision($this->previewId(), $this->ownerId(), $meta, $bytes);
        return $this->revisionResponse($result);
    }

    /**
     * DELETE /api/exelearning/preview-session/:id — delete a session.
     *
     * @return JsonModel
     */
    public function deleteAction(): JsonModel
    {
        if (!$this->identity()) {
            return $this->error(401, 'Unauthorized');
        }
        if (!$this->validateCsrf($this->getRequest())) {
            return $this->error(403, 'CSRF: Invalid or missing CSRF token');
        }

        $result = $this->store->deleteSession($this->previewId(), $this->ownerId());
        if (isset($result['status'])) {
            return $this->ownershipError($result['status']);
        }
        return new JsonModel(['success' => true]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Shared guard for the POST write actions: POST method + identity + CSRF.
     *
     * @return JsonModel|null A short-circuit error response, or null to proceed.
     */
    private function guardWrite(): ?JsonModel
    {
        $request = $this->getRequest();
        if (method_exists($request, 'isPost') && !$request->isPost()) {
            return $this->error(405, 'Method not allowed');
        }
        if (!$this->identity()) {
            return $this->error(401, 'Unauthorized');
        }
        if (!$this->validateCsrf($request)) {
            return $this->error(403, 'CSRF: Invalid or missing CSRF token');
        }
        return null;
    }

    /** The authenticated owner's id. */
    private function ownerId(): int
    {
        $identity = $this->identity();
        return $identity && method_exists($identity, 'getId') ? (int) $identity->getId() : 0;
    }

    /** The capability id from the route. */
    private function previewId(): string
    {
        return (string) $this->params()->fromRoute('id', '');
    }

    /**
     * Map an applyRevision result to a JSON response with the contract status.
     *
     * @param array $result
     * @return JsonModel
     */
    private function revisionResponse(array $result): JsonModel
    {
        if (isset($result['active'])) {
            return new JsonModel(['revision' => $result['revision'], 'active' => true]);
        }
        $status = (int) $result['status'];
        if ($status === 409) {
            $this->getResponse()->setStatusCode(409);
            return new JsonModel([
                'reason' => 'revision-conflict',
                'currentRevision' => $result['currentRevision'],
            ]);
        }
        if ($status === 422) {
            $this->getResponse()->setStatusCode(422);
            $body = ['reason' => $result['reason']];
            if (isset($result['missing'])) {
                $body['missing'] = $result['missing'];
            }
            if (isset($result['resources'])) {
                $body['resources'] = $result['resources'];
            }
            return new JsonModel($body);
        }
        if ($status === 403 || $status === 404) {
            return $this->ownershipError($status);
        }
        return $this->error($status, (string) ($result['message'] ?? 'Revision rejected'));
    }

    /**
     * Decode a multipart JSON field. Accepts a JSON string or a pre-parsed
     * value (some parsers deliver arrays directly).
     *
     * @param object $request
     * @param string $field
     * @return mixed Decoded value, or null on absence/parse failure.
     */
    private function decodeJsonField($request, string $field)
    {
        $raw = method_exists($request, 'getPost') ? $request->getPost($field) : null;
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return $decoded === null ? null : $decoded;
    }

    /**
     * @param array $entries
     * @return bool True if any entry is not a valid { key: string, size: int }.
     */
    private function hasInvalidAssetEntries(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['key']) || !is_string($entry['key'])) {
                return true;
            }
            if (!isset($entry['size']) || !is_int($entry['size'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param mixed $meta
     * @return string|null Error message, or null when the meta shape is valid.
     */
    private function validateRevisionMeta($meta): ?string
    {
        if (!is_array($meta)) {
            return 'revision must be a JSON object';
        }
        if (!isset($meta['baseRevision'], $meta['nextRevision'])
            || !is_int($meta['baseRevision']) || !is_int($meta['nextRevision'])) {
            return 'baseRevision and nextRevision must be integers';
        }
        $writes = $meta['writes'] ?? [];
        if (!is_array($writes) || !$this->isStringList($writes)) {
            return 'writes must be an array of paths';
        }
        $deletes = $meta['deletes'] ?? [];
        if (!is_array($deletes) || !$this->isStringList($deletes)) {
            return 'deletes must be an array of paths';
        }
        if (!$this->isStringMap($meta['assetRefs'] ?? []) || !$this->isStringMap($meta['fixedRefs'] ?? [])) {
            return 'assetRefs and fixedRefs must map paths to string ids';
        }
        return null;
    }

    /**
     * @param array $value
     * @return bool
     */
    private function isStringList(array $value): bool
    {
        foreach ($value as $item) {
            if (!is_string($item)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function isStringMap($value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Collect uploaded `files[]` bytes in order. Returns null if any part is
     * present but unreadable (a malformed upload rejects the whole request).
     *
     * @param object $request
     * @return array<int, string>|null
     */
    private function collectFileBytes($request): ?array
    {
        if (!method_exists($request, 'getFiles')) {
            return [];
        }
        $files = $request->getFiles();
        if (is_object($files) && method_exists($files, 'toArray')) {
            $files = $files->toArray();
        }
        $entries = is_array($files) ? ($files['files'] ?? []) : [];

        // Raw $_FILES multi-file shape: files => ['tmp_name' => [...], ...].
        if (isset($entries['tmp_name']) && is_array($entries['tmp_name'])) {
            $normalized = [];
            foreach ($entries['tmp_name'] as $i => $tmp) {
                $normalized[] = [
                    'tmp_name' => $tmp,
                    'error' => $entries['error'][$i] ?? UPLOAD_ERR_OK,
                ];
            }
            $entries = $normalized;
        }

        $bytes = [];
        foreach ((array) $entries as $entry) {
            if (!is_array($entry) || ($entry['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                return null;
            }
            $tmp = (string) ($entry['tmp_name'] ?? '');
            $content = $tmp !== '' ? @file_get_contents($tmp) : false;
            if ($content === false) {
                return null;
            }
            $bytes[] = $content;
        }
        return $bytes;
    }

    /**
     * A 403/404 ownership error with a stable message.
     *
     * @param int $status
     * @return JsonModel
     */
    private function ownershipError(int $status): JsonModel
    {
        return $this->error(
            $status,
            $status === 403 ? 'Access denied' : 'Preview session not found'
        );
    }

    /**
     * A JSON error response with the given status code.
     *
     * @param int $status
     * @param string $message
     * @return JsonModel
     */
    private function error(int $status, string $message): JsonModel
    {
        $this->getResponse()->setStatusCode($status);
        return new JsonModel(['success' => false, 'error' => $message]);
    }
}
