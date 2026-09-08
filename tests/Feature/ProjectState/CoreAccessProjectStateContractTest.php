<?php

namespace Tests\Feature\ProjectState;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoreAccessProjectStateContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_environment_owned_access_tables_and_contact_assignment_columns_are_classified(): void
    {
        $policies = config('project_state.table_policies');

        $this->assertSame('environment_owned', $policies['teams']['mode'] ?? null);
        $this->assertSame('environment_owned', $policies['user_access_profiles']['mode'] ?? null);
        $this->assertSame('environment_owned', $policies['team_user']['mode'] ?? null);

        $contacts = config('project_state.sections.core.tables.contacts');

        $this->assertContains('assigned_user_id', $contacts['columns']);
        $this->assertContains('assigned_team_id', $contacts['columns']);
        $this->assertSame([
            'assigned_user_id',
            'assigned_team_id',
        ], $contacts['null_on_import']);
    }
}