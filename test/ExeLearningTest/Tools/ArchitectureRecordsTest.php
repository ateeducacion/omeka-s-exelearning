<?php

declare(strict_types=1);

namespace ExeLearningTest\Tools;

use ExeLearningTools\ArchitectureRecords;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the architecture record validator.
 *
 * The validator lives under scripts/ rather than src/: it is development
 * tooling, it must not ship inside the module package, and it is deliberately
 * outside the coverage include set so a documentation linter cannot move the
 * MIN_COVERAGE number in either direction. It is still tested like production
 * code -- a gate nobody tests is a gate nobody can trust.
 *
 * This file is on the validator's legacy-reference allowlist, because several
 * of the cases below need retired identifiers as fixtures.
 *
 * @covers \ExeLearningTools\ArchitectureRecords
 */
class ArchitectureRecordsTest extends TestCase
{
    private string $root;

    /** @var resource */
    private $diagnostics;

    /**
     * The validator is deliberately Composer-free -- `make architecture-check`
     * has to work in a checkout where `composer install` has not run -- so it
     * is not on the autoload map and is required explicitly here.
     */
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/scripts/ArchitectureRecords.php';
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/exelearning-adr-' . uniqid();
        mkdir($this->root . '/' . ArchitectureRecords::ADR_DIR, 0755, true);
        mkdir($this->root . '/' . ArchitectureRecords::CHANGES_DIR, 0755, true);

        // Keep deliberately broken fixtures out of the suite's own output, and
        // make the reported diagnostics assertable.
        $this->diagnostics = fopen('php://memory', 'r+');
        ArchitectureRecords::$errorStream = $this->diagnostics;
    }

    protected function tearDown(): void
    {
        ArchitectureRecords::$errorStream = null;
        fclose($this->diagnostics);
        $this->removeDirectory($this->root);
    }

    private function diagnostics(): string
    {
        rewind($this->diagnostics);
        return (string) stream_get_contents($this->diagnostics);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function writeAdr(string $filename, string $contents): void
    {
        file_put_contents($this->root . '/' . ArchitectureRecords::ADR_DIR . '/' . $filename, $contents);
    }

    private function writeChangeDoc(string $dir, string $name, string $contents): void
    {
        $full = $this->root . '/' . ArchitectureRecords::CHANGES_DIR . '/' . $dir;
        if (!is_dir($full)) {
            mkdir($full, 0755, true);
        }
        file_put_contents($full . '/' . $name, $contents);
    }

    /**
     * A well-formed ADR. Overrides are spliced into the frontmatter so each
     * test can break exactly one thing.
     *
     * @param array<string, string> $overrides
     */
    private function adrFixture(array $overrides = []): string
    {
        $fields = array_merge([
            'id' => 'ADR-28-01',
            'title' => '"A decision"',
            'status' => 'Accepted',
            'date' => '2026-07-24',
            'tracking_issue' => '28',
        ], $overrides);

        $lines = ['---'];
        foreach ($fields as $key => $value) {
            $lines[] = $key . ': ' . $value;
        }
        $lines[] = 'related:';
        $lines[] = '  issues: []';
        $lines[] = '  prs: []';
        $lines[] = '  changes: []';
        $lines[] = '  adrs: []';
        $lines[] = 'ai_assistance:';
        $lines[] = '  tool: "Claude Code"';
        $lines[] = '  model: "claude-opus-5"';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '# ' . $fields['id'] . ': ' . trim($fields['title'], '"');
        $lines[] = '';
        $lines[] = 'Body.';

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array<int, string>
     */
    private function messages(array $problems): array
    {
        return array_map(
            function (array $problem): string {
                return $problem['file'] . ': ' . $problem['message'];
            },
            $problems
        );
    }

    private function assertNoProblemMatching(string $needle, array $problems): void
    {
        $this->assertStringNotContainsString($needle, implode(' | ', $this->messages($problems)));
    }

    private function assertProblemMatching(string $needle, array $problems): void
    {
        $messages = $this->messages($problems);
        foreach ($messages as $message) {
            if (strpos($message, $needle) !== false) {
                $this->assertTrue(true);
                return;
            }
        }
        $this->fail('No problem matching "' . $needle . '". Got: ' . implode(' | ', $messages));
    }

    // -----------------------------------------------------------------
    // Frontmatter parsing
    // -----------------------------------------------------------------

    public function testParseFrontmatterReturnsNullWithoutFrontmatter(): void
    {
        $this->assertNull(ArchitectureRecords::parseFrontmatter("# Just a heading\n"));
    }

    public function testParseFrontmatterReadsScalarsInlineListsAndBody(): void
    {
        $parsed = ArchitectureRecords::parseFrontmatter(
            "---\nid: ADR-28-01\ntitle: \"A decision\"\nsupersedes: []\n"
            . "superseded_by: [ADR-32-01, ADR-33-01]\n---\n\n# Heading\n"
        );

        $this->assertNotNull($parsed);
        $this->assertSame('ADR-28-01', $parsed['data']['id']);
        $this->assertSame('A decision', $parsed['data']['title']);
        $this->assertSame([], $parsed['data']['supersedes']);
        $this->assertSame(['ADR-32-01', 'ADR-33-01'], $parsed['data']['superseded_by']);
        $this->assertSame("\n# Heading\n", $parsed['body']);
    }

    public function testParseFrontmatterReadsTopLevelBlockListsAndSkipsComments(): void
    {
        $parsed = ArchitectureRecords::parseFrontmatter(
            "---\n# a comment\nauthors:\n  - \"@one\"\n  - '@two'\n\nstatus: Accepted\n---\nbody\n"
        );

        $this->assertNotNull($parsed);
        $this->assertSame(['@one', '@two'], $parsed['data']['authors']);
        $this->assertSame('Accepted', $parsed['data']['status']);
    }

    /**
     * The upstream TypeScript parser loses a block list nested inside a mapping:
     * the list items clear the map it was building, so `related.prs` vanishes
     * and any later sibling key clears the list in turn. ADR-28-01 has exactly
     * that shape, so this port tracks indentation instead.
     */
    public function testParseFrontmatterReadsBlockListsNestedInsideAMapping(): void
    {
        $parsed = ArchitectureRecords::parseFrontmatter(
            "---\nrelated:\n  issues: []\n  prs:\n"
            . "    - https://github.com/exelearning/wp-exelearning/pull/72\n"
            . "    - exelearning/exelearning#2232\n"
            . "  changes: []\n  adrs:\n    - ADR-28-01\n---\nbody\n"
        );

        $this->assertNotNull($parsed);
        $this->assertSame([], ArchitectureRecords::nested($parsed['data'], 'related', 'issues'));
        $this->assertSame(
            [
                'https://github.com/exelearning/wp-exelearning/pull/72',
                'exelearning/exelearning#2232',
            ],
            ArchitectureRecords::nested($parsed['data'], 'related', 'prs')
        );
        $this->assertSame([], ArchitectureRecords::nested($parsed['data'], 'related', 'changes'));
        $this->assertSame(['ADR-28-01'], ArchitectureRecords::nested($parsed['data'], 'related', 'adrs'));
    }

    public function testParseFrontmatterHandlesCarriageReturns(): void
    {
        $parsed = ArchitectureRecords::parseFrontmatter("---\r\nid: ADR-28-01\r\n---\r\n\r\n# H\r\n");

        $this->assertNotNull($parsed);
        $this->assertSame('ADR-28-01', $parsed['data']['id']);
    }

    public function testNestedReturnsNullForAMissingKey(): void
    {
        $this->assertNull(ArchitectureRecords::nested(['a' => ['b' => 'c']], 'a', 'z'));
        $this->assertNull(ArchitectureRecords::nested(['a' => 'scalar'], 'a', 'b'));
    }

    public function testAsListAndAsStringCoerceTheBoundedSchema(): void
    {
        $this->assertSame([], ArchitectureRecords::asList(null));
        $this->assertSame([], ArchitectureRecords::asList(''));
        $this->assertSame(['x'], ArchitectureRecords::asList('x'));
        $this->assertSame(['a', 'b'], ArchitectureRecords::asList(['a', 'b']));
        // A mapping is not a list.
        $this->assertSame([], ArchitectureRecords::asList(['tool' => 'Claude Code']));

        $this->assertSame('', ArchitectureRecords::asString(null));
        $this->assertSame('', ArchitectureRecords::asString(['a']));
        $this->assertSame('28', ArchitectureRecords::asString('28'));
    }

    // -----------------------------------------------------------------
    // Scalar helpers
    // -----------------------------------------------------------------

    public function testIsValidDateRejectsImpossibleDates(): void
    {
        $this->assertTrue(ArchitectureRecords::isValidDate('2026-07-24'));
        $this->assertTrue(ArchitectureRecords::isValidDate('2024-02-29'));
        $this->assertFalse(ArchitectureRecords::isValidDate('2026-02-30'));
        $this->assertFalse(ArchitectureRecords::isValidDate('2026-13-01'));
        $this->assertFalse(ArchitectureRecords::isValidDate('24-07-2026'));
        $this->assertFalse(ArchitectureRecords::isValidDate(''));
    }

    public function testIsPositiveIntegerRejectsZeroPaddingAndZero(): void
    {
        $this->assertTrue(ArchitectureRecords::isPositiveInteger('28'));
        $this->assertFalse(ArchitectureRecords::isPositiveInteger('0'));
        $this->assertFalse(ArchitectureRecords::isPositiveInteger('028'));
        $this->assertFalse(ArchitectureRecords::isPositiveInteger('#28'));
        $this->assertFalse(ArchitectureRecords::isPositiveInteger(''));
    }

    /**
     * Cross-repository references are first class here: issues are disabled on
     * this repository and much of the module's traceability points at sibling
     * repositories.
     */
    public function testIsReferenceAcceptsLocalNumbersAndCrossRepositoryForms(): void
    {
        $this->assertTrue(ArchitectureRecords::isReference('28'));
        $this->assertTrue(ArchitectureRecords::isReference('exelearning/wp-exelearning#72'));
        $this->assertTrue(
            ArchitectureRecords::isReference('https://github.com/exelearning/moodle-mod_exelearning/pull/106')
        );
        $this->assertTrue(
            ArchitectureRecords::isReference('https://github.com/exelearning/exelearning/issues/2232')
        );

        $this->assertFalse(ArchitectureRecords::isReference('#72'));
        $this->assertFalse(ArchitectureRecords::isReference('PR 72'));
        $this->assertFalse(ArchitectureRecords::isReference('http://github.com/a/b/pull/1'));
        $this->assertFalse(ArchitectureRecords::isReference('https://gitlab.com/a/b/pull/1'));
    }

    // -----------------------------------------------------------------
    // ADR discovery
    // -----------------------------------------------------------------

    public function testDiscoverAdrsSkipsPolicyFilesAndReadsRecords(): void
    {
        $this->writeAdr('README.md', "not a record\n");
        $this->writeAdr('template.md', "not a record\n");
        $this->writeAdr('ADR-28-01-a-decision.md', $this->adrFixture());

        $result = ArchitectureRecords::discoverAdrs($this->root);

        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['adrs']);
        $this->assertSame('ADR-28-01', $result['adrs'][0]['id']);
        $this->assertSame(28, $result['adrs'][0]['number']);
        $this->assertSame('01', $result['adrs'][0]['sequence']);
        $this->assertSame('ADR-28-01: A decision', $result['adrs'][0]['h1']);
    }

    public function testDiscoverAdrsNamesTheRetiredGrammarExplicitly(): void
    {
        $this->writeAdr('ADR-0001-bundle-editor.md', $this->adrFixture());

        $result = ArchitectureRecords::discoverAdrs($this->root);

        $this->assertCount(0, $result['adrs']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('retired global numbering', $result['errors'][0]['message']);
    }

    public function testDiscoverAdrsRejectsAnUnparseableFilename(): void
    {
        $this->writeAdr('ADR-28-1-one-digit-sequence.md', $this->adrFixture());

        $result = ArchitectureRecords::discoverAdrs($this->root);

        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('does not match', $result['errors'][0]['message']);
        $this->assertStringNotContainsString('retired', $result['errors'][0]['message']);
    }

    public function testDiscoverAdrsRejectsAnUppercaseSlug(): void
    {
        $this->writeAdr('ADR-28-01-A-Decision.md', $this->adrFixture());

        $result = ArchitectureRecords::discoverAdrs($this->root);

        $this->assertCount(1, $result['errors']);
    }

    public function testDiscoverAdrsReportsMissingFrontmatter(): void
    {
        $this->writeAdr('ADR-28-01-a-decision.md', "# ADR-28-01: A decision\n");

        $result = ArchitectureRecords::discoverAdrs($this->root);

        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('missing YAML frontmatter', $result['errors'][0]['message']);
    }

    public function testDiscoverAdrsReturnsNothingWhenTheDirectoryIsAbsent(): void
    {
        $result = ArchitectureRecords::discoverAdrs($this->root . '/nowhere');

        $this->assertSame([], $result['adrs']);
        $this->assertSame([], $result['errors']);
    }

    // -----------------------------------------------------------------
    // ADR validation
    // -----------------------------------------------------------------

    public function testValidateAcceptsAWellFormedRecord(): void
    {
        $this->writeAdr('ADR-28-01-a-decision.md', $this->adrFixture());
        $result = ArchitectureRecords::discoverAdrs($this->root);

        $this->assertSame([], ArchitectureRecords::validate($result['adrs'], []));
    }

    public function testValidateRejectsAnIdThatDisagreesWithTheFilename(): void
    {
        $this->writeAdr('ADR-28-01-a-decision.md', $this->adrFixture(['id' => 'ADR-28-02']));
        $result = ArchitectureRecords::discoverAdrs($this->root);

        $this->assertProblemMatching(
            'frontmatter id "ADR-28-02" does not match filename',
            ArchitectureRecords::validate($result['adrs'], [])
        );
    }

    public function testValidateRejectsATrackingNumberThatDisagreesWithTheFilename(): void
    {
        $this->writeAdr('ADR-28-01-a-decision.md', $this->adrFixture(['tracking_issue' => '32']));
        $result = ArchitectureRecords::discoverAdrs($this->root);

        $this->assertProblemMatching(
            'tracking_issue 32 does not match the filename tracking number 28',
            ArchitectureRecords::validate($result['adrs'], [])
        );
    }

    public function testValidateRejectsANonNumericTrackingNumber(): void
    {
        $this->writeAdr('ADR-28-01-a-decision.md', $this->adrFixture(['tracking_issue' => '"#28"']));
        $result = ArchitectureRecords::discoverAdrs($this->root);

        $this->assertProblemMatching(
            'tracking_issue "#28" is not a positive integer',
            ArchitectureRecords::validate($result['adrs'], [])
        );
    }

    public function testValidateRejectsAnH1ThatDoesNotMirrorIdAndTitle(): void
    {
        $body = str_replace(
            '# ADR-28-01: A decision',
            '# Bundle the editor',
            $this->adrFixture()
        );
        $this->writeAdr('ADR-28-01-a-decision.md', $body);
        $result = ArchitectureRecords::discoverAdrs($this->root);

        $this->assertProblemMatching(
            'H1 is "Bundle the editor" but should be "ADR-28-01: A decision"',
            ArchitectureRecords::validate($result['adrs'], [])
        );
    }

    public function testValidateRejectsAnUnknownStatusAndAnImpossibleDate(): void
    {
        $this->writeAdr(
            'ADR-28-01-a-decision.md',
            $this->adrFixture(['status' => 'Agreed', 'date' => '2026-02-30'])
        );
        $result = ArchitectureRecords::discoverAdrs($this->root);
        $problems = ArchitectureRecords::validate($result['adrs'], []);

        $this->assertProblemMatching('status "Agreed" is not one of', $problems);
        $this->assertProblemMatching('date "2026-02-30" is not a valid', $problems);
    }

    public function testValidateRequiresAiAssistanceProvenance(): void
    {
        $withoutProvenance = "---\nid: ADR-28-01\ntitle: \"A decision\"\nstatus: Accepted\n"
            . "date: 2026-07-24\ntracking_issue: 28\n---\n\n# ADR-28-01: A decision\n";
        $this->writeAdr('ADR-28-01-a-decision.md', $withoutProvenance);
        $result = ArchitectureRecords::discoverAdrs($this->root);
        $problems = ArchitectureRecords::validate($result['adrs'], []);

        $this->assertProblemMatching('missing required field `ai_assistance.tool`', $problems);
        $this->assertProblemMatching('missing required field `ai_assistance.model`', $problems);
    }

    /**
     * This repository's frontmatter records tools and links, never people's
     * names, so the upstream `deciders` requirement is deliberately absent.
     */
    public function testValidateDoesNotRequireDecidersOrReviewers(): void
    {
        $this->writeAdr('ADR-28-01-a-decision.md', $this->adrFixture());
        $result = ArchitectureRecords::discoverAdrs($this->root);
        $problems = ArchitectureRecords::validate($result['adrs'], []);

        $this->assertNoProblemMatching('deciders', $problems);
        $this->assertNoProblemMatching('reviewers', $problems);
    }

    public function testValidateRejectsTwoRecordsClaimingTheSameLocalSequence(): void
    {
        $this->writeAdr('ADR-28-01-one-decision.md', $this->adrFixture());
        $this->writeAdr('ADR-28-01-another-decision.md', $this->adrFixture());
        $result = ArchitectureRecords::discoverAdrs($this->root);
        $problems = ArchitectureRecords::validate($result['adrs'], []);

        $this->assertProblemMatching('duplicate ADR id "ADR-28-01"', $problems);
        $this->assertProblemMatching('duplicate local sequence 01 for tracking number 28', $problems);
    }

    public function testValidateAllowsSeveralDecisionsUnderOneTrackingNumber(): void
    {
        $this->writeAdr('ADR-28-01-one-decision.md', $this->adrFixture());
        $this->writeAdr(
            'ADR-28-02-another-decision.md',
            $this->adrFixture(['id' => 'ADR-28-02', 'title' => '"Another decision"'])
        );
        $result = ArchitectureRecords::discoverAdrs($this->root);

        $this->assertSame([], ArchitectureRecords::validate($result['adrs'], []));
    }

    public function testValidateRejectsTheRetiredRelatedSddsKey(): void
    {
        $withSdds = str_replace('  changes: []', '  sdds: []', $this->adrFixture());
        $this->writeAdr('ADR-28-01-a-decision.md', $withSdds);
        $result = ArchitectureRecords::discoverAdrs($this->root);

        $this->assertProblemMatching(
            'related.sdds is retired; use related.changes instead',
            ArchitectureRecords::validate($result['adrs'], [])
        );
    }

    public function testValidateRejectsDanglingCrossReferences(): void
    {
        $withRefs = str_replace(
            ['  changes: []', '  adrs: []'],
            ["  changes:\n    - 99-nope", "  adrs:\n    - ADR-99-01"],
            $this->adrFixture()
        );
        $this->writeAdr('ADR-28-01-a-decision.md', $withRefs);
        $result = ArchitectureRecords::discoverAdrs($this->root);
        $problems = ArchitectureRecords::validate($result['adrs'], []);

        $this->assertProblemMatching('related.adrs references unknown ADR "ADR-99-01"', $problems);
        $this->assertProblemMatching('related.changes references unknown change "99-nope"', $problems);
    }

    public function testValidateRejectsAMalformedTraceabilityReference(): void
    {
        $withRefs = str_replace(
            ['  prs: []', '  issues: []'],
            ["  prs:\n    - see the PR", "  issues:\n    - #2232"],
            $this->adrFixture()
        );
        $this->writeAdr('ADR-28-01-a-decision.md', $withRefs);
        $result = ArchitectureRecords::discoverAdrs($this->root);
        $problems = ArchitectureRecords::validate($result['adrs'], []);

        $this->assertProblemMatching('related.prs value "see the PR"', $problems);
        $this->assertProblemMatching('related.issues value "#2232"', $problems);
    }

    public function testValidateAcceptsCrossRepositoryTraceability(): void
    {
        $withRefs = str_replace(
            '  prs: []',
            "  prs:\n    - https://github.com/exelearning/wp-exelearning/pull/72\n    - 28",
            $this->adrFixture()
        );
        $this->writeAdr('ADR-28-01-a-decision.md', $withRefs);
        $result = ArchitectureRecords::discoverAdrs($this->root);

        $this->assertSame([], ArchitectureRecords::validate($result['adrs'], []));
    }

    // -----------------------------------------------------------------
    // Supersession
    // -----------------------------------------------------------------

    public function testValidateAcceptsASymmetricSupersession(): void
    {
        $this->writeAdr(
            'ADR-28-01-old-decision.md',
            str_replace(
                'status: Accepted',
                "status: Superseded\nsuperseded_by: [ADR-32-01]",
                $this->adrFixture()
            )
        );
        $this->writeAdr(
            'ADR-32-01-new-decision.md',
            str_replace(
                'status: Accepted',
                "status: Accepted\nsupersedes: [ADR-28-01]",
                $this->adrFixture([
                    'id' => 'ADR-32-01',
                    'title' => '"A newer decision"',
                    'tracking_issue' => '32',
                ])
            )
        );
        $result = ArchitectureRecords::discoverAdrs($this->root);

        $this->assertSame([], ArchitectureRecords::validate($result['adrs'], []));
    }

    public function testValidateRejectsAOneSidedSupersession(): void
    {
        $this->writeAdr('ADR-28-01-old-decision.md', $this->adrFixture());
        $this->writeAdr(
            'ADR-32-01-new-decision.md',
            str_replace(
                'status: Accepted',
                "status: Accepted\nsupersedes: [ADR-28-01]",
                $this->adrFixture([
                    'id' => 'ADR-32-01',
                    'title' => '"A newer decision"',
                    'tracking_issue' => '32',
                ])
            )
        );
        $result = ArchitectureRecords::discoverAdrs($this->root);
        $problems = ArchitectureRecords::validate($result['adrs'], []);

        $this->assertProblemMatching('does not list superseded_by: [ADR-32-01]', $problems);
        $this->assertProblemMatching('but status is "Accepted", not "Superseded"', $problems);
    }

    public function testValidateRejectsAnAsymmetricSupersededByAndSelfReference(): void
    {
        $this->writeAdr(
            'ADR-28-01-old-decision.md',
            str_replace(
                'status: Accepted',
                "status: Superseded\nsuperseded_by: [ADR-32-01]\nsupersedes: [ADR-28-01]",
                $this->adrFixture()
            )
        );
        $this->writeAdr(
            'ADR-32-01-new-decision.md',
            $this->adrFixture([
                'id' => 'ADR-32-01',
                'title' => '"A newer decision"',
                'tracking_issue' => '32',
            ])
        );
        $result = ArchitectureRecords::discoverAdrs($this->root);
        $problems = ArchitectureRecords::validate($result['adrs'], []);

        $this->assertProblemMatching('an ADR cannot supersede itself', $problems);
        $this->assertProblemMatching('does not list supersedes: [ADR-28-01]', $problems);
    }

    public function testValidateRejectsSupersessionOfAnUnknownRecord(): void
    {
        $this->writeAdr(
            'ADR-28-01-a-decision.md',
            str_replace(
                'status: Accepted',
                "status: Accepted\nsupersedes: [ADR-99-01]\nsuperseded_by: [ADR-98-01]",
                $this->adrFixture()
            )
        );
        $result = ArchitectureRecords::discoverAdrs($this->root);
        $problems = ArchitectureRecords::validate($result['adrs'], []);

        $this->assertProblemMatching('supersedes references unknown ADR "ADR-99-01"', $problems);
        $this->assertProblemMatching('superseded_by references unknown ADR "ADR-98-01"', $problems);
    }

    // -----------------------------------------------------------------
    // Change directories
    // -----------------------------------------------------------------

    private function changeDoc(string $number, string $extra = ''): string
    {
        return "---\ntracking_issue: " . $number . "\ntitle: \"A change\"\nstatus: draft\n"
            . "date: 2026-08-05\n" . $extra . "---\n\n# A change\n";
    }

    public function testDiscoverChangesReadsADirectoryAndPicksTheCanonicalDocument(): void
    {
        $this->writeChangeDoc('41-elpx-upload-validation', 'design.md', $this->changeDoc('41'));
        $this->writeChangeDoc('41-elpx-upload-validation', 'proposal.md', $this->changeDoc('41'));

        $result = ArchitectureRecords::discoverChanges($this->root);

        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['changes']);
        $this->assertSame(41, $result['changes'][0]['number']);
        $this->assertSame('elpx-upload-validation', $result['changes'][0]['slug']);
        // proposal.md wins regardless of alphabetical order on disk.
        $this->assertSame('proposal.md', $result['changes'][0]['canonical']['name']);
        $this->assertCount(2, $result['changes'][0]['documents']);
    }

    public function testDiscoverChangesRejectsADirectoryWithoutATrackingNumber(): void
    {
        $this->writeChangeDoc('elpx-upload-validation', 'proposal.md', $this->changeDoc('41'));

        $result = ArchitectureRecords::discoverChanges($this->root);

        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('<tracking-number>-<change-slug>', $result['errors'][0]['message']);
    }

    public function testDiscoverChangesRejectsADirectoryWithNoRecognisedDocument(): void
    {
        $full = $this->root . '/' . ArchitectureRecords::CHANGES_DIR . '/41-elpx-upload-validation';
        mkdir($full, 0755, true);
        file_put_contents($full . '/notes.md', "stray\n");

        $result = ArchitectureRecords::discoverChanges($this->root);

        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('contains no recognised document', $result['errors'][0]['message']);
    }

    public function testValidateRejectsAChangeDocumentFiledUnderTheWrongNumber(): void
    {
        $this->writeChangeDoc('41-elpx-upload-validation', 'proposal.md', $this->changeDoc('41'));
        $this->writeChangeDoc('41-elpx-upload-validation', 'design.md', $this->changeDoc('42'));

        $result = ArchitectureRecords::discoverChanges($this->root);
        $problems = ArchitectureRecords::validate([], $result['changes']);

        $this->assertProblemMatching(
            'tracking_issue 42 does not match the change directory tracking number 41',
            $problems
        );
    }

    public function testValidateRejectsASecondCarrierOfImplementationPrs(): void
    {
        $withPrs = $this->changeDoc('41', "implementation_prs: [41]\n");
        $this->writeChangeDoc('41-elpx-upload-validation', 'proposal.md', $withPrs);
        $this->writeChangeDoc('41-elpx-upload-validation', 'design.md', $withPrs);

        $result = ArchitectureRecords::discoverChanges($this->root);
        $problems = ArchitectureRecords::validate([], $result['changes']);

        $this->assertProblemMatching(
            'declares implementation_prs, but proposal.md is the canonical metadata carrier',
            $problems
        );
    }

    public function testValidateRejectsAnUnknownChangeStatusAndDanglingAdr(): void
    {
        $this->writeChangeDoc(
            '41-elpx-upload-validation',
            'proposal.md',
            "---\ntracking_issue: 41\ntitle: \"A change\"\nstatus: Draft\ndate: 2026-08-05\n"
            . "related_adrs: [ADR-99-01]\n---\n\n# A change\n"
        );

        $result = ArchitectureRecords::discoverChanges($this->root);
        $problems = ArchitectureRecords::validate([], $result['changes']);

        // Change statuses are lowercase; `Draft` is the retired capitalised form.
        $this->assertProblemMatching('status "Draft" is not one of', $problems);
        $this->assertProblemMatching('related_adrs references unknown ADR "ADR-99-01"', $problems);
    }

    // -----------------------------------------------------------------
    // Retired identifiers and committed indexes
    // -----------------------------------------------------------------

    public function testFindLegacyReferencesFlagsARetiredIdentifierWithItsLineNumber(): void
    {
        file_put_contents($this->root . '/Module.php', "<?php\n// ok\n// see ADR-0001 for why\n");

        $problems = ArchitectureRecords::findLegacyReferences($this->root, ['Module.php']);

        $this->assertCount(1, $problems);
        $this->assertSame('Module.php:3', $problems[0]['file']);
        $this->assertStringContainsString('retired identifier "ADR-0001"', $problems[0]['message']);
    }

    /**
     * `ADR-1858-01` starts with four digits too. Without the lookahead in the
     * detector, every current identifier would be flagged as retired.
     */
    public function testFindLegacyReferencesDoesNotFlagACurrentIdentifier(): void
    {
        file_put_contents($this->root . '/Module.php', "<?php\n// see ADR-1858-01 and ADR-28-01\n");

        $this->assertSame([], ArchitectureRecords::findLegacyReferences($this->root, ['Module.php']));
    }

    public function testFindLegacyReferencesAllowsARecordToNameItsOwnLegacyId(): void
    {
        $record = "---\nid: ADR-28-01\nlegacy_id: ADR-0001\n---\n\n"
            . "# ADR-28-01: A decision\n\nFormerly ADR-0001.\n";
        file_put_contents($this->root . '/record.md', $record);

        $this->assertSame([], ArchitectureRecords::findLegacyReferences($this->root, ['record.md']));
    }

    public function testFindLegacyReferencesStillFlagsAnotherRecordsLegacyId(): void
    {
        $record = "---\nid: ADR-28-01\nlegacy_id: ADR-0001\n---\n\n"
            . "# ADR-28-01: A decision\n\nSee ADR-0002.\n";
        file_put_contents($this->root . '/record.md', $record);

        $problems = ArchitectureRecords::findLegacyReferences($this->root, ['record.md']);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('"ADR-0002"', $problems[0]['message']);
    }

    public function testFindLegacyReferencesSkipsAllowlistedPathsAndBinaryAndMissingFiles(): void
    {
        file_put_contents($this->root . '/binary.bin', "ADR-0001\0padding");

        $files = [
            ArchitectureRecords::MIGRATION_MAP,
            'binary.bin',
            'does/not/exist.md',
        ];
        file_put_contents($this->root . '/migration.md', "ADR-0001\n");

        $this->assertSame([], ArchitectureRecords::findLegacyReferences($this->root, $files));
    }

    public function testIsAllowlistedCoversTheMigrationMapAndThisTest(): void
    {
        $this->assertTrue(ArchitectureRecords::isAllowlisted(ArchitectureRecords::MIGRATION_MAP));
        $this->assertTrue(
            ArchitectureRecords::isAllowlisted('test/ExeLearningTest/Tools/ArchitectureRecordsTest.php')
        );
        $this->assertFalse(ArchitectureRecords::isAllowlisted('Module.php'));
    }

    public function testFindCommittedIndexesRejectsEitherIndexIncludingTheRetiredPath(): void
    {
        $problems = ArchitectureRecords::findCommittedIndexes([
            'Module.php',
            ArchitectureRecords::ADR_DIR . '/records.md',
            ArchitectureRecords::CHANGES_DIR . '/records.md',
            'docs/architecture/sdd/records.md',
        ]);

        $this->assertCount(3, $problems);
        $this->assertStringContainsString('must not be committed', $problems[0]['message']);
    }

    // -----------------------------------------------------------------
    // Index rendering
    // -----------------------------------------------------------------

    public function testRenderAdrIndexSortsByTrackingNumberThenSequence(): void
    {
        $this->writeAdr(
            'ADR-32-01-later-decision.md',
            $this->adrFixture(['id' => 'ADR-32-01', 'title' => '"Later"', 'tracking_issue' => '32'])
        );
        $this->writeAdr('ADR-28-02-second-decision.md', $this->adrFixture([
            'id' => 'ADR-28-02',
            'title' => '"Second"',
        ]));
        $this->writeAdr('ADR-28-01-first-decision.md', $this->adrFixture([
            'id' => 'ADR-28-01',
            'title' => '"First"',
        ]));

        $adrs = ArchitectureRecords::discoverAdrs($this->root)['adrs'];
        $order = array_column(ArchitectureRecords::sortAdrs($adrs), 'id');

        $this->assertSame(['ADR-28-01', 'ADR-28-02', 'ADR-32-01'], $order);

        $index = ArchitectureRecords::renderAdrIndex($adrs);
        $this->assertStringContainsString('Produced by `make architecture-records`', $index);
        $this->assertStringContainsString('| [ADR-28-01](ADR-28-01-first-decision.md) | First |', $index);
        $this->assertStringContainsString('_No proposed ADRs._', $index);
        $this->assertLessThan(
            strpos($index, 'ADR-32-01-later-decision.md'),
            strpos($index, 'ADR-28-01-first-decision.md')
        );
    }

    public function testRenderAdrIndexNotesSupersession(): void
    {
        $this->writeAdr(
            'ADR-28-01-old-decision.md',
            str_replace(
                'status: Accepted',
                "status: Superseded\nsuperseded_by: [ADR-32-01]",
                $this->adrFixture()
            )
        );

        $index = ArchitectureRecords::renderAdrIndex(ArchitectureRecords::discoverAdrs($this->root)['adrs']);

        $this->assertStringContainsString('superseded by ADR-32-01', $index);
    }

    public function testRenderChangeIndexListsDocumentsAndEmptyStatusGroups(): void
    {
        $this->writeChangeDoc('41-elpx-upload-validation', 'proposal.md', $this->changeDoc('41'));
        $this->writeChangeDoc('41-elpx-upload-validation', 'tasks.md', $this->changeDoc('41'));

        $changes = ArchitectureRecords::discoverChanges($this->root)['changes'];
        $index = ArchitectureRecords::renderChangeIndex($changes);

        $this->assertStringContainsString('`41-elpx-upload-validation`', $index);
        $this->assertStringContainsString('[proposal](41-elpx-upload-validation/proposal.md)', $index);
        $this->assertStringContainsString('[tasks](41-elpx-upload-validation/tasks.md)', $index);
        $this->assertStringContainsString('_No implemented changes._', $index);
    }

    public function testRenderChangeIndexHandlesAnEmptyRepository(): void
    {
        $index = ArchitectureRecords::renderChangeIndex([]);

        $this->assertStringContainsString('_None yet_', $index);
        $this->assertStringContainsString('_No draft changes._', $index);
    }

    public function testTrackingLinkUsesTheIssuesPathSoItResolvesForPullRequestsToo(): void
    {
        $this->assertSame(
            '[#28](https://github.com/exelearning/omeka-s-exelearning/issues/28)',
            ArchitectureRecords::trackingLink(28)
        );
    }

    // -----------------------------------------------------------------
    // The repository's own records
    // -----------------------------------------------------------------

    /**
     * The gate must pass on this repository as it stands. Without this the
     * suite would only ever exercise synthetic fixtures.
     */
    public function testTheRepositorysOwnRecordsPassEveryCheck(): void
    {
        $repository = dirname(__DIR__, 3);

        $adrs = ArchitectureRecords::discoverAdrs($repository);
        $changes = ArchitectureRecords::discoverChanges($repository);

        $this->assertSame([], $adrs['errors']);
        $this->assertSame([], $changes['errors']);
        $this->assertSame([], ArchitectureRecords::validate($adrs['adrs'], $changes['changes']));
        $this->assertNotEmpty($adrs['adrs']);

        $files = ArchitectureRecords::trackedFiles($repository);
        $this->assertNotEmpty($files, 'git ls-files returned nothing');
        $this->assertSame([], ArchitectureRecords::findLegacyReferences($repository, $files));
        $this->assertSame([], ArchitectureRecords::findCommittedIndexes($files));
    }

    public function testRunReportsSuccessOnTheRepositorysOwnRecords(): void
    {
        $repository = dirname(__DIR__, 3);

        ob_start();
        $status = ArchitectureRecords::run('check', $repository);
        $output = (string) ob_get_clean();

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Architecture records OK', $output);
    }

    public function testRunListPrintsBothIndexes(): void
    {
        ob_start();
        $status = ArchitectureRecords::run('list', $this->root);
        $output = (string) ob_get_clean();

        $this->assertSame(0, $status);
        $this->assertStringContainsString('# ADR Index', $output);
        $this->assertStringContainsString('# Change Index', $output);
    }

    public function testRunListRefusesWhileStructuralProblemsRemain(): void
    {
        $this->writeAdr('ADR-0001-bundle-editor.md', $this->adrFixture());

        ob_start();
        $status = ArchitectureRecords::run('list', $this->root);
        ob_end_clean();

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Structural problems:', $this->diagnostics());
        $this->assertStringContainsString('Refusing to list records', $this->diagnostics());
    }

    public function testRunCheckFailsOnABrokenRecord(): void
    {
        $this->writeAdr('ADR-28-01-a-decision.md', $this->adrFixture(['id' => 'ADR-28-02']));

        ob_start();
        $status = ArchitectureRecords::run('check', $this->root);
        ob_end_clean();

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Metadata problems:', $this->diagnostics());
        $this->assertStringContainsString('2 problem(s) found.', $this->diagnostics());
    }

    public function testReportPrintsNothingWhenThereIsNothingToReport(): void
    {
        ArchitectureRecords::report('Structural problems:', []);

        $this->assertSame('', $this->diagnostics());
    }

    public function testTrackedFilesReturnsNothingOutsideAGitRepository(): void
    {
        $this->assertSame([], ArchitectureRecords::trackedFiles($this->root . '/nowhere'));
    }
}
