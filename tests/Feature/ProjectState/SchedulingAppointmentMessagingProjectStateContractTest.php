<?php

namespace Tests\Feature\ProjectState;

use App\Modules\Scheduling\Models\Appointment;
use Tests\TestCase;

class SchedulingAppointmentMessagingProjectStateContractTest extends TestCase
{
    public function test_messaging_project_state_remaps_appointment_communication_contexts(): void
    {
        $section = require config_path('project_state/messaging.php');

        $this->assertGreaterThanOrEqual(
            5,
            (int) ($section['version'] ?? 0),
        );

        $enrollments = $section['tables']['message_chain_enrollments'] ?? [];
        $scheduledMessages = $section['tables']['scheduled_messages'] ?? [];

        $this->assertSame(
            'appointments',
            $this->targetsFor($enrollments, 'context_type')[Appointment::class] ?? null,
        );
        $this->assertSame(
            'appointments',
            $this->targetsFor($enrollments, 'origin_type')[Appointment::class] ?? null,
        );
        $this->assertSame(
            'appointments',
            $this->targetsFor($scheduledMessages, 'context_type')[Appointment::class] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $table
     * @return array<string, string>
     */
    private function targetsFor(array $table, string $typeColumn): array
    {
        foreach ($table['polymorphic_references'] ?? [] as $reference) {
            if (($reference['type_column'] ?? null) === $typeColumn) {
                return is_array($reference['targets'] ?? null)
                    ? $reference['targets']
                    : [];
            }
        }

        return [];
    }
}