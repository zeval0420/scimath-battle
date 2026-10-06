<?php

/**
 * Lightweight validation helper. Not a generic rules-engine -- each admin
 * action script calls the specific checks it needs and collects errors into
 * a simple associative array (field => message), which the form templates
 * know how to render next to the relevant input.
 */
class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    public function requiredString(array $data, string $field, string $label, int $maxLength = 255): ?string
    {
        $value = trim((string) ($data[$field] ?? ''));

        if ($value === '') {
            $this->errors[$field] = "{$label} is required.";

            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            $this->errors[$field] = "{$label} must be {$maxLength} characters or fewer.";

            return null;
        }

        return $value;
    }

    public function optionalString(array $data, string $field, string $label, int $maxLength = 255): ?string
    {
        $value = trim((string) ($data[$field] ?? ''));

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            $this->errors[$field] = "{$label} must be {$maxLength} characters or fewer.";

            return null;
        }

        return $value;
    }

    public function optionalDate(array $data, string $field, string $label): ?string
    {
        $value = trim((string) ($data[$field] ?? ''));

        if ($value === '') {
            return null;
        }

        $d = DateTime::createFromFormat('Y-m-d', $value);
        if (!$d || $d->format('Y-m-d') !== $value) {
            $this->errors[$field] = "{$label} must be a valid date (YYYY-MM-DD).";

            return null;
        }

        return $value;
    }

    /**
     * Validates a required integer within [min, max]. Returns null (and
     * records an error) if invalid.
     */
    public function requiredInt(array $data, string $field, string $label, int $min, int $max): ?int
    {
        $raw = $data[$field] ?? '';

        if ($raw === '' || !is_numeric($raw) || (int) $raw != $raw) {
            $this->errors[$field] = "{$label} must be a whole number.";

            return null;
        }

        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            $this->errors[$field] = "{$label} must be between {$min} and {$max}.";

            return null;
        }

        return $value;
    }

    /**
     * Same as requiredInt but an empty submitted value is treated as "use
     * the event default" (returns null without an error) -- used for
     * question points/time overrides.
     */
    public function optionalIntOrInherit(array $data, string $field, string $label, int $min, int $max): ?int
    {
        $raw = trim((string) ($data[$field] ?? ''));

        if ($raw === '') {
            return null;
        }

        if (!is_numeric($raw) || (int) $raw != $raw) {
            $this->errors[$field] = "{$label} must be a whole number, or left blank to use the event default.";

            return null;
        }

        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            $this->errors[$field] = "{$label} must be between {$min} and {$max}.";

            return null;
        }

        return $value;
    }

    public function inSet(array $data, string $field, string $label, array $allowed): ?string
    {
        $value = (string) ($data[$field] ?? '');

        if (!in_array($value, $allowed, true)) {
            $this->errors[$field] = "{$label} must be one of: " . implode(', ', $allowed) . '.';

            return null;
        }

        return $value;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field] = $message;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
