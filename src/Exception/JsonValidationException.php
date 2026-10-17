<?php

declare(strict_types=1);

namespace PhpAiGuard\Exception;

/**
 * Thrown when the model did not return JSON matching the schema, even after
 * one corrective retry.
 */
class JsonValidationException extends AiGuardException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(private array $errors, private string $rawText)
    {
        parent::__construct('The model did not return valid JSON for the schema: ' . implode('; ', array_slice($errors, 0, 5)));
    }

    /** @return list<string> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** The raw text returned by the model on the last attempt. */
    public function getRawText(): string
    {
        return $this->rawText;
    }
}
