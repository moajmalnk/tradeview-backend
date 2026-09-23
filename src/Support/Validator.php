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
                if ($rule === 'email' && !filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field] = 'Invalid email.';
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
}
