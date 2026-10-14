<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('crm migrations apply rollback and reapply on a disposable database', function () {
    config()->set('database.connections.crm_migration_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    $tables = ['companies', 'contacts', 'deals', 'tasks', 'notes'];

    try {
        $this->artisan('migrate', ['--database' => 'crm_migration_test', '--force' => true])->assertSuccessful();
        foreach ($tables as $table) {
            expect(Schema::connection('crm_migration_test')->hasTable($table))->toBeTrue();
        }
        expect(DB::connection('crm_migration_test')->table('companies')->count())->toBe(0);

        $this->artisan('migrate:rollback', ['--database' => 'crm_migration_test', '--step' => 5, '--force' => true])->assertSuccessful();
        foreach ($tables as $table) {
            expect(Schema::connection('crm_migration_test')->hasTable($table))->toBeFalse();
        }
        expect(Schema::connection('crm_migration_test')->hasTable('users'))->toBeTrue();

        $this->artisan('migrate', ['--database' => 'crm_migration_test', '--force' => true])->assertSuccessful();
        foreach ($tables as $table) {
            expect(Schema::connection('crm_migration_test')->hasTable($table))->toBeTrue();
        }
    } finally {
        DB::purge('crm_migration_test');
    }
});
