<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Db;
use PDO;

abstract class BaseRepository
{
    protected function db(): PDO
    {
        return Db::pdo();
    }

    protected function encodeJson(mixed $value): string
    {
        return json_encode($value ?? new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    protected function decodeJson(?string $value): mixed
    {
        if ($value === null || $value === '') {
            return new \stdClass();
        }
        $decoded = json_decode($value, true);

        return $decoded ?? new \stdClass();
    }

    /** @param array<string, mixed> $row */
    protected function mapJsonFields(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = $this->decodeJson(is_string($row[$field]) ? $row[$field] : null);
            }
        }

        return $row;
    }

    protected function boolInt(mixed $value): int
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }
}
