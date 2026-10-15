<?php

namespace App\Exception;

/**
 * Raised when a task cannot be created because its input failed validation.
 * Carries human-readable violation messages for each invalid field.
 */
final class TaskValidationException extends \RuntimeException
{
    /**
     * @param list<string> $violations
     */
    public function __construct(
        private readonly array $violations,
    ) {
        parent::__construct(sprintf('Task validation failed: %s', implode('; ', $violations)));
    }

    /**
     * @return list<string>
     */
    public function getViolations(): array
    {
        return $this->violations;
    }
}
