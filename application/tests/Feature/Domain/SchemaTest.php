<?php

namespace Tests\Feature\Domain;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_stage_two_tables_exist_on_mysql(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('gruppa_cabinet_test', DB::connection()->getDatabaseName());

        foreach ([
            'gp_dictionaries',
            'gp_dictionary_items',
            'gp_users',
            'gp_user_documents',
            'gp_groups',
            'gp_group_status_history',
            'gp_audit_log',
            'gp_payments',
            'gp_payment_notifications',
            'gp_group_applications',
            'gp_settings',
            'sessions',
            'jobs',
            'job_batches',
            'failed_jobs',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table [{$table}].");
        }
    }

    public function test_active_email_is_a_stored_generated_column_with_the_required_unique_index(): void
    {
        $column = DB::selectOne(<<<'SQL'
            SELECT GENERATION_EXPRESSION, EXTRA
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'gp_users'
              AND COLUMN_NAME = 'active_email'
        SQL);

        $this->assertNotNull($column);
        $this->assertStringContainsString('deleted_at', strtolower((string) $column->GENERATION_EXPRESSION));
        $this->assertStringContainsString('stored generated', strtolower((string) $column->EXTRA));

        $indexes = $this->indexes('gp_users');
        $this->assertSame(['active_email'], $indexes['gp_users_active_email_unique']['columns']);
        $this->assertTrue($indexes['gp_users_active_email_unique']['unique']);

        foreach ($indexes as $index) {
            $this->assertNotSame(['email'], $index['columns']);
            $this->assertNotSame(['email', 'deleted_at'], $index['columns']);
        }
    }

    public function test_required_domain_indexes_exist(): void
    {
        $expectations = [
            'gp_users' => [['status'], ['disabled'], ['free']],
            'gp_groups' => [['public_uuid'], ['owner_id'], ['status'], ['expires_at'], ['status', 'expires_at'], ['owner_id', 'status']],
            'gp_payments' => [['order_number'], ['transaction_id'], ['owner_id'], ['group_id'], ['status'], ['type'], ['created_at']],
            'gp_payment_notifications' => [['payment_id'], ['order_number'], ['transaction_id'], ['created_at']],
            'gp_group_applications' => [['group_id'], ['processed_at'], ['group_id', 'processed_at'], ['created_at']],
            'gp_group_status_history' => [['group_id']],
            'gp_audit_log' => [['entity_type', 'entity_id'], ['actor_id'], ['created_at']],
            'gp_dictionary_items' => [['dictionary_id', 'code']],
        ];

        foreach ($expectations as $table => $expectedColumnSets) {
            $actualColumnSets = array_column($this->indexes($table), 'columns');

            foreach ($expectedColumnSets as $expectedColumns) {
                $this->assertContains($expectedColumns, $actualColumnSets, sprintf(
                    'Missing index on %s (%s).',
                    $table,
                    implode(', ', $expectedColumns),
                ));
            }
        }

        $paymentIndexes = $this->indexes('gp_payments');
        $this->assertTrue($paymentIndexes['gp_payments_order_number_unique']['unique']);
        $this->assertTrue($paymentIndexes['gp_payments_transaction_id_unique']['unique']);
    }

    /**
     * @return array<string, array{columns: list<string>, unique: bool}>
     */
    private function indexes(string $table): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            ORDER BY INDEX_NAME, SEQ_IN_INDEX
        SQL, [$table]);

        $indexes = [];

        foreach ($rows as $row) {
            $name = (string) $row->INDEX_NAME;
            $indexes[$name] ??= [
                'columns' => [],
                'unique' => (int) $row->NON_UNIQUE === 0,
            ];
            $indexes[$name]['columns'][] = (string) $row->COLUMN_NAME;
        }

        return $indexes;
    }
}
