<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * Sample Migration #2: adds customer loyalty table.
 */

use GitiArts\Phase2\Database\SchemaBuilder;

return new class {
    /**
     * @return array<int,string>
     */
    public function up(SchemaBuilder $schema): array
    {
        return SchemaBuilder::table('customers')
            ->uuid('id')
            ->string('mobile')
            ->string('name_fa')->nullable()
            ->integer('loyalty_points')->default(0)
            ->datetime('first_order_at')->nullable()
            ->datetime('last_order_at')->nullable()
            ->datetime('created_at')
            ->datetime('updated_at')
            ->unique('mobile')
            ->index('loyalty_points')
            ->toSql('sqlite');
    }

    /**
     * @return array<int,string>
     */
    public function down(SchemaBuilder $schema): array
    {
        return ['DROP TABLE IF EXISTS customers'];
    }
};
