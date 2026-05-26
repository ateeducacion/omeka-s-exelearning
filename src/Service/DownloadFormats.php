<?php
declare(strict_types=1);

namespace ExeLearning\Service;

/**
 * Canonical registry of formats offered by the embed multi-format download
 * split-button. The actual format generation is produced client-side by the
 * eXeLearning `SharedExporters` bundle; this class only describes the
 * options exposed in the UI and the filename suffix convention.
 */
final class DownloadFormats
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            [
                'id' => 'elpx',
                'label' => 'eXeLearning source',
                'suffix' => '.elpx',
                'mime' => 'application/zip',
                'client' => false,
            ],
            [
                'id' => 'html5',
                'label' => 'HTML5 web',
                'suffix' => '_web.zip',
                'mime' => 'application/zip',
                'client' => true,
            ],
            [
                'id' => 'scorm12',
                'label' => 'SCORM 1.2',
                'suffix' => '_scorm.zip',
                'mime' => 'application/zip',
                'client' => true,
            ],
            [
                'id' => 'ims',
                'label' => 'IMS Content Package',
                'suffix' => '_ims.zip',
                'mime' => 'application/zip',
                'client' => true,
            ],
            [
                'id' => 'epub3',
                'label' => 'EPUB 3',
                'suffix' => '.epub',
                'mime' => 'application/epub+zip',
                'client' => true,
            ],
        ];
    }

    /**
     * @return string[]
     */
    public static function defaultIds(): array
    {
        return array_map(static fn ($f) => $f['id'], self::all());
    }

    /**
     * Sanitize a stored or submitted list of format ids.
     *
     * @param mixed $input  Array, comma-separated string, or null/missing.
     * @return string[]     Canonical-ordered subset of {@see self::all()}.
     */
    public static function sanitize($input): array
    {
        if (is_string($input)) {
            $input = array_filter(array_map('trim', explode(',', $input)));
        }
        if (!is_array($input)) {
            return self::defaultIds();
        }

        $valid = self::defaultIds();
        $set = array_map('strval', $input);
        $out = [];
        foreach ($valid as $id) {
            if (in_array($id, $set, true)) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $id): ?array
    {
        foreach (self::all() as $fmt) {
            if ($fmt['id'] === $id) {
                return $fmt;
            }
        }
        return null;
    }
}
