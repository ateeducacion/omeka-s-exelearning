<?php
declare(strict_types=1);

namespace ExeLearning\Controller;

/**
 * Shared, mandatory CSRF validation for the module's state-changing endpoints.
 *
 * A request that omits the token entirely is rejected — a missing token is
 * never valid. This closes the bypass where skipping the field skipped the
 * check.
 */
trait CsrfValidationTrait
{
    /**
     * Validate the CSRF token on a state-changing request.
     *
     * The token is read from the `csrf` POST field, the `X-CSRF-Token` header,
     * or the `csrf` query parameter.
     *
     * @param object $request
     * @return bool
     */
    protected function validateCsrf($request): bool
    {
        $token = method_exists($request, 'getPost') ? $request->getPost('csrf') : null;
        if (!$token && method_exists($request, 'getHeaders')) {
            $header = $request->getHeaders()->get('X-CSRF-Token');
            $token = ($header && $header !== false) ? $header->getFieldValue() : null;
        }
        if (!$token && method_exists($request, 'getQuery')) {
            $token = $request->getQuery('csrf');
        }
        if ($token === null || $token === '') {
            return false;
        }
        return $this->csrfTokenIsValid((string) $token);
    }

    /**
     * Validate a non-empty CSRF token against the session-bound validator.
     *
     * @param string $token
     * @return bool
     *
     * @codeCoverageIgnore
     */
    protected function csrfTokenIsValid(string $token): bool
    {
        return (new \Laminas\Validator\Csrf(['name' => 'csrf']))->isValid($token);
    }
}
