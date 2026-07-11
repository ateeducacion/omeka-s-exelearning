<?php
declare(strict_types=1);

namespace ExeLearning\Controller;

use Laminas\Validator\Csrf as CsrfValidator;

/**
 * Long-lived CSRF token for the editor preview management API (serving
 * contract v2, managementHeaders['X-CSRF-Token']).
 *
 * The editor creates ONE preview session at bootstrap and then issues MANY
 * preview publish POSTs across the whole editing session. Laminas' default form
 * CSRF token (Laminas\Form\Element\Csrf, as used by the save endpoint) is
 * unsuitable for that lifecycle:
 *
 *  - The token is NOT single-use — Laminas\Validator\Csrf::isValid() is a pure
 *    comparison against the session token list; it never consumes or rotates
 *    the token — so a single token can validate unlimited publishes.
 *  - BUT initCsrfToken() stamps an ABSOLUTE, container-GLOBAL
 *    `EXPIRE = time() + timeout` (default timeout 300s) on the validator's
 *    session namespace and never refreshes it on validation. So a token minted
 *    at editor load stops validating ~5 minutes later and every later publish
 *    403s. Because that EXPIRE is container-global, any co-namespaced token with
 *    a finite timeout (e.g. the save form's, also named "csrf") would wipe the
 *    whole namespace when it lapses.
 *
 * This mints the preview token in its OWN namespace ({@see NAME}) with
 * `timeout => null` (no absolute expiry → survives for the authenticated
 * session), isolating it from the form-token namespace's 5-minute expiry so a
 * long editing session keeps previewing. It is defense-in-depth only: the
 * management API additionally requires a logged-in identity and owner-scoped
 * session access.
 */
final class PreviewCsrf
{
    /** Dedicated Laminas CSRF session namespace for the preview token. */
    public const NAME = 'exelearning-preview';

    /**
     * Mint a session-lifetime preview CSRF token (a `token-tokenId` hash).
     *
     * @codeCoverageIgnore Requires a live session container (admin runtime).
     */
    public static function mint(): string
    {
        return (new CsrfValidator(['name' => self::NAME, 'timeout' => null]))->getHash();
    }
}
