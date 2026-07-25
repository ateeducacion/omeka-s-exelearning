<?php
declare(strict_types=1);

namespace ExeLearning\Controller;

use ExeLearning\Service\PreviewSnapshotStore;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;

/**
 * Authenticated, owner-scoped management API for the opaque editor preview
 * (Omeka S adapter).
 *
 * This is the ONLY authenticated surface of the preview; the serving route
 * (PreviewController) is an authless capability URL. Both actions require a
 * logged-in identity and a valid CSRF token (via CsrfValidationTrait), and the
 * store enforces owner scoping (403/404). Two endpoints, because the editor
 * sends the whole project each time rather than patching it:
 *
 *   POST   /api/exelearning/preview-session       multipart: snapshot=<zip>,
 *                                                 previewId? -> {previewId}
 *   DELETE /api/exelearning/preview-session/:id
 *
 * This replaces the four-operation protocol v2 (create / assets / revisions /
 * delete) that the current editor build no longer speaks.
 */
class PreviewSessionController extends AbstractActionController
{
    use CsrfValidationTrait;

    /** @var PreviewSnapshotStore */
    private $store;

    public function __construct(PreviewSnapshotStore $store)
    {
        $this->store = $store;
    }

    /**
     * POST /api/exelearning/preview-session — publish a whole-project snapshot.
     *
     * `previewId` is absent on the first refresh (mint a capability) and present
     * afterwards (replace in place). The store refuses an id that is unknown or
     * owned by someone else, so it cannot be used to claim another author's
     * capability.
     *
     * @return JsonModel
     */
    public function createAction(): JsonModel
    {
        $guard = $this->guardWrite();
        if ($guard !== null) {
            return $guard;
        }

        $upload = $_FILES['snapshot'] ?? null;
        if (!is_array($upload)
            || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_string($upload['tmp_name'] ?? null)
            || $upload['tmp_name'] === '') {
            return $this->error(400, 'Missing snapshot upload');
        }

        // Read straight off the request, the way the rest of this controller
        // does: params()->fromPost() needs a wired plugin manager the unit
        // harness does not provide.
        $request = $this->getRequest();
        $previewId = method_exists($request, 'getPost') ? $request->getPost('previewId') : null;
        $previewId = is_string($previewId) && $previewId !== '' ? $previewId : null;

        $result = $this->store->replace($this->ownerId(), $upload['tmp_name'], $previewId);
        if (isset($result['error'])) {
            return $this->error((int) $result['status'], (string) $result['error']);
        }

        // No previewUrl: the client derives it from servingBaseUrl +
        // /{previewId}/index.html, which keeps one source of truth for how a
        // capability URL is shaped.
        return new JsonModel(['success' => true, 'previewId' => $result['previewId']]);
    }

    /**
     * DELETE /api/exelearning/preview-session/:id — drop a snapshot.
     *
     * Owner scoping comes from the same store verdict the publish path uses, so
     * the two verbs cannot drift: a malformed id is a 400, an unknown capability
     * a 404 and somebody else's a 403.
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

        $refused = $this->store->deleteOwned($this->previewId(), $this->ownerId());
        if ($refused !== null) {
            return $this->error((int) $refused['status'], (string) $refused['error']);
        }
        return new JsonModel(['success' => true]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Shared guard for the POST write action: POST method + identity + CSRF.
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

    /**
     * Validate against the dedicated, long-lived preview CSRF namespace via the
     * shared {@see PreviewCsrf::validator()} factory — the SAME options minting
     * used — rather than the default 300s form-token namespace, so a token
     * minted once at editor bootstrap keeps validating across an entire editing
     * session's preview publishes and mint/validate can never drift.
     *
     * @param string $token
     * @return bool
     */
    protected function csrfTokenIsValid(string $token): bool
    {
        return PreviewCsrf::validator()->isValid($token);
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
