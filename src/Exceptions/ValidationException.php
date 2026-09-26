<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Exceptions;

/**
 * 422: the payload or query was rejected. {@see errors()} maps every field to its
 * messages; `errorMessage()` is the first of them (Laravel adds "(and N more errors)").
 *
 * A 422 can also come WITHOUT field errors, from a check the validator does not run: an
 * Idempotency-Key reused with a different payload, a custom domain that is not pending
 * verification, a limit enforced deep in the domain. `errors()` is then empty and
 * `errorMessage()` says what went wrong. Both are caller mistakes, not something a retry
 * fixes.
 */
class ValidationException extends ApiException
{
    /**
     * The first message for a field, or the first message of any field when none is named.
     */
    public function firstError(?string $field = null): ?string
    {
        $errors = $this->errors();

        if ($field !== null) {
            return $errors[$field][0] ?? null;
        }

        foreach ($errors as $messages) {
            return $messages[0];
        }

        return null;
    }

    public function hasError(string $field): bool
    {
        return isset($this->errors()[$field]);
    }

    /** Whether the envelope named any field at all. */
    public function hasFieldErrors(): bool
    {
        return $this->errors() !== [];
    }
}
