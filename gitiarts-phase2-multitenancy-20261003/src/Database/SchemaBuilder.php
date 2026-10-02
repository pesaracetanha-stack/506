<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * Copyright (c) 2026 GitiArts. All rights reserved.
 */

namespace GitiArts\Phase2\Database;

/**
 * SchemaBuilder
 *
 * Tiny database-agnostic schema builder. Generates DDL that works on BOTH
 * SQLite and PostgreSQL from a single declarative definition.
 *
 * Type mapping (portable across SQLite 3.x and PostgreSQL 14+):
 *
 *   SchemaBuilder::TYPE_INTEGER  → SQLite INTEGER, PostgreSQL INTEGER
 *   SchemaBuilder::TYPE_TEXT     → SQLite TEXT,    PostgreSQL TEXT
 *   SchemaBuilder::TYPE_DATETIME → SQLite TEXT (ISO 8601), PostgreSQL TIMESTAMP
 *   SchemaBuilder::TYPE_BOOLEAN  → SQLite INTEGER (0/1), PostgreSQL BOOLEAN
 *   SchemaBuilder::TYPE_JSON     → SQLite TEXT, PostgreSQL JSONB
 *   SchemaBuilder::TYPE_UUID     → SQLite TEXT, PostgreSQL UUID
 *
 * NOTE: The builder does NOT try to be a full ORM. It only handles table
 * creation. Drop / rename / alter operations are intentionally minimal.
 *
 * Persian/RTL correctness: every TEXT column is created with no collation
 * in SQLite (TEXT collation = binary; Persian sorting handled in PHP) and
 * the default collation in PostgreSQL (which respects ICU when the cluster
 * is built with --with-icu — standard in modern PG installs).
 */
final class SchemaBuilder
{
    public const TYPE_INTEGER  = 'integer';
    public const TYPE_TEXT      = 'text';
    public const TYPE_DATETIME  = 'datetime';
    public const TYPE_BOOLEAN   = 'boolean';
    public const TYPE_JSON       = 'json';
    public const TYPE_UUID       = 'uuid';
    public const TYPE_BIGINT     = 'bigint';

    private array $columns = [];
    private array $indexes = [];
    private array $uniqueConstraints = [];

    public function __construct(
        private readonly string $tableName
    ) {
    }

    public static function table(string $name): self
    {
        return new self($name);
    }

    public function uuid(string $name): self
    {
        $this->columns[$name] = ['type' => self::TYPE_UUID, 'nullable' => false];
        return $this;
    }

    public function bigInt(string $name, bool $autoIncrement = false): self
    {
        $this->columns[$name] = ['type' => self::TYPE_BIGINT, 'nullable' => false, 'auto_increment' => $autoIncrement];
        return $this;
    }

    public function integer(string $name, bool $autoIncrement = false): self
    {
        $this->columns[$name] = ['type' => self::TYPE_INTEGER, 'nullable' => false, 'auto_increment' => $autoIncrement];
        return $this;
    }

    public function string(string $name, ?int $length = null): self
    {
        // Both SQLite and PostgreSQL TEXT have no length limit; length is
        // accepted for documentation only.
        unset($length);
        $this->columns[$name] = ['type' => self::TYPE_TEXT, 'nullable' => false];
        return $this;
    }

    public function text(string $name): self
    {
        $this->columns[$name] = ['type' => self::TYPE_TEXT, 'nullable' => false];
        return $this;
    }

    public function datetime(string $name): self
    {
        $this->columns[$name] = ['type' => self::TYPE_DATETIME, 'nullable' => false];
        return $this;
    }

    public function boolean(string $name): self
    {
        $this->columns[$name] = ['type' => self::TYPE_BOOLEAN, 'nullable' => false];
        return $this;
    }

    public function json(string $name): self
    {
        $this->columns[$name] = ['type' => self::TYPE_JSON, 'nullable' => true];
        return $this;
    }

    public function nullable(): self
    {
        $key = array_key_last($this->columns);
        if ($key !== null) {
            $this->columns[$key]['nullable'] = true;
        }
        return $this;
    }

    public function default(mixed $value): self
    {
        $key = array_key_last($this->columns);
        if ($key !== null) {
            $this->columns[$key]['default'] = $value;
        }
        return $this;
    }

    public function index(string ...$columns): self
    {
        $this->indexes[] = ['columns' => $columns];
        return $this;
    }

    public function unique(string ...$columns): self
    {
        $this->uniqueConstraints[] = ['columns' => $columns];
        return $this;
    }

    /**
     * Build the CREATE TABLE + INDEX statements for the given driver.
     *
     * @param string $driver "sqlite" or "postgres".
     * @return array<string> Array of DDL statements to execute in order.
     */
    public function toSql(string $driver): array
    {
        $statements = [];
        $statements[] = $this->buildCreateTable($driver);

        foreach ($this->indexes as $idx) {
            $cols = implode(', ', $idx['columns']);
            $idxName = $this->tableName . '_idx_' . implode('_', $idx['columns']);
            $statements[] = sprintf('CREATE INDEX IF NOT EXISTS %s ON %s (%s)', $idxName, $this->tableName, $cols);
        }
        foreach ($this->uniqueConstraints as $uniq) {
            $cols = implode(', ', $uniq['columns']);
            $uqName = $this->tableName . '_uq_' . implode('_', $uniq['columns']);
            $statements[] = sprintf(
                'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (%s)',
                $uqName,
                $this->tableName,
                $cols
            );
        }
        return $statements;
    }

    private function buildCreateTable(string $driver): string
    {
        $lines = [];
        foreach ($this->columns as $name => $def) {
            $sqlType = $this->mapType($def['type'], $driver);
            $line = sprintf('%s %s', $name, $sqlType);

            if (($def['auto_increment'] ?? false)) {
                $line .= $driver === 'sqlite'
                    ? ' PRIMARY KEY AUTOINCREMENT'
                    : ' GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY';
            } elseif (!($def['nullable'] ?? true)) {
                $line .= ' NOT NULL';
            }

            if (array_key_exists('default', $def)) {
                $line .= ' DEFAULT ' . $this->formatDefault($def['default'], $driver);
            }

            $lines[] = $line;
        }

        return sprintf(
            'CREATE TABLE IF NOT EXISTS %s (%s)',
            $this->tableName,
            implode(', ', $lines)
        );
    }

    private function mapType(string $type, string $driver): string
    {
        return match ([$type, $driver]) {
            [self::TYPE_INTEGER, 'sqlite']   => 'INTEGER',
            [self::TYPE_INTEGER, 'postgres'] => 'INTEGER',
            [self::TYPE_BIGINT, 'sqlite']    => 'INTEGER',
            [self::TYPE_BIGINT, 'postgres']  => 'BIGINT',
            [self::TYPE_TEXT, 'sqlite']       => 'TEXT',
            [self::TYPE_TEXT, 'postgres']     => 'TEXT',
            [self::TYPE_DATETIME, 'sqlite']   => 'TEXT',   // ISO 8601 string
            [self::TYPE_DATETIME, 'postgres'] => 'TIMESTAMP',
            [self::TYPE_BOOLEAN, 'sqlite']    => 'INTEGER',
            [self::TYPE_BOOLEAN, 'postgres']  => 'BOOLEAN',
            [self::TYPE_JSON, 'sqlite']       => 'TEXT',
            [self::TYPE_JSON, 'postgres']    => 'JSONB',
            [self::TYPE_UUID, 'sqlite']       => 'TEXT',
            [self::TYPE_UUID, 'postgres']    => 'UUID',
            default                            => 'TEXT',
        };
    }

    private function formatDefault(mixed $value, string $driver): string
    {
        if (is_bool($value)) {
            return $driver === 'sqlite' ? ($value ? '1' : '0') : ($value ? 'TRUE' : 'FALSE');
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value === null) {
            return 'NULL';
        }
        return "'" . str_replace("'", "''", (string) $value) . "'";
    }
}
