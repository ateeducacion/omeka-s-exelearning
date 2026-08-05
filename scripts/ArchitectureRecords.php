<?php

declare(strict_types=1);

namespace ExeLearningTools;

/**
 * Validation and index generation for the module's architecture records.
 *
 * Discovers Architecture Decision Records under `docs/architecture/adr/` and
 * change directories under `docs/architecture/changes/`, validates their
 * identifiers, metadata and cross-references, and renders the two indexes.
 *
 * This is a PHP port of the Bun/TypeScript validator used by the main
 * eXeLearning repository (`scripts/architecture-records.ts`). The rules are the
 * same; the runtime is not. This repository is a PHP module whose CI installs
 * only PHP, so adding Bun purely to lint documentation would be a new toolchain
 * dependency for one check. The two adaptations to the shared rules are
 * deliberate and documented in `docs/architecture/adr/README.md`:
 *
 *  - `deciders` / `reviewers` are not required. This repository's frontmatter
 *    deliberately records tools and links, never people's names.
 *  - `related.prs` and `related.issues` accept a cross-repository reference as
 *    well as a bare number, because issues are disabled here and much of this
 *    module's traceability points at sibling repositories.
 *
 * The index is deliberately NOT a committed file: it is contributor-facing, it
 * is derived entirely from frontmatter, and a generated file checked into git
 * conflicts on every concurrent branch.
 *
 * @see docs/architecture/adr/README.md
 */
class ArchitectureRecords
{
    const ADR_DIR = 'docs/architecture/adr';
    const CHANGES_DIR = 'docs/architecture/changes';
    const MIGRATION_MAP = 'docs/architecture/migration-map.md';
    const REPOSITORY = 'exelearning/omeka-s-exelearning';

    const ADR_STATUSES = ['Proposed', 'Accepted', 'Rejected', 'Superseded'];
    const CHANGE_STATUSES = [
        'draft',
        'in-review',
        'accepted',
        'implemented',
        'superseded',
        'abandoned',
    ];

    const CHANGE_DOCUMENTS = ['proposal.md', 'spec.md', 'design.md', 'research.md', 'tasks.md'];

    /** Files inside the ADR directory that are policy, not records. */
    const ADR_NON_RECORDS = ['README.md', 'records.md', 'template.md'];

    const ADR_FILENAME_RE = '/^ADR-([1-9][0-9]*)-([0-9]{2})-([a-z0-9]+(?:-[a-z0-9]+)*)\.md$/';
    const CHANGE_DIR_RE = '/^([1-9][0-9]*)-([a-z0-9]+(?:-[a-z0-9]+)*)$/';

    /**
     * A retired identifier is `ADR-NNNN` / `SDD-NNNN` that is *not* followed by
     * a two-digit local sequence. Without that lookahead a current identifier
     * such as `ADR-1858-01` would match on its own four-digit prefix.
     */
    const LEGACY_ID_RE = '/\b(?:ADR|SDD)-[0-9]{4}(?!-[0-9]{2})\b/';
    const LEGACY_FILENAME_RE = '/^(?:ADR|SDD)-[0-9]{4}-/';

    /**
     * A traceability reference: a bare number in this repository, a
     * `owner/repo#123` shorthand, or a full GitHub issue/PR URL.
     */
    const REFERENCE_RE = '#^(?:[1-9][0-9]*'
        . '|[A-Za-z0-9._-]+/[A-Za-z0-9._-]+\#[1-9][0-9]*'
        . '|https://github\.com/[A-Za-z0-9._-]+/[A-Za-z0-9._-]+/(?:pull|issues)/[1-9][0-9]*)$#';

    /**
     * Files allowed to mention retired identifiers, because documenting the
     * migration requires naming what was migrated. Everything else in the
     * repository must use current identifiers. A prefix match, so a directory
     * may be listed.
     */
    const LEGACY_REFERENCE_ALLOWLIST = [
        self::MIGRATION_MAP,
        // This detector's own tests need retired identifiers as fixtures.
        'test/ExeLearningTest/Tools/ArchitectureRecordsTest.php',
    ];

    const GENERATED_BANNER = '<!-- Produced by `make architecture-records`. Not a committed file. -->';

    /** Frontmatter keys under `related:` that were renamed by the migration. */
    const RETIRED_RELATED_KEYS = ['sdds' => 'changes'];

    /**
     * Where diagnostics go. Defaults to STDERR; tests point it at a memory
     * stream so a deliberately broken fixture does not spray the suite output,
     * and so the reported text can be asserted on.
     *
     * @var resource|null
     */
    public static $errorStream = null;

    // -----------------------------------------------------------------
    // Frontmatter
    // -----------------------------------------------------------------

    /**
     * Parse the bounded YAML subset used by architecture frontmatter: scalars,
     * inline lists, block lists, one level of nested mappings, and block lists
     * nested inside those mappings.
     *
     * Deliberately not a general YAML parser. The schema is fixed and small,
     * and this module ships no YAML dependency.
     *
     * @return array{data: array<string, mixed>, body: string}|null
     */
    public static function parseFrontmatter(string $raw): ?array
    {
        if (!preg_match('/^---\r?\n(.*?)\r?\n---\r?\n?(.*)$/s', $raw, $match)) {
            return null;
        }

        $data = [];
        $topKey = null;
        $nestedKey = null;
        $nestedIndent = 0;

        foreach (preg_split('/\r?\n/', $match[1]) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || strncmp($trimmed, '#', 1) === 0) {
                continue;
            }

            $indent = strlen($line) - strlen(ltrim($line, ' '));

            if ($indent === 0 && preg_match('/^([A-Za-z_][A-Za-z0-9_]*):(.*)$/', $line, $keyMatch)) {
                $topKey = $keyMatch[1];
                $nestedKey = null;
                $rest = trim($keyMatch[2]);
                if ($rest === '') {
                    $data[$topKey] = [];
                } else {
                    $data[$topKey] = self::parseScalarOrInlineList($rest);
                    $topKey = null;
                }
                continue;
            }

            if ($topKey === null) {
                continue;
            }

            if (preg_match('/^\s+-\s*(.*)$/', $line, $itemMatch)) {
                $value = self::stripQuotes(trim($itemMatch[1]));
                if ($nestedKey !== null && $indent > $nestedIndent) {
                    $data[$topKey][$nestedKey][] = $value;
                } else {
                    $nestedKey = null;
                    $data[$topKey][] = $value;
                }
                continue;
            }

            if (preg_match('/^\s+([A-Za-z_][A-Za-z0-9_]*):(.*)$/', $line, $subMatch)) {
                $nestedKey = $subMatch[1];
                $nestedIndent = $indent;
                $rest = trim($subMatch[2]);
                $data[$topKey][$nestedKey] = $rest === ''
                    ? []
                    : self::parseScalarOrInlineList($rest);
            }
        }

        return ['data' => $data, 'body' => $match[2]];
    }

    /**
     * @return string|array<int, string>
     */
    public static function parseScalarOrInlineList(string $raw)
    {
        if (strncmp($raw, '[', 1) === 0 && substr($raw, -1) === ']') {
            $inner = trim(substr($raw, 1, -1));
            if ($inner === '') {
                return [];
            }
            return array_map(
                function (string $part): string {
                    return self::stripQuotes(trim($part));
                },
                explode(',', $inner)
            );
        }
        return self::stripQuotes($raw);
    }

    public static function stripQuotes(string $value): string
    {
        return preg_replace('/^["\'](.*)["\']$/', '$1', $value);
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    public static function asList($value): array
    {
        if ($value === null) {
            return [];
        }
        if (is_array($value)) {
            $list = [];
            foreach ($value as $key => $item) {
                if (!is_int($key) || is_array($item)) {
                    return [];
                }
                $list[] = (string) $item;
            }
            return $list;
        }
        $single = trim((string) $value);
        return $single === '' ? [] : [$single];
    }

    /**
     * @param mixed $value
     */
    public static function asString($value): string
    {
        if ($value === null || is_array($value)) {
            return '';
        }
        return (string) $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return mixed
     */
    public static function nested(array $data, string $key, string $sub)
    {
        if (isset($data[$key]) && is_array($data[$key]) && array_key_exists($sub, $data[$key])) {
            return $data[$key][$sub];
        }
        return null;
    }

    // -----------------------------------------------------------------
    // Discovery
    // -----------------------------------------------------------------

    /**
     * @return array{adrs: array<int, array<string, mixed>>, errors: array<int, array{file: string, message: string}>}
     */
    public static function discoverAdrs(string $root): array
    {
        $dir = $root . '/' . self::ADR_DIR;
        $adrs = [];
        $errors = [];
        if (!is_dir($dir)) {
            return ['adrs' => $adrs, 'errors' => $errors];
        }

        $files = scandir($dir);
        sort($files);
        foreach ($files as $file) {
            if (substr($file, -3) !== '.md' || in_array($file, self::ADR_NON_RECORDS, true)) {
                continue;
            }

            $rel = self::ADR_DIR . '/' . $file;

            // The current grammar is checked first: `ADR-1858-01-slug.md` also
            // starts with four digits, so the retired pattern would shadow it.
            if (!preg_match(self::ADR_FILENAME_RE, $file, $match)) {
                $errors[] = [
                    'file' => $rel,
                    'message' => preg_match(self::LEGACY_FILENAME_RE, $file)
                        ? 'uses the retired global numbering. Rename to '
                            . 'ADR-<tracking-number>-<NN>-<decision-slug>.md (see '
                            . self::ADR_DIR . '/README.md).'
                        : 'filename does not match ADR-<tracking-number>-<NN>-<decision-slug>.md',
                ];
                continue;
            }

            $parsed = self::parseFrontmatter((string) file_get_contents($dir . '/' . $file));
            if ($parsed === null) {
                $errors[] = ['file' => $rel, 'message' => 'missing YAML frontmatter'];
                continue;
            }

            $data = $parsed['data'];
            $h1 = null;
            if (preg_match('/^# (.+)$/m', $parsed['body'], $headingMatch)) {
                $h1 = rtrim($headingMatch[1], "\r");
            }

            $related = isset($data['related']) && is_array($data['related']) ? $data['related'] : [];

            $adrs[] = [
                'path' => $rel,
                'file' => $file,
                'id' => self::asString(isset($data['id']) ? $data['id'] : null),
                'number' => (int) $match[1],
                'sequence' => $match[2],
                'title' => self::asString(isset($data['title']) ? $data['title'] : null),
                'status' => self::asString(isset($data['status']) ? $data['status'] : null),
                'date' => self::asString(isset($data['date']) ? $data['date'] : null),
                'trackingIssue' => self::asString(isset($data['tracking_issue']) ? $data['tracking_issue'] : null),
                'legacyId' => isset($data['legacy_id']) ? self::asString($data['legacy_id']) : null,
                'supersedes' => self::asList(isset($data['supersedes']) ? $data['supersedes'] : null),
                'supersededBy' => self::asList(isset($data['superseded_by']) ? $data['superseded_by'] : null),
                'relatedKeys' => array_keys($related),
                'relatedAdrs' => self::asList(self::nested($data, 'related', 'adrs')),
                'relatedChanges' => self::asList(self::nested($data, 'related', 'changes')),
                'relatedPrs' => self::asList(self::nested($data, 'related', 'prs')),
                'relatedIssues' => self::asList(self::nested($data, 'related', 'issues')),
                'aiTool' => isset($data['ai_assistance'])
                    ? self::asString(self::nested($data, 'ai_assistance', 'tool'))
                    : null,
                'aiModel' => isset($data['ai_assistance'])
                    ? self::asString(self::nested($data, 'ai_assistance', 'model'))
                    : null,
                'h1' => $h1,
            ];
        }

        return ['adrs' => $adrs, 'errors' => $errors];
    }

    /**
     * @return array{
     *     changes: array<int, array<string, mixed>>,
     *     errors: array<int, array{file: string, message: string}>
     * }
     */
    public static function discoverChanges(string $root): array
    {
        $dir = $root . '/' . self::CHANGES_DIR;
        $changes = [];
        $errors = [];
        if (!is_dir($dir)) {
            return ['changes' => $changes, 'errors' => $errors];
        }

        $entries = scandir($dir);
        sort($entries);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($dir . '/' . $entry)) {
                continue;
            }

            $rel = self::CHANGES_DIR . '/' . $entry;
            if (!preg_match(self::CHANGE_DIR_RE, $entry, $match)) {
                $errors[] = [
                    'file' => $rel,
                    'message' => 'directory name does not match <tracking-number>-<change-slug>',
                ];
                continue;
            }

            $documents = [];
            foreach (self::CHANGE_DOCUMENTS as $name) {
                $docPath = $dir . '/' . $entry . '/' . $name;
                if (!is_file($docPath)) {
                    continue;
                }
                $parsed = self::parseFrontmatter((string) file_get_contents($docPath));
                if ($parsed === null) {
                    $errors[] = ['file' => $rel . '/' . $name, 'message' => 'missing YAML frontmatter'];
                    continue;
                }
                $documents[] = [
                    'path' => $rel . '/' . $name,
                    'name' => $name,
                    'data' => $parsed['data'],
                ];
            }

            if ($documents === []) {
                $errors[] = [
                    'file' => $rel,
                    'message' => 'contains no recognised document ('
                        . implode(', ', self::CHANGE_DOCUMENTS) . ')',
                ];
                continue;
            }

            $canonical = $documents[0];
            $changes[] = [
                'dir' => $rel,
                'name' => $entry,
                'number' => (int) $match[1],
                'slug' => $match[2],
                'documents' => $documents,
                'canonical' => $canonical,
                'title' => self::asString(
                    isset($canonical['data']['title']) ? $canonical['data']['title'] : null
                ),
                'status' => self::asString(
                    isset($canonical['data']['status']) ? $canonical['data']['status'] : null
                ),
                'date' => self::asString(
                    isset($canonical['data']['date']) ? $canonical['data']['date'] : null
                ),
                'implementationPrs' => self::asList(
                    isset($canonical['data']['implementation_prs']) ? $canonical['data']['implementation_prs'] : null
                ),
                'relatedAdrs' => self::asList(
                    isset($canonical['data']['related_adrs']) ? $canonical['data']['related_adrs'] : null
                ),
            ];
        }

        return ['changes' => $changes, 'errors' => $errors];
    }

    // -----------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------

    public static function isValidDate(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match)) {
            return false;
        }
        return checkdate((int) $match[2], (int) $match[3], (int) $match[1]);
    }

    public static function isPositiveInteger(string $value): bool
    {
        return (bool) preg_match('/^[1-9][0-9]*$/', $value);
    }

    public static function isReference(string $value): bool
    {
        return (bool) preg_match(self::REFERENCE_RE, $value);
    }

    /**
     * @param array<int, array<string, mixed>> $adrs
     * @param array<int, array<string, mixed>> $changes
     * @return array<int, array{file: string, message: string}>
     */
    public static function validate(array $adrs, array $changes): array
    {
        $problems = [];
        $add = function (string $file, string $message) use (&$problems): void {
            $problems[] = ['file' => $file, 'message' => $message];
        };

        $adrIds = [];
        foreach ($adrs as $adr) {
            $adrIds[$adr['id']] = true;
        }
        $changeNames = [];
        foreach ($changes as $change) {
            $changeNames[$change['name']] = true;
        }

        $seenIds = [];
        $seenSequences = [];

        foreach ($adrs as $adr) {
            $expectedId = 'ADR-' . $adr['number'] . '-' . $adr['sequence'];

            if ($adr['id'] === '') {
                $add($adr['path'], 'missing required field `id`');
            } elseif ($adr['id'] !== $expectedId) {
                $add(
                    $adr['path'],
                    'frontmatter id "' . $adr['id'] . '" does not match filename '
                        . '(expected "' . $expectedId . '")'
                );
            }

            if ($adr['title'] === '') {
                $add($adr['path'], 'missing required field `title`');
            }

            if ($adr['date'] === '') {
                $add($adr['path'], 'missing required field `date`');
            } elseif (!self::isValidDate($adr['date'])) {
                $add($adr['path'], 'date "' . $adr['date'] . '" is not a valid YYYY-MM-DD date');
            }

            if ($adr['status'] === '') {
                $add($adr['path'], 'missing required field `status`');
            } elseif (!in_array($adr['status'], self::ADR_STATUSES, true)) {
                $add(
                    $adr['path'],
                    'status "' . $adr['status'] . '" is not one of ' . implode(', ', self::ADR_STATUSES)
                );
            }

            if ($adr['trackingIssue'] === '') {
                $add($adr['path'], 'missing required field `tracking_issue`');
            } elseif (!self::isPositiveInteger($adr['trackingIssue'])) {
                $add(
                    $adr['path'],
                    'tracking_issue "' . $adr['trackingIssue'] . '" is not a positive integer'
                );
            } elseif ((int) $adr['trackingIssue'] !== $adr['number']) {
                $add(
                    $adr['path'],
                    'tracking_issue ' . $adr['trackingIssue']
                        . ' does not match the filename tracking number ' . $adr['number']
                );
            }

            if ($adr['aiTool'] === null || $adr['aiTool'] === '') {
                $add(
                    $adr['path'],
                    'missing required field `ai_assistance.tool` (use `none` if no AI tool was used)'
                );
            }
            if ($adr['aiModel'] === null || $adr['aiModel'] === '') {
                $add(
                    $adr['path'],
                    'missing required field `ai_assistance.model` (use `none` if no AI tool was used)'
                );
            }

            $expectedH1 = $expectedId . ': ' . $adr['title'];
            if ($adr['h1'] === null) {
                $add($adr['path'], 'missing H1 heading');
            } elseif ($adr['h1'] !== $expectedH1) {
                $add($adr['path'], 'H1 is "' . $adr['h1'] . '" but should be "' . $expectedH1 . '"');
            }

            if (isset($seenIds[$adr['id']])) {
                $add($adr['path'], 'duplicate ADR id "' . $adr['id'] . '" (also in ' . $seenIds[$adr['id']] . ')');
            } elseif ($adr['id'] !== '') {
                $seenIds[$adr['id']] = $adr['path'];
            }

            $sequenceKey = $adr['number'] . '-' . $adr['sequence'];
            if (isset($seenSequences[$sequenceKey])) {
                $add(
                    $adr['path'],
                    'duplicate local sequence ' . $adr['sequence'] . ' for tracking number '
                        . $adr['number'] . ' (also in ' . $seenSequences[$sequenceKey] . ')'
                );
            } else {
                $seenSequences[$sequenceKey] = $adr['path'];
            }

            foreach ($adr['relatedKeys'] as $key) {
                if (array_key_exists($key, self::RETIRED_RELATED_KEYS)) {
                    $add(
                        $adr['path'],
                        'related.' . $key . ' is retired; use related.'
                            . self::RETIRED_RELATED_KEYS[$key] . ' instead'
                    );
                }
            }

            foreach ($adr['relatedAdrs'] as $ref) {
                if (!isset($adrIds[$ref])) {
                    $add($adr['path'], 'related.adrs references unknown ADR "' . $ref . '"');
                }
            }
            foreach ($adr['relatedChanges'] as $ref) {
                if (!isset($changeNames[$ref])) {
                    $add($adr['path'], 'related.changes references unknown change "' . $ref . '"');
                }
            }
            foreach ($adr['relatedPrs'] as $ref) {
                if (!self::isReference($ref)) {
                    $add(
                        $adr['path'],
                        'related.prs value "' . $ref . '" is not a number, owner/repo#N or a GitHub URL'
                    );
                }
            }
            foreach ($adr['relatedIssues'] as $ref) {
                if (!self::isReference($ref)) {
                    $add(
                        $adr['path'],
                        'related.issues value "' . $ref . '" is not a number, owner/repo#N or a GitHub URL'
                    );
                }
            }

            self::validateSupersession($adr, $adrs, $adrIds, $add);
        }

        foreach ($changes as $change) {
            self::validateChange($change, $adrIds, $add);
        }

        return $problems;
    }

    /**
     * @param array<string, mixed> $adr
     * @param array<int, array<string, mixed>> $adrs
     * @param array<string, bool> $adrIds
     */
    private static function validateSupersession(array $adr, array $adrs, array $adrIds, callable $add): void
    {
        $byId = [];
        foreach ($adrs as $candidate) {
            $byId[$candidate['id']] = $candidate;
        }

        foreach ($adr['supersedes'] as $ref) {
            if ($ref === $adr['id']) {
                $add($adr['path'], 'an ADR cannot supersede itself');
                continue;
            }
            if (!isset($adrIds[$ref])) {
                $add($adr['path'], 'supersedes references unknown ADR "' . $ref . '"');
                continue;
            }
            $target = $byId[$ref];
            if (!in_array($adr['id'], $target['supersededBy'], true)) {
                $add(
                    $adr['path'],
                    'supersedes "' . $ref . '" but ' . $target['path']
                        . ' does not list superseded_by: [' . $adr['id'] . ']'
                );
            }
            if ($target['status'] !== 'Superseded') {
                $add(
                    $target['path'],
                    'is superseded by ' . $adr['id'] . ' but status is "'
                        . $target['status'] . '", not "Superseded"'
                );
            }
        }

        foreach ($adr['supersededBy'] as $ref) {
            if ($ref === $adr['id']) {
                $add($adr['path'], 'an ADR cannot be superseded by itself');
                continue;
            }
            if (!isset($adrIds[$ref])) {
                $add($adr['path'], 'superseded_by references unknown ADR "' . $ref . '"');
                continue;
            }
            $target = $byId[$ref];
            if (!in_array($adr['id'], $target['supersedes'], true)) {
                $add(
                    $adr['path'],
                    'superseded_by "' . $ref . '" but ' . $target['path']
                        . ' does not list supersedes: [' . $adr['id'] . ']'
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $change
     * @param array<string, bool> $adrIds
     */
    private static function validateChange(array $change, array $adrIds, callable $add): void
    {
        $canonical = $change['canonical'];

        if ($change['title'] === '') {
            $add($canonical['path'], 'missing required field `title`');
        }
        if ($change['date'] === '') {
            $add($canonical['path'], 'missing required field `date`');
        } elseif (!self::isValidDate($change['date'])) {
            $add($canonical['path'], 'date "' . $change['date'] . '" is not a valid YYYY-MM-DD date');
        }
        if ($change['status'] === '') {
            $add($canonical['path'], 'missing required field `status`');
        } elseif (!in_array($change['status'], self::CHANGE_STATUSES, true)) {
            $add(
                $canonical['path'],
                'status "' . $change['status'] . '" is not one of ' . implode(', ', self::CHANGE_STATUSES)
            );
        }

        foreach ($change['implementationPrs'] as $ref) {
            if (!self::isReference($ref)) {
                $add(
                    $canonical['path'],
                    'implementation_prs value "' . $ref . '" is not a number, owner/repo#N or a GitHub URL'
                );
            }
        }
        foreach ($change['relatedAdrs'] as $ref) {
            if (!isset($adrIds[$ref])) {
                $add($canonical['path'], 'related_adrs references unknown ADR "' . $ref . '"');
            }
        }

        foreach ($change['documents'] as $doc) {
            $number = self::asString(isset($doc['data']['tracking_issue']) ? $doc['data']['tracking_issue'] : null);
            if ($number === '') {
                $add($doc['path'], 'missing required field `tracking_issue`');
            } elseif (!self::isPositiveInteger($number)) {
                $add($doc['path'], 'tracking_issue "' . $number . '" is not a positive integer');
            } elseif ((int) $number !== $change['number']) {
                $add(
                    $doc['path'],
                    'tracking_issue ' . $number . ' does not match the change directory tracking number '
                        . $change['number']
                );
            }

            if (self::asString(isset($doc['data']['title']) ? $doc['data']['title'] : null) === '') {
                $add($doc['path'], 'missing required field `title`');
            }

            if ($doc['name'] !== $canonical['name'] && isset($doc['data']['implementation_prs'])) {
                $add(
                    $doc['path'],
                    'declares implementation_prs, but ' . $canonical['name']
                        . ' is the canonical metadata carrier for this change'
                );
            }
        }
    }

    /**
     * The index is derived, not stored. Committing it reintroduces exactly the
     * merge-conflict class this convention removes, so its presence is an error.
     *
     * @param array<int, string> $files
     * @return array<int, array{file: string, message: string}>
     */
    public static function findCommittedIndexes(array $files): array
    {
        $indexes = [
            self::ADR_DIR . '/records.md',
            self::CHANGES_DIR . '/records.md',
            'docs/architecture/sdd/records.md',
        ];

        $problems = [];
        foreach ($files as $file) {
            if (!in_array($file, $indexes, true)) {
                continue;
            }
            $problems[] = [
                'file' => $file,
                'message' => 'the record index must not be committed -- it is derived from '
                    . 'frontmatter and conflicts on every concurrent branch. Delete it; '
                    . '`make architecture-records` prints it.',
            ];
        }
        return $problems;
    }

    /**
     * Scan tracked files for retired identifiers outside the documented allowlist.
     *
     * @param array<int, string> $files
     * @return array<int, array{file: string, message: string}>
     */
    public static function findLegacyReferences(string $root, array $files): array
    {
        $problems = [];
        foreach ($files as $file) {
            if (self::isAllowlisted($file)) {
                continue;
            }
            $full = $root . '/' . $file;
            if (!is_file($full)) {
                continue;
            }
            $content = @file_get_contents($full);
            if ($content === false || strpos($content, "\0") !== false) {
                continue;
            }

            // A migrated document may name its own former identifier, so the
            // provenance note inside the document itself stays readable.
            $ownLegacyId = null;
            if (substr($file, -3) === '.md') {
                $parsed = self::parseFrontmatter($content);
                if ($parsed !== null && isset($parsed['data']['legacy_id'])) {
                    $ownLegacyId = self::asString($parsed['data']['legacy_id']);
                }
            }

            foreach (preg_split('/\r?\n/', $content) as $index => $line) {
                if (strpos($line, 'legacy_id:') !== false) {
                    continue;
                }
                if (!preg_match(self::LEGACY_ID_RE, $line, $hit)) {
                    continue;
                }
                if ($ownLegacyId !== null && $hit[0] === $ownLegacyId) {
                    continue;
                }
                $problems[] = [
                    'file' => $file . ':' . ($index + 1),
                    'message' => 'references retired identifier "' . $hit[0]
                        . '". Use the current identifier (see ' . self::MIGRATION_MAP . ').',
                ];
            }
        }
        return $problems;
    }

    public static function isAllowlisted(string $file): bool
    {
        foreach (self::LEGACY_REFERENCE_ALLOWLIST as $allowed) {
            if ($file === $allowed || strncmp($file, $allowed, strlen($allowed)) === 0) {
                return true;
            }
        }
        return false;
    }

    // -----------------------------------------------------------------
    // Index generation
    // -----------------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $adrs
     * @return array<int, array<string, mixed>>
     */
    public static function sortAdrs(array $adrs): array
    {
        $sorted = $adrs;
        usort($sorted, function (array $a, array $b): int {
            return $a['number'] <=> $b['number'] ?: strcmp($a['sequence'], $b['sequence']);
        });
        return $sorted;
    }

    /**
     * @param array<int, array<string, mixed>> $changes
     * @return array<int, array<string, mixed>>
     */
    public static function sortChanges(array $changes): array
    {
        $sorted = $changes;
        usort($sorted, function (array $a, array $b): int {
            return $a['number'] <=> $b['number'] ?: strcmp($a['slug'], $b['slug']);
        });
        return $sorted;
    }

    /**
     * GitHub resolves `/issues/<n>` for a pull request too, so one link shape
     * works whether the tracking number is an issue or a PR. Issues are
     * disabled on this repository, so in practice every number is a PR and the
     * link redirects to `/pull/<n>`.
     */
    public static function trackingLink(int $number): string
    {
        return '[#' . $number . '](https://github.com/' . self::REPOSITORY . '/issues/' . $number . ')';
    }

    /**
     * @param array<int, array<string, mixed>> $adrs
     */
    public static function renderAdrIndex(array $adrs): string
    {
        $sorted = self::sortAdrs($adrs);
        $lines = [
            self::GENERATED_BANNER,
            '',
            '# ADR Index',
            '',
            'Architecture Decision Records for `' . self::REPOSITORY . '`, ordered by tracking',
            'number and then by local sequence. See ' . self::ADR_DIR . '/README.md for the policy.',
            '',
            '| ID | Title | Status | Tracking | Date |',
            '|---|---|---|---|---|',
        ];

        foreach ($sorted as $adr) {
            $lines[] = '| [' . $adr['id'] . '](' . $adr['file'] . ') | ' . $adr['title']
                . ' | ' . $adr['status'] . ' | ' . self::trackingLink($adr['number'])
                . ' | ' . $adr['date'] . ' |';
        }

        foreach (self::ADR_STATUSES as $status) {
            $lines[] = '';
            $lines[] = '## ' . $status;
            $lines[] = '';
            $group = array_filter($sorted, function (array $adr) use ($status): bool {
                return $adr['status'] === $status;
            });
            if ($group === []) {
                $lines[] = '_No ' . strtolower($status) . ' ADRs._';
                continue;
            }
            foreach ($group as $adr) {
                $supersession = $adr['supersededBy'] === []
                    ? ''
                    : ' -- superseded by ' . implode(', ', $adr['supersededBy']);
                $lines[] = '- [' . $adr['id'] . '](' . $adr['file'] . ') -- '
                    . $adr['title'] . $supersession;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<int, array<string, mixed>> $changes
     */
    public static function renderChangeIndex(array $changes): string
    {
        $sorted = self::sortChanges($changes);
        $lines = [
            self::GENERATED_BANNER,
            '',
            '# Change Index',
            '',
            'Change proposals, specifications and designs for `' . self::REPOSITORY . '`,',
            'ordered by tracking number. Each change lives in its own directory named',
            '`<tracking-number>-<change-slug>`. See ' . self::CHANGES_DIR . '/README.md',
            'for the policy.',
            '',
            '| Change | Title | Status | Tracking | Date | Documents |',
            '|---|---|---|---|---|---|',
        ];

        foreach ($sorted as $change) {
            $docs = [];
            foreach ($change['documents'] as $doc) {
                $docs[] = '[' . substr($doc['name'], 0, -3) . '](' . $change['name'] . '/' . $doc['name'] . ')';
            }
            $lines[] = '| `' . $change['name'] . '` | ' . $change['title'] . ' | ' . $change['status']
                . ' | ' . self::trackingLink($change['number']) . ' | ' . $change['date']
                . ' | ' . implode(', ', $docs) . ' |';
        }

        if ($sorted === []) {
            $lines[] = '| _None yet_ | | | | | |';
        }

        foreach (self::CHANGE_STATUSES as $status) {
            $lines[] = '';
            $lines[] = '## ' . $status;
            $lines[] = '';
            $group = array_filter($sorted, function (array $change) use ($status): bool {
                return $change['status'] === $status;
            });
            if ($group === []) {
                $lines[] = '_No ' . $status . ' changes._';
                continue;
            }
            foreach ($group as $change) {
                $adrs = $change['relatedAdrs'] === []
                    ? ''
                    : ' -- ' . implode(', ', $change['relatedAdrs']);
                $lines[] = '- [`' . $change['name'] . '`](' . $change['name'] . '/'
                    . $change['documents'][0]['name'] . ') -- ' . $change['title'] . $adrs;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    // -----------------------------------------------------------------
    // CLI
    // -----------------------------------------------------------------

    /**
     * Tracked files plus not-yet-added ones, honouring .gitignore. Including
     * untracked files matters: otherwise a brand-new file passes `check`
     * locally and only fails in CI, once it has been committed.
     *
     * @return array<int, string>
     */
    public static function trackedFiles(string $root): array
    {
        $command = 'git -C ' . escapeshellarg($root)
            . ' ls-files --cached --others --exclude-standard 2>/dev/null';
        $output = [];
        $status = 0;
        @exec($command, $output, $status);
        if ($status !== 0) {
            return [];
        }
        return array_values(array_unique(array_filter($output, 'strlen')));
    }

    /**
     * @return resource
     */
    private static function errorStream()
    {
        return self::$errorStream === null ? STDERR : self::$errorStream;
    }

    /**
     * @param array<int, array{file: string, message: string}> $problems
     */
    public static function report(string $title, array $problems): void
    {
        if ($problems === []) {
            return;
        }
        $stream = self::errorStream();
        fwrite($stream, "\n" . $title . "\n");
        foreach ($problems as $problem) {
            fwrite($stream, '  x ' . $problem['file'] . ': ' . $problem['message'] . "\n");
        }
    }

    /**
     * @return int process exit code
     */
    public static function run(string $mode, string $root): int
    {
        $discoveredAdrs = self::discoverAdrs($root);
        $discoveredChanges = self::discoverChanges($root);
        $adrs = $discoveredAdrs['adrs'];
        $changes = $discoveredChanges['changes'];
        $structural = array_merge($discoveredAdrs['errors'], $discoveredChanges['errors']);

        if ($mode === 'list') {
            self::report('Structural problems:', $structural);
            if ($structural !== []) {
                fwrite(self::errorStream(), "\nRefusing to list records while structural problems remain.\n");
                return 1;
            }
            echo self::renderAdrIndex($adrs), "\n";
            echo self::renderChangeIndex($changes), "\n";
            return 0;
        }

        $files = self::trackedFiles($root);
        $metadata = self::validate($adrs, $changes);
        $legacy = self::findLegacyReferences($root, $files);
        $committedIndexes = self::findCommittedIndexes($files);

        self::report('Structural problems:', $structural);
        self::report('Metadata problems:', $metadata);
        self::report('Retired identifier references:', $legacy);
        self::report('Committed index:', $committedIndexes);

        $total = count($structural) + count($metadata) + count($legacy) + count($committedIndexes);
        if ($total === 0) {
            echo 'Architecture records OK -- ' . count($adrs) . ' ADRs, '
                . count($changes) . " changes.\n";
            return 0;
        }
        fwrite(self::errorStream(), "\n" . $total . " problem(s) found.\n");
        return 1;
    }
}
