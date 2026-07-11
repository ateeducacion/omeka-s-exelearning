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
