<?php
declare(strict_types=1);

namespace ExeLearning\Controller\Admin;

use ExeLearning\Form\StylesUploadForm;
use ExeLearning\Service\StylesService;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * Admin controller for the Styles management page.
 *
 * All actions require `Omeka\Entity\Module` update capability so only
 * module administrators (typically the global admin) can add/remove
 * styles or toggle built-ins.
 */
class StylesController extends AbstractActionController
{
    private StylesService $styles;

    public function __construct(StylesService $styles)
    {
        $this->styles = $styles;
    }

    /**
     * Render the styles management page.
     */
    public function indexAction()
    {
        if (!$this->allowed()) {
            return $this->redirect()->toRoute('admin');
        }

        $form = new StylesUploadForm('styles_upload');
        $form->init();

        if ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();
            $files = $this->params()->fromFiles();
            $form->setData(array_merge($post, $files));
            if ($form->isValid()) {
                $summary = $this->processUploads($files['styles_zip'] ?? null);
                foreach ($summary['installed'] as $title) {
                    $this->messenger()->addSuccess(sprintf('Style "%s" installed.', $title));
                }
                foreach ($summary['errors'] as $error) {
                    $this->messenger()->addError($error);
                }
                return $this->redirect()->toRoute('admin/exelearning-styles');
            }
            $this->messenger()->addError('Please select at least one ZIP file.');
        }

        $view = new ViewModel([
            'form' => $form,
            'builtins' => $this->styles->listBuiltinThemes(),
            'uploaded' => $this->styles->listUploadedStyles(),
            'registry' => $this->styles->getRegistry(),
            'blockImport' => $this->styles->isImportBlocked(),
            'maxZipSize' => $this->styles->getMaxZipSize(),
        ]);
        $view->setTemplate('exelearning/admin/styles/index');
        return $view;
    }

    /**
     * Toggle an uploaded style's enabled flag.
     */
    public function toggleUploadedAction()
    {
        if (!$this->allowed() || !$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/exelearning-styles');
        }
        $slug = (string) $this->params()->fromPost('slug', '');
        $enabled = (bool) $this->params()->fromPost('enabled', 0);
        if ($slug !== '') {
            $this->styles->setUploadedEnabled($slug, $enabled);
        }
        return $this->redirect()->toRoute('admin/exelearning-styles');
    }

    /**
     * Toggle a built-in style's enabled flag.
     */
    public function toggleBuiltinAction()
    {
        if (!$this->allowed() || !$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/exelearning-styles');
        }
        $id = (string) $this->params()->fromPost('id', '');
        $enabled = (bool) $this->params()->fromPost('enabled', 0);
        if ($id !== '') {
            $this->styles->setBuiltinEnabled($id, $enabled);
        }
        return $this->redirect()->toRoute('admin/exelearning-styles');
    }

    /**
     * Delete an uploaded style.
     */
    public function deleteAction()
    {
        if (!$this->allowed() || !$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/exelearning-styles');
        }
        $slug = (string) $this->params()->fromPost('slug', '');
        if ($slug !== '' && $this->styles->deleteUploaded($slug)) {
            $this->messenger()->addSuccess('Style deleted.');
        }
        return $this->redirect()->toRoute('admin/exelearning-styles');
    }

    /**
     * Toggle the "block user-imported styles" policy.
     */
    public function toggleBlockImportAction()
    {
        if (!$this->allowed() || !$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/exelearning-styles');
        }
        $enabled = (bool) $this->params()->fromPost('enabled', 0);
        $this->styles->setImportBlocked($enabled);
        return $this->redirect()->toRoute('admin/exelearning-styles');
    }

    /**
     * Capability gate: module administrators only.
     *
     * Protected so a test subclass can override it without having to
     * wire up an entire Laminas service manager.
     */
    protected function allowed(): bool
    {
        if (!$this->identity()) {
            return false;
        }
        $acl = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\Acl');
        return $acl->userIsAllowed('Omeka\Entity\Module', 'update');
    }

    /**
     * Walk the uploaded $_FILES field, validate + extract each ZIP.
     *
     * @param mixed $files Normalized $_FILES['styles_zip'] (single or array).
     * @return array{installed: string[], errors: string[]}
     */
    private function processUploads($files): array
    {
        $summary = ['installed' => [], 'errors' => []];
        if (empty($files)) {
            return $summary;
        }
        // Normalize across the three shapes we can receive for styles_zip:
        //   a) Raw PHP multi-file: ['name' => [...], 'tmp_name' => [...], ...]
        //   b) Raw PHP single-file: ['name' => '...', 'tmp_name' => '...', ...]
        //   c) Laminas-transposed list: [ ['name' => '...', 'tmp_name' => '...'], ... ]
        // Only (c) arrives from $this->params()->fromFiles() with a multi
        // input, so it MUST be handled or every multi-upload reports "no
        // file was selected".
        $entries = [];
        $isTransposedList = array_is_list($files)
            && isset($files[0])
            && is_array($files[0])
            && array_key_exists('tmp_name', $files[0]);
        if ($isTransposedList) {
            foreach ($files as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $entries[] = [
                    'tmp_name' => $item['tmp_name'] ?? '',
                    'name'     => $item['name'] ?? '',
                    'error'    => $item['error'] ?? UPLOAD_ERR_NO_FILE,
                ];
            }
        } elseif (isset($files['tmp_name']) && is_array($files['tmp_name'])) {
            $count = count($files['tmp_name']);
            for ($i = 0; $i < $count; $i++) {
                $entries[] = [
                    'tmp_name' => $files['tmp_name'][$i] ?? '',
                    'name'     => $files['name'][$i] ?? '',
                    'error'    => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                ];
            }
        } else {
            $entries[] = [
                'tmp_name' => $files['tmp_name'] ?? '',
                'name'     => $files['name'] ?? '',
                'error'    => $files['error'] ?? UPLOAD_ERR_NO_FILE,
            ];
        }
        foreach ($entries as $entry) {
            if ((int) $entry['error'] !== UPLOAD_ERR_OK || $entry['tmp_name'] === '') {
                $summary['errors'][] = sprintf(
                    'Upload failed for %s: %s',
                    $entry['name'] !== '' ? $entry['name'] : 'unknown file',
                    self::uploadErrorMessage((int) $entry['error'])
                );
                continue;
            }
            try {
                $result = $this->styles->installFromZip($entry['tmp_name'], (string) $entry['name']);
                $summary['installed'][] = $result['title'] ?? $result['name'];
            } catch (\Throwable $e) {
                $summary['errors'][] = sprintf('%s: %s', $entry['name'] ?: 'file', $e->getMessage());
            }
        }
        return $summary;
    }

    /**
     * Translate a PHP $_FILES error code into an admin-facing string.
     */
    private static function uploadErrorMessage(int $code): string
    {
        switch ($code) {
            case UPLOAD_ERR_OK:
                return 'OK';
            case UPLOAD_ERR_INI_SIZE:
                return 'file exceeds upload_max_filesize';
            case UPLOAD_ERR_FORM_SIZE:
                return 'file exceeds the form MAX_FILE_SIZE';
            case UPLOAD_ERR_PARTIAL:
                return 'the file was only partially uploaded';
            case UPLOAD_ERR_NO_FILE:
                return 'no file was selected';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'PHP is missing a temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'PHP could not write the uploaded file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'a PHP extension stopped the upload';
            default:
                return sprintf('unknown upload error (code %d)', $code);
        }
    }
}
