<?php

namespace Tests\Feature;

use App\Support\SchemaAudit\ManualSelectContractAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualSelectContractAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_select_contracts_match_the_current_schema(): void
    {
        $failures = app(ManualSelectContractAudit::class)->run();

        $this->assertSame([], $failures, json_encode($failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function test_schema_audit_command_reports_a_clean_status_as_json(): void
    {
        $this->artisan('schema:audit-selects --json')->assertExitCode(0);
    }
}
