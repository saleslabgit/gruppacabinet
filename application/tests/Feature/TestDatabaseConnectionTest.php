<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TestDatabaseConnectionTest extends TestCase
{
    public function test_suite_uses_the_dedicated_mysql_database(): void
    {
        $connection = DB::connection();
        $result = $connection->selectOne('SELECT DATABASE() AS database_name, 1 AS connected');

        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame('gruppa_cabinet_test', $connection->getDatabaseName());
        $this->assertSame('gruppa_cabinet_test', $result->database_name);
        $this->assertSame(1, (int) $result->connected);
    }
}
