<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * Sample Migration: creates the core tenants-related tables for a café.
 *
 * This migration runs on BOTH SQLite and PostgreSQL via SchemaBuilder.
 *
 * Returns an anonymous class implementing up() and down() methods.
 */

use GitiArts\Phase2\Database\SchemaBuilder;

return new class {
    /**
     * @return array<int,string>
     */
    public function up(SchemaBuilder $schema): array
    {
        // ---------------------------------------------------------------
        // menu_categories: classification of menu items
        // ---------------------------------------------------------------
        $catStatements = SchemaBuilder::table('menu_categories')
            ->uuid('id')
            ->string('name_fa')                  // Persian category name
            ->string('slug')                     // URL-safe identifier
            ->integer('sort_order')->default(0)
            ->datetime('created_at')
            ->datetime('updated_at')
            ->unique('slug')
            ->index('sort_order')
            ->toSql('sqlite');

        // ---------------------------------------------------------------
        // menu_items: products the café sells
        // ---------------------------------------------------------------
        $itemStatements = SchemaBuilder::table('menu_items')
            ->uuid('id')
            ->uuid('category_id')->nullable()
            ->string('name_fa')                   // Persian display name
            ->text('description_fa')->nullable()
            ->bigInt('price_toman')->default(0)
            ->boolean('is_available')->default(true)
            ->string('image_path')->nullable()
            ->integer('sort_order')->default(0)
            ->datetime('created_at')
            ->datetime('updated_at')
            ->index('category_id')
            ->index('is_available')
            ->toSql('sqlite');

        // ---------------------------------------------------------------
        // orders: customer orders
        // ---------------------------------------------------------------
        $orderStatements = SchemaBuilder::table('orders')
            ->uuid('id')
            ->uuid('employee_id')->nullable()
            ->string('table_label')->nullable()
            ->bigInt('total_toman')->default(0)
            ->string('status')->default('pending')   // pending|preparing|served|paid|cancelled
            ->json('items_json')->nullable()
            ->datetime('created_at')
            ->datetime('updated_at')
            ->index('status')
            ->index('created_at')
            ->index('employee_id')
            ->toSql('sqlite');

        // ---------------------------------------------------------------
        // employees: café staff
        // ---------------------------------------------------------------
        $empStatements = SchemaBuilder::table('employees')
            ->uuid('id')
            ->string('full_name_fa')                  // Persian full name
            ->string('mobile')                        // Iranian mobile (09XXXXXXXXX)
            ->string('national_id')->nullable()        // Iranian National ID (کد ملی)
            ->string('role')->default('barista')       // manager|barista|waiter|chef|cashier
            ->boolean('is_active')->default(true)
            ->datetime('hired_at')
            ->datetime('created_at')
            ->datetime('updated_at')
            ->unique('mobile')
            ->index('role')
            ->index('is_active')
            ->toSql('sqlite');

        return array_merge($catStatements, $itemStatements, $orderStatements, $empStatements);
    }

    /**
     * @return array<int,string>
     */
    public function down(SchemaBuilder $schema): array
    {
        return [
            'DROP TABLE IF EXISTS employees',
            'DROP TABLE IF EXISTS orders',
            'DROP TABLE IF EXISTS menu_items',
            'DROP TABLE IF EXISTS menu_categories',
        ];
    }
};
