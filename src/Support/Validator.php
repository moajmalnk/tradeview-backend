<?php

declare(strict_types=1);

namespace App\Support;

final class Validator
{
    /** @param array<string, mixed> $rules field => required|email|string|number|enum:a,b */
    public static function validate(array $data, array $rules): array
    {
        $errors = [];
        $clean = [];

        foreach ($rules as $field => $ruleStr) {
            $parts = explode('|', $ruleStr);
            $value = $data[$field] ?? null;
            $required = in_array('required', $parts, true);

            if ($value === null || $value === '') {
                if ($required) {
                    $errors[$field] = 'Required.';
                }
                continue;
            }

            foreach ($parts as $rule) {
                if ($rule === 'required') {
                    continue;
                }
                if ($rule === 'email' && !self::isEmail((string) $value)) {
                    $errors[$field] = 'Invalid email.';
                }
                if ($rule === 'email_strict' && !self::isStrictEmail((string) $value)) {
                    $errors[$field] = 'invalid email id';
                }
                if ($rule === 'string' && !is_string($value) && !is_numeric($value)) {
                    $errors[$field] = 'Must be a string.';
                }
                if ($rule === 'number' && !is_numeric($value)) {
                    $errors[$field] = 'Must be a number.';
                }
                if (str_starts_with($rule, 'min:')) {
                    $min = (int) substr($rule, 4);
                    if (is_string($value) && strlen($value) < $min) {
                        $errors[$field] = "Min length {$min}.";
                    }
                }
                if (str_starts_with($rule, 'enum:')) {
                    $allowed = explode(',', substr($rule, 5));
                    if (!in_array((string) $value, $allowed, true)) {
                        $errors[$field] = 'Invalid value.';
                    }
                }
            }

            if (!isset($errors[$field])) {
                $clean[$field] = $value;
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors, 'data' => $clean];
    }

    /** Accepts standard emails and simple local-style addresses like trader@tradeview */
    private static function isEmail(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return true;
        }

        return (bool) preg_match('/^[^\s@]+@[^\s@]+$/', $value);
    }

    /** Registration requires a domain with a dot, so hosts like gmail are rejected. */
    private static function isStrictEmail(string $value): bool
    {
        return (bool) preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', strtolower(trim($value)));
    }
}
