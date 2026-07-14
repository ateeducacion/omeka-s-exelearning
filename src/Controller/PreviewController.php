<?php
declare(strict_types=1);

namespace ExeLearning\Controller;

use ExeLearning\Service\PreviewSnapshotStore;
use Laminas\Http\Response as HttpResponse;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;

/** Manages and serves complete opaque editor-preview snapshots. */
class PreviewController extends AbstractActionController
{
    use CsrfValidationTrait;

    private const PREVIEW_SANDBOX_CSP =
        "sandbox allow-scripts allow-popups allow-forms allow-downloads allow-presentation; "
        . "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob: https:; "
        . "media-src 'self' data: blob: https:; "
        . "font-src 'self' data:; "
        . "connect-src 'self'; "
        . "frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; "
        . "child-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; "
        . "object-src 'none'; "
        . "base-uri 'none'; "
        . "form-action 'self'; "
        . "frame-ancestors 'self';";

    /** @var PreviewSnapshotStore */
    private $store;

    public function __construct(PreviewSnapshotStore $store)
    {
        $this->store = $store;
    }

    /** @return JsonModel */
    public function manageAction(): JsonModel
    {
        $request = $this->getRequest();
        if (!$this->identity()) {
            return $this->errorResponse(401, 'Unauthorized');
        }
        if (!$this->validateCsrf($request)) {
            return $this->errorResponse(403, 'Invalid or missing CSRF token');
        }

        $mediaId = (int) $this->params()->fromRoute('id', 0);
        if (!$mediaId || !$this->mediaIsEditable($mediaId)) {
            return $this->errorResponse(403, 'Media is not editable');
        }
        $ownerId = (int) $this->identity()->getId();

        if (method_exists($request, 'isDelete') && $request->isDelete()) {
            $previewId = (string) $this->params()->fromRoute('previewId', '');
            try {
                if (!$this->store->delete($ownerId, $mediaId, $previewId)) {
                    return $this->errorResponse(404, 'Preview snapshot not found');
                }
                return new JsonModel(['success' => true]);
            } catch (\UnexpectedValueException $error) {
                return $this->errorResponse(403, 'Preview snapshot is outside this media scope');
            }
        }

        if (!method_exists($request, 'isPost') || !$request->isPost()) {
            return $this->errorResponse(405, 'Method not allowed');
        }
        $files = $request->getFiles();
        $snapshot = $files['snapshot'] ?? null;
        if (!is_array($snapshot)
            || ($snapshot['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || empty($snapshot['tmp_name'])
        ) {
            return $this->errorResponse(400, 'A preview ZIP is required');
        }

        $previewId = method_exists($request, 'getPost')
            ? (string) $request->getPost('previewId', '')
            : '';
        try {
            $id = $this->store->replace(
                $ownerId,
                $mediaId,
                (string) $snapshot['tmp_name'],
                $previewId !== '' ? $previewId : null
            );
        } catch (\UnexpectedValueException $error) {
            return $this->errorResponse(403, 'Preview snapshot is outside this media scope');
        } catch (\InvalidArgumentException | \LengthException $error) {
            return $this->errorResponse(400, $error->getMessage());
        } catch (\RuntimeException $error) {
            return $this->errorResponse(409, $error->getMessage());
        }

        return new JsonModel([
            'previewId' => $id,
            'previewUrl' => $this->previewUrl($id),
        ]);
    }

    /** Serve one file without consulting the Omeka session. */
    public function serveAction(): HttpResponse
    {
        $previewId = (string) $this->params()->fromRoute('previewId', '');
        $path = (string) $this->params()->fromRoute('file', 'index.html');
        $file = $this->store->get($previewId, $path ?: 'index.html');
        if ($file === null) {
            return $this->notFound();
        }

        $response = new HttpResponse();
        $response->setStatusCode(200);
        $this->applyHeaders($response, $file['mime']);
        $response->setContent($file['bytes']);
        return $response;
    }

    private function mediaIsEditable(int $mediaId): bool
    {
        try {
            $this->api()->read('media', $mediaId);
        } catch (\Exception $error) {
            return false;
        }
        $acl = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\Acl');
        return $acl->userIsAllowed('Omeka\Entity\Media', 'update');
    }

    private function previewUrl(string $previewId): string
    {
        $uri = $this->getRequest()->getUri();
        $port = $uri->getPort();
        $origin = $uri->getScheme() . '://' . $uri->getHost();
        if ($port && !(($uri->getScheme() === 'http' && $port == 80)
            || ($uri->getScheme() === 'https' && $port == 443))
        ) {
            $origin .= ':' . $port;
        }
        $path = $uri->getPath();
        $apiPosition = strpos($path, '/api/');
        $basePath = $apiPosition === false ? '' : substr($path, 0, $apiPosition);
        return $origin . $basePath . '/exelearning/preview/' . $previewId . '/index.html';
    }

    private function errorResponse(int $statusCode, string $message): JsonModel
    {
        $this->getResponse()->setStatusCode($statusCode);
        return new JsonModel(['success' => false, 'message' => $message]);
    }

    private function applyHeaders(HttpResponse $response, string $mime): void
    {
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', $mime);
        $headers->addHeaderLine('X-Content-Type-Options', 'nosniff');
        $headers->addHeaderLine('Referrer-Policy', 'no-referrer');
        $headers->addHeaderLine('Cache-Control', 'no-store');
        $headers->addHeaderLine('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $headers->addHeaderLine('Cross-Origin-Resource-Policy', 'cross-origin');
        if ($this->scriptableMime($mime)) {
            $headers->addHeaderLine('Content-Security-Policy', self::PREVIEW_SANDBOX_CSP);
        }
    }

    private function scriptableMime(string $mime): bool
    {
        $mime = strtolower($mime);
        return strpos($mime, 'text/html') === 0
            || strpos($mime, 'image/svg+xml') === 0
            || strpos($mime, 'application/xml') === 0
            || strpos($mime, 'application/xhtml+xml') === 0;
    }

    private function notFound(): HttpResponse
    {
        $response = new HttpResponse();
        $response->setStatusCode(404);
        $this->applyHeaders($response, 'text/plain; charset=utf-8');
        $response->setContent('Not found');
        return $response;
    }
}
