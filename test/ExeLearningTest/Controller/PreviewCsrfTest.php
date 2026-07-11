<?php

declare(strict_types=1);

namespace ExeLearningTest\Controller;

use ExeLearning\Controller\PreviewCsrf;
use Laminas\Validator\Csrf as CsrfValidator;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the load-bearing PreviewCsrf lifetime guarantee against the REAL
 * Laminas\Validator\Csrf, driven by a clock-controlled session container
 * ({@see ClockedSessionContainer}) that models Laminas' absolute container-global
 * expiry. Proves the design claim rather than trusting untested internals:
 *
 *  - the preview validator uses its own namespace with NO absolute timeout;
 *  - a preview token survives well past the default 300s form-token TTL and is
 *    NOT single-use;
 *  - the control (default form validator, 300s timeout) DOES expire — which is
 *    exactly why the preview token needs its own timeout=>null namespace.
 *
 * @covers \ExeLearning\Controller\PreviewCsrf
 */
class PreviewCsrfTest extends TestCase
{
    public function testValidatorUsesPreviewNamespaceWithNoTimeout(): void
    {
        $validator = PreviewCsrf::validator();

        $this->assertInstanceOf(CsrfValidator::class, $validator);
        $this->assertSame('exelearning-preview', $validator->getName());
        $this->assertNull($validator->getTimeout(), 'the preview validator must carry no absolute expiry');
        $this->assertSame('exelearning-preview', PreviewCsrf::NAME);
    }

    public function testMintProducesATokenIdHash(): void
    {
        // token-tokenId shape (two md5 halves). Proves mint() runs end-to-end.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}-[0-9a-f]{32}$/', PreviewCsrf::mint());
    }

    public function testPreviewTokenSurvivesBeyondFormTokenTtlAndIsMultiUse(): void
    {
        $container = new ClockedSessionContainer(1000000);

        // Mint via the shared factory, backed by the shared clock container.
        $minter = PreviewCsrf::validator();
        $minter->setSession($container);
        $token = $minter->getHash();

        // Six minutes pass — well past the default 300s form-token TTL.
        $container->advance(360);

        // A fresh validator (as a later request builds) still accepts it…
        $first = PreviewCsrf::validator();
        $first->setSession($container);
        $this->assertTrue(
            $first->isValid($token),
            'the preview token must survive past the 5-minute form-token TTL'
        );

        // …and again — the token is not single-use / not rotated on validation.
        $second = PreviewCsrf::validator();
        $second->setSession($container);
        $this->assertTrue($second->isValid($token), 'the preview token must validate more than once');
    }

    public function testPreviewTokenSurvivesMultipleSequentialRequests(): void
    {
        // Hop dimension: Laminas Csrf may (in some versions) call
        // setExpirationHops(1), which would kill the token after ONE subsequent
        // request regardless of timeout. Simulate several sequential publish
        // requests (hops) and prove the preview token still validates on each.
        $container = new ClockedSessionContainer(1000000);

        $minter = PreviewCsrf::validator();
        $minter->setSession($container);
        $token = $minter->getHash();

        for ($hop = 1; $hop <= 4; $hop++) {
            $container->nextRequest();
            $validator = PreviewCsrf::validator();
            $validator->setSession($container);
            $this->assertTrue(
                $validator->isValid($token),
                "the preview token must still validate at sequential request #$hop"
            );
        }
    }

    public function testPreviewMintArmsNeitherSecondsNorHopExpiry(): void
    {
        // Directly assert the shipped validator arms NO expiry on the preview
        // container — neither seconds (timeout=null) nor hops.
        $container = new ClockedSessionContainer(1000000);
        $minter = PreviewCsrf::validator();
        $minter->setSession($container);
        $minter->getHash();

        $this->assertFalse($container->secondsWereArmed(), 'preview mint must not arm a seconds expiry');
        $this->assertFalse($container->hopsWereArmed(), 'preview mint must not arm a hop expiry');
    }

    public function testClockedContainerModelsHopExpiry(): void
    {
        // Guard against a vacuous hop test: prove the container actually expires
        // on hops, so "survives 4 requests" above is meaningful.
        $container = new ClockedSessionContainer(1000000);
        $container->marker = 'present';
        $container->setExpirationHops(1);

        $container->nextRequest();
        $this->assertSame('present', $container->marker, 'valid within the hop budget');

        $container->nextRequest();
        $this->assertNull($container->marker, 'expired once the hop budget is exceeded');
    }

    public function testDefaultFormTokenExpiresAfterItsTtl(): void
    {
        // Control: the SAME Laminas validator with the default 300s timeout stamps
        // an absolute expiry and stops validating once it elapses.
        $container = new ClockedSessionContainer(1000000);

        $minter = new CsrfValidator(['name' => 'csrf']); // default timeout: 300s
        $minter->setSession($container);
        $token = $minter->getHash();

        $within = new CsrfValidator(['name' => 'csrf']);
        $within->setSession($container);
        $this->assertTrue($within->isValid($token), 'the form token is valid within its TTL');

        $container->advance(360); // past the 300s TTL

        $after = new CsrfValidator(['name' => 'csrf']);
        $after->setSession($container);
        $this->assertFalse($after->isValid($token), 'the default form token must expire after its 300s TTL');
    }
}
