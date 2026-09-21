<?php

namespace Database\Seeders;

use App\Support\Modules\ModuleManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SurfaceShowcaseSeeder extends Seeder
{
    private const MARKER = 'surface-showcase-v1';

    /** @var array<string, int|string|null> */
    private array $refs = [];

    /** @var array<string, array<string, true>> */
    private array $coveredColumns = [];

    /** @var array<string, array<int, string>> */
    private array $columnCache = [];

    /** @var array<int, string> */
    private array $seededModules = [];

    private ?object $clientReplyTaskTemplate = null;

    private ?object $clientReplyFlow = null;

    private CarbonImmutable $now;

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException(
                'SurfaceShowcaseSeeder is intentionally restricted to local and testing environments.',
            );
        }

        $this->now = CarbonImmutable::now()->startOfMinute();

        DB::transaction(function (): void {
            $this->seedCore();
            $this->seedWorkflow();
            $this->seedRelationships();
            $this->seedMortgage();
            $this->seedTasks();
            $this->seedMedia();
            $this->seedMessaging();
            $this->seedInboundMessaging();
            $this->seedInternalNotifications();
            $this->seedWebinars();
            $this->seedCampaigns();
            $this->seedBroadcasts();
            $this->seedScheduling();
            $this->seedFlowRoutes();
            $this->seedDocuments();
            $this->seedReporting();

            $this->assertCoveredColumns();
        });

        $this->command?->info(sprintf(
            'Surface showcase ready. Seeded modules: %s.',
            implode(', ', $this->seededModules),
        ));
    }

    private function seedCore(): void
    {
        $this->requireTable('users', 'core');

        $this->refs['owner'] = $this->row('users', [
            'email' => 'stacey.showcase@example.test',
        ], [
            'name' => 'Stacey Showcase',
            'email_verified_at' => $this->now->subYears(2),
            'password' => Hash::make('password'),
            'remember_token' => 'showcase-remember-token',
        ]);

        $this->refs['loan_officer'] = $this->row('users', [
            'email' => 'ben.showcase@example.test',
        ], [
            'name' => 'Ben Loan Officer',
            'email_verified_at' => $this->now->subYear(),
            'password' => Hash::make('password'),
            'remember_token' => 'showcase-lo-remember-token',
        ]);

        if ($this->hasTable('teams')) {
            $this->refs['team'] = $this->row('teams', [
                'name' => 'Showcase Lending Team',
            ], [
                'is_active' => true,
                'meta' => $this->meta(['region' => 'Central', 'queue' => 'new-business']),
            ]);

            $this->row('team_user', [
                'team_id' => $this->refs['team'],
                'user_id' => $this->refs['owner'],
            ]);
            $this->row('team_user', [
                'team_id' => $this->refs['team'],
                'user_id' => $this->refs['loan_officer'],
            ]);

            $this->row('user_access_profiles', [
                'user_id' => $this->refs['owner'],
            ], [
                'role_key' => 'owner',
                'is_active' => true,
                'capability_overrides' => ['contacts.delete' => true, 'messages.release_suppression' => true],
                'meta' => $this->meta(['title' => 'Branch manager']),
            ]);
            $this->row('user_access_profiles', [
                'user_id' => $this->refs['loan_officer'],
            ], [
                'role_key' => 'member',
                'is_active' => true,
                'capability_overrides' => ['contacts.delete' => false],
                'meta' => $this->meta(['title' => 'Loan officer']),
            ]);
        }

        $statuses = [
            'prospect' => ['Showcase · Prospect Nurture', 'nurture', '#2563eb', 10],
            'past_client' => ['Showcase · Past Client', 'customer', '#059669', 20],
            'preapproved' => ['Showcase · Preapproved', 'active', '#7c3aed', 30],
            'requires_action' => ['Showcase · Requires Action', 'attention', '#dc2626', 40],
        ];

        foreach ($statuses as $key => [$name, $category, $color, $sortOrder]) {
            $this->refs['status_'.$key] = $this->row('contact_statuses', [
                'key' => 'showcase_'.$key,
            ], [
                'name' => $name,
                'description' => 'Rich dev-only status used to evaluate populated CRM surfaces.',
                'category' => $category,
                'color' => $color,
                'is_core' => false,
                'is_active' => true,
                'is_customized' => true,
                'customized_at' => $this->now->subDays(30),
                'sort_order' => $sortOrder,
                'source_version' => 'showcase-1',
                'meta' => $this->meta(['business_meaning' => $category]),
            ]);
        }

        $this->refs['import_batch'] = $this->row('contact_import_batches', [
            'name' => 'Showcase Realtor Outreach · September 2026',
        ], [
            'source' => 'csv_import',
            'original_filename' => 'showcase-realtors-2026-09.csv',
            'status' => 'completed',
            'imported_at' => $this->now->subDays(7),
            'contact_count' => 6,
            'successful_count' => 5,
            'failed_count' => 1,
            'meta' => $this->meta(['audience' => 'realtors', 'operator_notes' => 'Contains one deliberate conflict.']),
        ]);

        $contacts = [
            'ava' => [
                'first_name' => 'Ava', 'last_name' => 'Morgan', 'name' => 'Ava Morgan',
                'email' => 'ava.morgan+showcase@example.test', 'phone' => '+13125550101',
                'birthday' => '1988-04-17', 'source' => 'Realtor referral', 'subsource' => 'Shawna Ellis',
            ],
            'marcus' => [
                'first_name' => 'Marcus', 'last_name' => 'Chen', 'name' => 'Marcus Chen',
                'email' => 'marcus.chen+showcase@example.test', 'phone' => '+13125550102',
                'birthday' => '1979-11-03', 'source' => 'Past client import', 'subsource' => 'Loan CRM',
            ],
            'nina' => [
                'first_name' => 'Nina', 'last_name' => 'Patel', 'name' => 'Nina Patel',
                'email' => 'nina.patel+showcase@example.test', 'phone' => '+13125550103',
                'birthday' => '1992-08-26', 'source' => 'Webinar', 'subsource' => 'VA Homebuyer Game Plan',
            ],
            'olivia' => [
                'first_name' => 'Olivia', 'last_name' => 'Reed', 'name' => 'Olivia Reed',
                'email' => 'olivia.reed+showcase@example.test', 'phone' => '+13125550104',
                'birthday' => '1985-02-14', 'source' => 'Realtor outreach', 'subsource' => 'Cara Woods',
            ],
            'daniel' => [
                'first_name' => 'Daniel', 'last_name' => 'Brooks', 'name' => 'Daniel Brooks',
                'email' => 'daniel.brooks+showcase@example.test', 'phone' => '+13125550105',
                'birthday' => '1974-06-09', 'source' => 'Website', 'subsource' => 'Mortgage calculator',
            ],
            'unmatched' => [
                'first_name' => 'Unmatched', 'last_name' => 'Sender', 'name' => 'Unmatched Sender',
                'email' => 'unmatched.sender+showcase@example.test', 'phone' => '+13125550199',
                'birthday' => '1990-01-01', 'source' => 'Manual', 'subsource' => 'Inbox recovery example',
            ],
        ];

        foreach ($contacts as $key => $contact) {
            $this->refs['contact_'.$key] = $this->row('contacts', [
                'email' => $contact['email'],
            ], $contact + [
                'contact_import_batch_id' => $this->refs['import_batch'],
                'assigned_user_id' => $key === 'marcus' ? $this->refs['loan_officer'] : $this->refs['owner'],
                'assigned_team_id' => $this->refs['team'] ?? null,
                'last_contacted_at' => $this->now->subHours(6),
                'last_activity_at' => $this->now->subMinutes(18),
                'meta' => $this->meta([
                    'preferred_name' => $contact['first_name'],
                    'preferred_channel' => $key === 'nina' ? 'sms' : 'email',
                    'timezone' => 'America/Chicago',
                    'data_quality' => $key === 'marcus' ? ['conflicting_tag' => true] : ['reviewed' => true],
                ]),
            ]);
        }

        $tags = [
            'ava' => ['Shawna Realtor Referral', 'Hot Lead'],
            'marcus' => ['Past Client', 'Old Lead', 'Needs Data Review'],
            'nina' => ['Webinar Registrant', 'High Intent Reply'],
            'olivia' => ['Cara Realtor Referral', 'Realtor Outreach'],
            'daniel' => ['Mortgage Calculator', 'New Lead'],
            'unmatched' => ['Inbox Recovery Example'],
        ];

        foreach ($tags as $contactKey => $contactTags) {
            foreach ($contactTags as $tag) {
                $this->row('contact_tags', [
                    'contact_id' => $this->refs['contact_'.$contactKey],
                    'tag' => $tag,
                ]);
            }
        }

        $this->refs['note'] = $this->row('notes', [
            'contact_id' => $this->refs['contact_nina'],
            'body' => 'Nina asked whether zero-down VA financing could work before her lease ends in November.',
        ], [
            'related_type' => 'App\\Modules\\Core\\Models\\Contact',
            'related_id' => $this->refs['contact_nina'],
            'meta' => $this->meta(['visibility' => 'internal', 'author' => 'Stacey Showcase']),
        ]);

        foreach (array_keys($contacts) as $index => $contactKey) {
            $this->row('contact_import_occurrences', [
                'contact_import_batch_id' => $this->refs['import_batch'],
                'contact_id' => $this->refs['contact_'.$contactKey],
            ], [
                'row_number' => $index + 2,
                'outcome' => $contactKey === 'marcus' ? 'updated' : 'created',
                'identity_type' => 'email',
                'identity_value' => $contacts[$contactKey]['email'],
                'original_source' => $contacts[$contactKey]['source'],
                'original_subsource' => $contacts[$contactKey]['subsource'],
                'original_status' => $contactKey === 'marcus' ? 'Prospect Nurture' : 'New',
                'row_fingerprint' => hash('sha256', self::MARKER.'-'.$contactKey),
                'meta' => $this->meta(['normalized_phone' => $contacts[$contactKey]['phone']]),
            ]);
        }

        $this->refs['import_run'] = $this->row('contact_import_runs', [
            'contact_import_batch_id' => $this->refs['import_batch'],
            'profile_key' => 'showcase_realtor_import',
        ], [
            'status' => 'failed',
            'csv_path' => 'imports/showcase-realtors-2026-09.csv',
            'import_mode' => 'upsert',
            'headers' => ['First Name', 'Last Name', 'Email', 'Phone', 'Source', 'Status'],
            'mapping' => ['email' => 'Email', 'phone' => 'Phone', 'source' => 'Source'],
            'profile_defaults' => ['status' => 'prospect_nurture'],
            'treatment_selections' => ['marketing_consent' => 'leave_unchanged'],
            'post_import_config' => ['campaign_key' => 'showcase_realtor_outreach'],
            'processing_stats' => ['created' => 4, 'updated' => 1, 'failed' => 1],
            'actor_user_id' => $this->refs['owner'],
            'total_rows' => 6,
            'processed_rows' => 6,
            'next_row_number' => 8,
            'next_byte_offset' => 2048,
            'queued_at' => $this->now->subDays(7)->subMinutes(5),
            'started_at' => $this->now->subDays(7)->subMinutes(4),
            'finalizing_at' => $this->now->subDays(7)->subMinute(),
            'failed_at' => $this->now->subDays(7),
            'failure_reason' => 'One row contained neither a usable email nor a usable phone number.',
        ]);

        $this->refs['calendar'] = $this->row('business_calendars', [
            'key' => 'showcase_business_calendar',
        ], [
            'name' => 'Showcase Business Calendar',
            'skipped_weekdays' => [0, 6],
            'is_default' => false,
        ]);
        $this->row('business_calendar_exclusions', [
            'business_calendar_id' => $this->refs['calendar'],
            'key' => 'showcase_independence_day',
        ], [
            'name' => 'Independence Day',
            'recurrence' => 'annual',
            'exact_date' => '2026-07-04',
            'month' => 7,
            'day' => 4,
        ]);

        $this->row('site_settings', [
            'key' => 'showcase.surface_data',
        ], [
            'value' => ['version' => 1, 'seeded_at' => $this->now->toIso8601String(), 'marker' => self::MARKER],
        ]);

        $this->seededModules[] = 'core';
    }

    private function seedWorkflow(): void
    {
        if (! $this->moduleAvailable('workflow', 'contact_workflow_profiles')) {
            return;
        }

        $assignments = [
            'ava' => $this->clientStatusId('prospect_new') ?? $this->refs['status_prospect'],
            'marcus' => $this->clientStatusId('past_contact') ?? $this->refs['status_past_client'],
            'nina' => $this->clientStatusId('engaged') ?? $this->refs['status_requires_action'],
            'olivia' => $this->clientStatusId('prospect_nurture') ?? $this->refs['status_prospect'],
            'daniel' => $this->clientStatusId('lost_inactive') ?? $this->refs['status_prospect'],
            'unmatched' => $this->clientStatusId('prospect_new') ?? $this->refs['status_prospect'],
        ];

        foreach ($assignments as $contactKey => $statusId) {
            $this->refs['workflow_'.$contactKey] = $this->row('contact_workflow_profiles', [
                'contact_id' => $this->refs['contact_'.$contactKey],
            ], [
                'contact_status_id' => $statusId,
                'assigned_to_type' => 'App\\Models\\User',
                'assigned_to_id' => $contactKey === 'marcus' ? $this->refs['loan_officer'] : $this->refs['owner'],
                'last_status_changed_at' => $this->now->subDays($contactKey === 'nina' ? 0 : 12),
                'meta' => $this->meta([
                    'change_reason' => $contactKey === 'marcus'
                        ? 'Corrected from prospect after client review.'
                        : 'Showcase lifecycle assignment.',
                ]),
            ]);
        }

        $this->seededModules[] = 'workflow';
    }

    private function seedRelationships(): void
    {
        if (! $this->moduleAvailable('relationships', 'contact_relationships')) {
            return;
        }

        $this->refs['relationship_realtor'] = $this->row('contact_relationships', [
            'contact_id' => $this->refs['contact_olivia'],
            'relationship_key' => 'realtor',
        ], [
            'stage_key' => 'active_referral_partner',
            'source' => 'realtor_import',
            'subsource' => 'Cara Woods list',
            'is_active' => true,
            'started_at' => $this->now->subMonths(18),
            'ended_at' => $this->now->subMonths(2),
            'meta' => $this->meta(['market' => 'Chicago western suburbs', 'owner' => 'Ben']),
        ]);

        $this->seededModules[] = 'relationships';
    }

    private function seedMortgage(): void
    {
        if (! $this->moduleAvailable('mortgage', 'contact_mortgage_profiles')) {
            return;
        }

        $this->refs['mortgage_stage'] = $this->row('mortgage_stages', [
            'key' => 'showcase_closed',
        ], [
            'name' => 'Showcase · Closed',
            'category' => 'closed',
            'is_active' => true,
            'sort_order' => 90,
        ]);

        foreach (['ava' => 'yes', 'marcus' => 'no', 'nina' => 'unknown', 'daniel' => 'no'] as $contactKey => $hasRealtor) {
            $this->row('contact_mortgage_profiles', [
                'contact_id' => $this->refs['contact_'.$contactKey],
            ], [
                'has_realtor' => $hasRealtor,
                'original_lead_at' => $this->now->subMonths($contactKey === 'marcus' ? 30 : 3),
                'meta' => $this->meta([
                    'occupancy' => 'primary_residence',
                    'target_timeline' => $contactKey === 'nina' ? 'within_90_days' : 'exploring',
                ]),
            ]);
        }

        if ($this->hasTable('mortgage_loans')) {
            $this->refs['mortgage_loan'] = $this->row('mortgage_loans', [
                'source_system' => 'showcase_los',
                'source_record_id' => 'SC-2026-001',
            ], [
                'mortgage_stage_id' => $this->refs['mortgage_stage'],
                'source_fingerprint' => hash('sha256', self::MARKER.'-loan'),
                'loan_originator' => 'Stacey Showcase',
                'loan_purpose' => 'purchase',
                'loan_program' => 'VA 30 Year Fixed',
                'mortgage_type' => 'VA',
                'lien_position' => 'first',
                'loan_amount' => '415000.00',
                'note_rate' => '6.1250',
                'sales_price' => '425000.00',
                'appraised_value' => '430000.00',
                'cash_to_close' => '8500.00',
                'subject_property_street' => '1840 Example Avenue',
                'subject_property_city' => 'Naperville',
                'subject_property_state' => 'IL',
                'subject_property_zip' => '60540',
                'closed_on' => $this->now->subMonths(6)->toDateString(),
                'meta' => $this->meta(['loan_number_masked' => '•••• 4821', 'servicing_status' => 'current']),
            ]);

            $this->row('mortgage_loan_participants', [
                'mortgage_loan_id' => $this->refs['mortgage_loan'],
                'role' => 'borrower',
                'position' => 1,
            ], [
                'contact_id' => $this->refs['contact_marcus'],
                'first_name' => 'Marcus',
                'last_name' => 'Chen',
                'email' => 'marcus.chen+showcase@example.test',
                'phone' => '+13125550102',
                'date_of_birth' => '1979-11-03',
                'mailing_address' => '1840 Example Avenue, Naperville, IL 60540',
                'meta' => $this->meta(['occupancy' => 'primary']),
            ]);

            $this->row('mortgage_loan_realtors', [
                'mortgage_loan_id' => $this->refs['mortgage_loan'],
                'role' => 'buyers_agent',
                'position' => 1,
            ], [
                'contact_id' => $this->refs['contact_olivia'],
                'name' => 'Olivia Reed',
                'email' => 'olivia.reed+showcase@example.test',
                'phone' => '+13125550104',
                'meta' => $this->meta(['brokerage' => 'Example Realty Group']),
            ]);
        }

        if ($this->hasTable('mortgage_realtor_profiles') && isset($this->refs['relationship_realtor'])) {
            $this->refs['mortgage_realtor_profile'] = $this->row('mortgage_realtor_profiles', [
                'contact_relationship_id' => $this->refs['relationship_realtor'],
            ], [
                'brokerage_name' => 'Example Realty Group',
                'license_number' => 'IL-475.123456-SC',
                'last_referral_at' => $this->now->subDays(9),
                'meta' => $this->meta(['preferred_program' => 'VA', 'territory' => ['Naperville', 'Aurora']]),
            ]);

            $this->row('mortgage_realtor_production_snapshots', [
                'mortgage_realtor_profile_id' => $this->refs['mortgage_realtor_profile'],
                'period_ending_on' => $this->now->endOfMonth()->toDateString(),
            ], [
                'period_months' => 12,
                'loan_count' => 34,
                'conventional_count' => 21,
                'va_count' => 8,
                'loan_volume' => '14250000.00',
                'source' => 'showcase_market_data',
                'source_fingerprint' => hash('sha256', self::MARKER.'-realtor-production'),
                'meta' => $this->meta(['rank' => 'top_20_percent']),
            ]);
        }

        $this->seededModules[] = 'mortgage';
    }

    private function seedTasks(): void
    {
        if (! $this->moduleAvailable('tasks', 'tasks')) {
            return;
        }

        $template = $this->clientReplyTaskTemplate();

        if ($template === null) {
            $templateId = $this->row('task_templates', [
                'key' => 'showcase_high_intent_reply_follow_up',
            ], [
                'source' => 'module',
                'source_version' => 'showcase-1',
                'owner_group' => 'showcase',
                'category' => 'reply_follow_up',
                'name' => 'High-intent reply follow-up',
                'title' => 'Follow up on high-intent reply',
                'description' => 'Review the inbound reply and contact this person promptly.',
                'task_description' => 'Review the inbound reply, contact the person as soon as practical, and record the outcome in the CRM.',
                'assigned_to_type' => null,
                'assigned_to_id' => null,
                'assigned_to_strategy' => 'unassigned',
                'responsible_party' => 'internal',
                'responsible_type' => null,
                'responsible_id' => null,
                'priority' => 'high',
                'due_offset_minutes' => 0,
                'link_defaults' => [
                    ['source' => 'current_contact', 'role' => 'subject'],
                ],
                'defaults' => [],
                'is_active' => true,
                'is_customized' => false,
                'customized_at' => null,
                'meta' => $this->meta(['fallback' => true]),
            ]);

            $template = DB::table('task_templates')->where('id', $templateId)->first();
        }

        if (! is_object($template)) {
            throw new RuntimeException('Surface showcase could not resolve a reply follow-up Task Template.');
        }

        $this->clientReplyTaskTemplate = $template;
        $this->refs['task_template'] = (int) $template->id;
        $this->refs['task_template_key'] = (string) $template->key;

        $this->refs['task_template_coverage'] = $this->row('task_templates', [
            'key' => 'showcase_task_template_field_coverage',
        ], [
            'source' => 'manual',
            'source_version' => 'showcase-1',
            'owner_group' => 'showcase',
            'category' => 'surface_coverage',
            'name' => 'Showcase · Task template field coverage',
            'title' => 'Inspect every Task Template field',
            'description' => 'Development-only template for the Task Template authoring surface.',
            'task_description' => 'This template is not used by the seeded automatic reply scenario.',
            'assigned_to_type' => 'App\\Models\\User',
            'assigned_to_id' => $this->refs['owner'],
            'assigned_to_strategy' => 'contact_owner',
            'responsible_party' => 'internal',
            'responsible_type' => 'App\\Modules\\Core\\Access\\Models\\Team',
            'responsible_id' => $this->refs['team'] ?? $this->refs['owner'],
            'priority' => 'normal',
            'due_offset_minutes' => 1440,
            'link_defaults' => [
                ['source' => 'current_contact', 'role' => 'subject'],
                ['source' => 'current_subject', 'role' => 'context'],
            ],
            'defaults' => ['notify_assignee' => true],
            'is_active' => true,
            'is_customized' => true,
            'customized_at' => $this->now->subDays(3),
            'meta' => $this->meta(['scenario' => 'task_template_field_coverage']),
        ]);

        $taskMeta = $this->meta([
            'scenario' => 'client_reply_follow_up',
            'task_template' => [
                'id' => (int) $template->id,
                'key' => (string) $template->key,
                'source' => $template->source ?? null,
                'source_version' => $template->source_version ?? null,
            ],
            'automation' => [
                'surface' => 'flow_routes',
                'execution_key' => 'surface-showcase:client-reply-follow-up',
                'occurred_at' => $this->now->subHours(2)->toIso8601String(),
                'provenance' => $this->clientReplyProvenance(),
            ],
        ]);
        $taskValues = [
            'assigned_to_type' => $template->assigned_to_type ?? null,
            'assigned_to_id' => $template->assigned_to_id ?? null,
            'responsible_party' => $template->responsible_party ?? 'internal',
            'responsible_type' => $template->responsible_type ?? null,
            'responsible_id' => $template->responsible_id ?? null,
            'task_template_id' => (int) $template->id,
            'task_template_key' => (string) $template->key,
            'source' => 'module',
            'title' => (string) $template->title,
            'description' => filled($template->task_description ?? null)
                ? (string) $template->task_description
                : ($template->description ?? null),
            'status' => 'open',
            'priority' => $template->priority ?? null,
            'due_at' => $this->now->addMinutes((int) ($template->due_offset_minutes ?? 0)),
            'completed_at' => null,
            'canceled_at' => null,
            'canceled_reason' => null,
            'archived_at' => null,
            'meta' => $taskMeta,
        ];

        $existingShowcaseTaskId = DB::table('tasks')
            ->where('meta', 'like', '%'.self::MARKER.'%')
            ->where('meta', 'like', '%client_reply_follow_up%')
            ->value('id');

        if (! is_numeric($existingShowcaseTaskId)) {
            $existingShowcaseTaskId = DB::table('tasks')
                ->where('title', 'Call Nina Patel — asked to start her VA preapproval')
                ->where('meta', 'like', '%'.self::MARKER.'%')
                ->value('id');
        }

        if (is_numeric($existingShowcaseTaskId)) {
            $this->refs['task_high_intent'] = (int) $existingShowcaseTaskId;
            $this->update('tasks', $this->refs['task_high_intent'], $taskValues);
        } else {
            $this->refs['task_high_intent'] = $this->row('tasks', [
                'task_template_key' => (string) $template->key,
                'source' => 'module',
                'meta' => $taskMeta,
            ], $taskValues);
        }

        $completedTaskValues = [
            'assigned_to_type' => 'App\\Models\\User',
            'assigned_to_id' => $this->refs['loan_officer'],
            'responsible_party' => 'contact',
            'responsible_type' => 'App\\Modules\\Core\\Models\\Contact',
            'responsible_id' => $this->refs['contact_marcus'],
            'task_template_id' => null,
            'task_template_key' => null,
            'source' => 'manual',
            'description' => 'Completed example with history and ownership populated.',
            'status' => 'completed',
            'priority' => 'normal',
            'due_at' => $this->now->subDays(2),
            'completed_at' => $this->now->subDays(2)->addMinutes(30),
            'canceled_at' => null,
            'canceled_reason' => null,
            'archived_at' => null,
            'meta' => $this->meta(['scenario' => 'completed_manual_task', 'outcome' => 'approved']),
        ];
        $completedTaskId = DB::table('tasks')
            ->where('title', 'Review Marcus Chen closing document')
            ->where('meta', 'like', '%'.self::MARKER.'%')
            ->value('id');

        if (is_numeric($completedTaskId)) {
            $this->refs['task_completed'] = (int) $completedTaskId;
            $this->update('tasks', $this->refs['task_completed'], [
                'title' => 'Review Marcus Chen closing document',
                ...$completedTaskValues,
            ]);
        } else {
            $this->refs['task_completed'] = $this->row('tasks', [
                'title' => 'Review Marcus Chen closing document',
            ], $completedTaskValues);
        }

        $this->row('task_links', [
            'task_id' => $this->refs['task_completed'],
            'linkable_type' => 'App\\Modules\\Core\\Models\\Contact',
            'linkable_id' => $this->refs['contact_marcus'],
            'role' => 'subject',
        ]);

        $canceledTaskValues = [
            'assigned_to_type' => 'App\\Models\\User',
            'assigned_to_id' => $this->refs['owner'],
            'responsible_party' => 'internal',
            'responsible_type' => 'App\\Modules\\Core\\Access\\Models\\Team',
            'responsible_id' => $this->refs['team'] ?? null,
            'task_template_id' => (int) $template->id,
            'source' => 'system',
            'description' => 'Historical task retained to make cancellation and archive states visible.',
            'status' => 'canceled',
            'priority' => 'low',
            'due_at' => $this->now->subDays(10),
            'completed_at' => null,
            'canceled_at' => $this->now->subDays(9),
            'canceled_reason' => 'Duplicate of the active contextual task.',
            'archived_at' => $this->now->subDays(8),
            'meta' => $this->meta([
                'scenario' => 'canceled_duplicate_task',
                'duplicate_of_task_id' => $this->refs['task_high_intent'],
            ]),
        ];
        $canceledTaskId = DB::table('tasks')
            ->where('title', 'Duplicate follow-up task · canceled example')
            ->where('meta', 'like', '%'.self::MARKER.'%')
            ->value('id');

        if (is_numeric($canceledTaskId)) {
            $this->update('tasks', (int) $canceledTaskId, [
                'task_template_key' => (string) $template->key,
                'title' => 'Duplicate follow-up task · canceled example',
                ...$canceledTaskValues,
            ]);
        } else {
            $this->row('tasks', [
                'task_template_key' => (string) $template->key,
                'title' => 'Duplicate follow-up task · canceled example',
            ], $canceledTaskValues);
        }

        if ((string) $template->key !== 'showcase_high_intent_reply_follow_up') {
            DB::table('task_templates')
                ->where('key', 'showcase_high_intent_reply_follow_up')
                ->where('meta', 'like', '%'.self::MARKER.'%')
                ->delete();
        }

        $this->seededModules[] = 'tasks';
    }

    private function seedMedia(): void
    {
        if (! $this->moduleAvailable('media', 'media_assets')) {
            return;
        }

        $this->refs['media_signature'] = $this->row('media_assets', [
            'uuid' => '41f3f445-4c50-4f15-a031-3c7a65b50a01',
        ], [
            'uploaded_by_type' => 'App\\Models\\User',
            'uploaded_by_id' => $this->refs['owner'],
            'title' => 'Stacey signature headshot',
            'kind' => 'image',
            'disk' => 'spaces',
            'path' => 'showcase/media/stacey-signature-headshot.jpg',
            'original_filename' => 'stacey-headshot.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size_bytes' => 184320,
            'checksum_sha256' => hash('sha256', self::MARKER.'-signature-image'),
            'perceptual_hash' => '91af20bc44de7710',
            'perceptual_hash_algorithm' => 'dhash',
            'image_width' => 1200,
            'image_height' => 1200,
            'visibility' => 'public',
            'source' => 'crm',
            'meta' => $this->meta([
                'variants' => [
                    'signature' => ['width' => 96, 'height' => 96, 'format' => 'webp'],
                    'display' => ['width' => 640, 'height' => 640, 'format' => 'webp'],
                ],
                'alt_text' => 'Stacey, your mortgage advisor',
            ]),
            'archived_at' => null,
        ]);

        $this->row('media_assets', [
            'uuid' => '077bb425-8321-410d-a272-d9709a8d8983',
        ], [
            'uploaded_by_type' => 'App\\Models\\User',
            'uploaded_by_id' => $this->refs['owner'],
            'title' => 'Archived signature image',
            'kind' => 'image',
            'disk' => 'spaces',
            'path' => 'showcase/media/archived-signature.jpg',
            'original_filename' => 'old-signature.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size_bytes' => 98304,
            'checksum_sha256' => hash('sha256', self::MARKER.'-archived-signature'),
            'perceptual_hash' => '10de44bc20af9177',
            'perceptual_hash_algorithm' => 'dhash',
            'image_width' => 600,
            'image_height' => 600,
            'visibility' => 'private',
            'source' => 'crm',
            'meta' => $this->meta(['archive_reason' => 'Replaced by current headshot']),
            'archived_at' => $this->now->subYear(),
        ]);

        $this->seededModules[] = 'media';
    }

    private function seedMessaging(): void
    {
        if (! $this->moduleAvailable('messaging', 'scheduled_messages')) {
            return;
        }

        $this->refs['reply_profile_placeholder'] = null;

        $this->refs['consent_email'] = $this->row('message_consents', [
            'contact_id' => $this->refs['contact_nina'],
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'mortgage_education',
        ], [
            'consented_at' => $this->now->subMonths(2),
            'source' => 'webinar_registration',
            'ip_address' => '192.0.2.15',
            'user_agent' => 'Mozilla/5.0 Showcase Browser',
            'meta' => $this->meta(['disclosure_version' => '2026-08-01', 'proof' => 'checkbox']),
        ]);

        $this->refs['consent_sms'] = $this->row('message_consents', [
            'contact_id' => $this->refs['contact_nina'],
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'mortgage_education',
        ], [
            'consented_at' => $this->now->subMonths(2),
            'source' => 'webinar_registration',
            'ip_address' => '192.0.2.15',
            'user_agent' => 'Mozilla/5.0 Showcase Browser',
            'meta' => $this->meta(['disclosure_version' => '2026-08-01', 'proof' => 'optional_sms_checkbox']),
        ]);

        $revokedConsent = $this->row('message_consents', [
            'contact_id' => $this->refs['contact_marcus'],
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'past_client_nurture',
        ], [
            'consented_at' => $this->now->subYears(2),
            'source' => 'legacy_import',
            'ip_address' => '198.51.100.20',
            'user_agent' => 'Imported consent record',
            'meta' => $this->meta(['import_batch_id' => $this->refs['import_batch']]),
        ]);
        $this->row('consent_revocations', [
            'contact_id' => $this->refs['contact_marcus'],
            'message_consent_id' => $revokedConsent,
        ], [
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'past_client_nurture',
            'reason' => 'unsubscribe',
            'revoked_at' => $this->now->subDays(2),
            'source' => 'provider_webhook',
            'ip_address' => '198.51.100.20',
            'user_agent' => 'Resend unsubscribe event',
            'meta' => $this->meta(['provider_event_id' => 'showcase-unsubscribe-001']),
        ]);

        $this->refs['template'] = $this->row('message_templates', [
            'key' => 'showcase_va_high_intent_follow_up',
        ], [
            'name' => 'Showcase · VA high-intent follow-up',
            'description' => 'Fully populated email/SMS authoring example with CTA and signature media.',
            'channel' => 'sms',
            'status' => 'active',
            'composition_context_key' => 'campaign',
            'composition_family_key' => 'va_homebuyer',
            'current_version_id' => null,
            'source' => 'manual',
            'source_version' => 'showcase-1',
            'is_customized' => true,
            'customized_at' => $this->now->subDays(5),
        ]);

        $this->refs['template_version'] = $this->row('message_template_versions', [
            'message_template_id' => $this->refs['template'],
            'version' => 1,
        ], [
            'subject' => 'Ready to start your VA preapproval?',
            'content' => [
                'message' => 'Hi {first_name}, after the VA Homebuyer Game Plan, would you like me to help you start your preapproval? Reply YES and I will call you. —Stacey',
                'body' => '<p>Hi {first_name},</p><p>Would you like help starting your VA preapproval?</p>{cta}{media}',
                'cta' => ['label' => 'Start my preapproval', 'url' => 'https://example.test/start'],
                'media' => isset($this->refs['media_signature']) ? [['media_asset_id' => $this->refs['media_signature'], 'size' => 'signature']] : [],
            ],
            'renderer_key' => 'token_message',
            'renderer_version' => '1',
            'content_hash' => hash('sha256', self::MARKER.'-template-v1'),
            'created_by' => $this->refs['owner'],
        ]);
        $this->update('message_templates', $this->refs['template'], [
            'current_version_id' => $this->refs['template_version'],
        ]);

        $this->refs['email_template'] = $this->row('message_templates', [
            'key' => 'showcase_realtor_signature_email',
        ], [
            'name' => 'Showcase · Realtor outreach with signature image',
            'description' => 'Email example with a deliberately small signature image and document link.',
            'channel' => 'email',
            'status' => 'active',
            'composition_context_key' => 'broadcast',
            'composition_family_key' => 'realtor_outreach',
            'current_version_id' => null,
            'source' => 'manual',
            'source_version' => 'showcase-1',
            'is_customized' => true,
            'customized_at' => $this->now->subDays(5),
        ]);
        $this->refs['email_template_version'] = $this->row('message_template_versions', [
            'message_template_id' => $this->refs['email_template'],
            'version' => 1,
        ], [
            'subject' => 'Don’t send me a buyer',
            'content' => [
                'subject' => 'Don’t send me a buyer',
                'body' => '<p>Hi {first_name},</p><p>What if your next buyer arrived already prepared?</p>{cta}<p>—Stacey</p>{media}',
                'cta' => ['label' => 'See how it works', 'url' => 'https://example.test/realtors'],
                'media' => isset($this->refs['media_signature']) ? [['media_asset_id' => $this->refs['media_signature'], 'size' => 'signature', 'width' => 96]] : [],
                'attachments' => [['name' => 'VA Homebuyer Game Plan.pdf', 'url' => 'https://example.test/files/va-guide.pdf']],
            ],
            'renderer_key' => 'email_html',
            'renderer_version' => '1',
            'content_hash' => hash('sha256', self::MARKER.'-email-template-v1'),
            'created_by' => $this->refs['owner'],
        ]);
        $this->update('message_templates', $this->refs['email_template'], [
            'current_version_id' => $this->refs['email_template_version'],
        ]);

        $this->refs['preset'] = $this->row('message_template_presets', [
            'key' => 'showcase_va_high_intent_follow_up',
        ], [
            'name' => 'Showcase · VA High Intent Follow-up',
            'description' => 'Seeded preset for inspecting the complete template workspace.',
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar_follow_up',
            'message_type' => 'campaign_step',
            'payload_class' => 'App\\Modules\\Messaging\\Payloads\\SmsPayload',
            'queue' => 'marketing',
            'dispatch_keys' => ['campaign_step', 'high_intent_reply'],
            'payload' => ['message' => 'Hi {first_name}, ready to begin? Reply YES. —Stacey'],
            'tokens' => ['first_name', 'contact_name', 'cta', 'media'],
            'status' => 'active',
            'is_active' => true,
            'source' => 'manual',
            'source_config_path' => 'showcase.messaging.va_high_intent',
            'source_version' => 1,
            'is_customized' => true,
            'customized_at' => $this->now->subDays(5),
            'last_synced_at' => $this->now->subDays(5),
            'meta' => $this->meta(['owner' => 'Stacey', 'intent' => 'preapproval_start']),
        ]);

        $this->row('message_template_preset_assignments', [
            'message_template_preset_id' => $this->refs['preset'],
            'surface' => 'campaigns',
            'definition_key' => 'showcase_va_high_intent_follow_up',
        ], [
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar_follow_up',
            'message_type' => 'campaign_step',
            'reply_profile_key' => 'showcase_high_intent',
            'campaign_key' => 'showcase_va_nurture',
            'campaign_step' => 1,
            'campaign_step_variant_key' => 'sms',
            'source_config_path' => 'showcase.campaigns.va_nurture.steps.0',
            'context_type' => 'App\\Modules\\Core\\Models\\Contact',
            'context_id' => $this->refs['contact_nina'],
            'is_active' => true,
            'starts_at' => $this->now->subMonth(),
            'ends_at' => $this->now->addYear(),
            'meta' => $this->meta(['assignment_note' => 'Complete field coverage example']),
        ]);

        $this->row('message_template_catalog_entries', [
            'message_template_preset_id' => $this->refs['preset'],
            'item_key' => 'showcase_va_high_intent_follow_up',
        ], [
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar_follow_up',
            'module_key' => 'campaigns',
            'module_label' => 'Campaigns',
            'surface' => 'campaigns',
            'group_key' => 'va_homebuyer',
            'group_label' => 'VA Homebuyer',
            'item_label' => 'High-intent follow-up',
            'item_order' => 10,
            'usage_type' => 'campaign_step',
            'source' => 'manual',
            'source_config_path' => 'showcase.messaging.catalog.va_high_intent',
            'context_type' => 'App\\Modules\\Core\\Models\\Contact',
            'context_id' => $this->refs['contact_nina'],
            'is_active' => true,
            'meta' => $this->meta(['search_terms' => ['VA', 'reply', 'preapproval']]),
        ]);

        $this->row('message_template_composition_layers', [
            'identity_key' => hash('sha256', self::MARKER.'-composition-layer'),
        ], [
            'scope_type' => 'message',
            'channel' => 'sms',
            'client_key' => 'showcase-client',
            'context_key' => 'campaign',
            'family_key' => 'va_homebuyer',
            'message_template_id' => $this->refs['template'],
            'payload' => ['signature' => '—Stacey', 'compliance' => 'Reply STOP to opt out.'],
            'source' => 'client',
            'source_version' => 'showcase-1',
            'is_customized' => true,
            'customized_at' => $this->now->subDays(5),
        ]);

        $this->refs['chain'] = $this->row('message_chains', [
            'key' => 'showcase_va_reply_chain',
        ], [
            'name' => 'Showcase · VA reply chain',
            'description' => 'A complete chain used by campaign, webinar, and reply-context surfaces.',
            'status' => 'active',
            'current_version_id' => null,
            'source' => 'manual',
            'source_version' => 'showcase-1',
            'is_customized' => true,
            'customized_at' => $this->now->subDays(4),
        ]);

        $this->refs['chain_version'] = $this->row('message_chain_versions', [
            'message_chain_id' => $this->refs['chain'],
            'version' => 1,
        ], [
            'exit_conditions' => [['event' => 'inbound.reply.high_intent'], ['event' => 'consent.revoked']],
            'content_hash' => hash('sha256', self::MARKER.'-chain-v1'),
            'published_at' => $this->now->subDays(4),
            'created_by' => $this->refs['owner'],
        ]);
        $this->update('message_chains', $this->refs['chain'], [
            'current_version_id' => $this->refs['chain_version'],
        ]);

        $this->refs['chain_step'] = $this->row('message_chain_steps', [
            'message_chain_version_id' => $this->refs['chain_version'],
            'key' => 'ask_to_begin',
        ], [
            'name' => 'Ask whether they want to begin',
            'sort_order' => 10,
            'timing_type' => 'anchored',
            'anchor_key' => 'webinar.ended',
            'offset_seconds' => 3600,
            'day_offset' => 1,
            'local_time' => '09:15:00',
            'variant_strategy' => 'first_available',
            'advance_policy' => 'first_sent',
            'conditions' => [['field' => 'contact.status', 'operator' => 'not_in', 'value' => ['closed']]],
            'is_active' => true,
        ]);

        $this->refs['chain_variant'] = $this->row('message_chain_step_variants', [
            'message_chain_step_id' => $this->refs['chain_step'],
            'key' => 'sms',
        ], [
            'sort_order' => 10,
            'message_template_version_id' => $this->refs['template_version'],
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar_follow_up',
            'message_type' => 'campaign_step',
            'reply_profile_key' => 'showcase_high_intent',
            'queue' => 'marketing',
            'dependency_policy' => ['requires' => ['sms_consent', 'valid_phone']],
            'conditions' => [['field' => 'contact.phone', 'operator' => 'present']],
            'is_active' => true,
        ]);

        $this->refs['chain_enrollment'] = $this->row('message_chain_enrollments', [
            'dedupe_key' => 'showcase-va-reply-chain-nina',
        ], [
            'message_chain_version_id' => $this->refs['chain_version'],
            'recipient_type' => 'App\\Modules\\Core\\Models\\Contact',
            'recipient_id' => $this->refs['contact_nina'],
            'context_type' => 'App\\Modules\\Core\\Models\\Contact',
            'context_id' => $this->refs['contact_nina'],
            'origin_type' => 'App\\Modules\\Messaging\\Models\\MessageChain',
            'origin_id' => $this->refs['chain'],
            'surface' => 'campaigns',
            'current_message_chain_step_id' => $this->refs['chain_step'],
            'next_action_at' => $this->now->addDay(),
            'status' => 'active',
            'started_at' => $this->now->subDays(2),
            'paused_at' => $this->now->subDay(),
            'resumed_at' => $this->now->subHours(20),
            'exited_at' => null,
            'exit_reason_code' => null,
            'completed_at' => null,
            'cancelled_at' => null,
        ]);

        $this->refs['chain_enrollment_history'] = $this->row('message_chain_enrollments', [
            'dedupe_key' => 'showcase-va-reply-chain-marcus-history',
        ], [
            'message_chain_version_id' => $this->refs['chain_version'],
            'recipient_type' => 'App\\Modules\\Core\\Models\\Contact',
            'recipient_id' => $this->refs['contact_marcus'],
            'context_type' => 'App\\Modules\\Core\\Models\\Contact',
            'context_id' => $this->refs['contact_marcus'],
            'origin_type' => 'App\\Modules\\Messaging\\Models\\MessageChain',
            'origin_id' => $this->refs['chain'],
            'surface' => 'campaigns',
            'current_message_chain_step_id' => $this->refs['chain_step'],
            'next_action_at' => $this->now->subMonth(),
            'status' => 'cancelled',
            'started_at' => $this->now->subMonths(2),
            'paused_at' => $this->now->subMonths(2)->addDay(),
            'resumed_at' => $this->now->subMonths(2)->addDays(2),
            'exited_at' => $this->now->subMonth(),
            'exit_reason_code' => 'consent_revoked',
            'completed_at' => $this->now->subMonth()->subMinute(),
            'cancelled_at' => $this->now->subMonth(),
        ]);

        $messagePayload = [
            'to' => '+13125550103',
            'message' => "Hey Nina, thanks for joining today’s VA Homebuyer Game Plan Webinar. We hope it answered your questions.\n\nReplay:\nhttps://example.test/webinars/va-homebuyer-game-plan/replay\n\nFor a personalized VA homebuying strategy, reply CALL and we’ll schedule your free consultation.\n\n— Stacey",
        ];

        $this->refs['outbound_high_intent'] = $this->row('scheduled_messages', [
            'dedupe_key' => 'showcase-outbound-high-intent-nina',
        ], [
            'recipient_type' => 'App\\Modules\\Core\\Models\\Contact',
            'recipient_id' => $this->refs['contact_nina'],
            'context_type' => 'App\\Modules\\Core\\Models\\Contact',
            'context_id' => $this->refs['contact_nina'],
            'behavior_owner_type' => null,
            'behavior_owner_id' => null,
            'message_template_version_id' => null,
            'message_chain_enrollment_id' => null,
            'message_chain_step_variant_id' => null,
            'channel' => 'sms',
            'message_type' => 'post_attended',
            'reply_profile_key' => 'webinar_homebuyer',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => 'App\\Modules\\Messaging\\Payloads\\SmsPayload',
            'queue' => 'post_event',
            'dispatch_keys' => ['webinar_ended'],
            'definition_config_path' => 'messaging.sms.definitions.transactional.webinar.va-homebuyer-game-plan.post_attended',
            'payload' => $messagePayload,
            'send_at' => $this->now->subHours(3),
            'status' => 'sent',
            'operational_state' => 'active',
            'manual_schedule_override_at' => $this->now->subHours(4),
            'provider_idempotency_key' => 'showcase-provider-idempotency-nina',
            'meta' => $this->meta(['provider' => 'telnyx', 'from' => '+13125550999', 'webinar' => 'VA Homebuyer Game Plan']),
        ]);

        $this->refs['outbound_chain_history'] = $this->row('scheduled_messages', [
            'dedupe_key' => 'showcase-chain-message-marcus-history',
        ], [
            'recipient_type' => 'App\\Modules\\Core\\Models\\Contact',
            'recipient_id' => $this->refs['contact_marcus'],
            'context_type' => 'App\\Modules\\Core\\Models\\Contact',
            'context_id' => $this->refs['contact_marcus'],
            'behavior_owner_type' => 'App\\Modules\\Messaging\\Models\\MessageChainStep',
            'behavior_owner_id' => $this->refs['chain_step'],
            'message_template_version_id' => $this->refs['template_version'],
            'message_chain_enrollment_id' => $this->refs['chain_enrollment_history'],
            'message_chain_step_variant_id' => $this->refs['chain_variant'],
            'channel' => 'sms',
            'message_type' => 'campaign_step',
            'reply_profile_key' => 'showcase_high_intent',
            'purpose' => 'marketing',
            'scope' => 'webinar_follow_up',
            'payload_class' => 'App\\Modules\\Messaging\\Payloads\\SmsPayload',
            'queue' => 'marketing',
            'dispatch_keys' => ['campaign_step'],
            'definition_config_path' => 'showcase.messaging.va_high_intent',
            'payload' => [
                'to' => '+13125550102',
                'message' => 'Hi Marcus, checking in after the VA Homebuyer Game Plan. Reply if you would like to talk through your next step. —Stacey',
            ],
            'send_at' => $this->now->subMonths(2)->addDay(),
            'status' => 'sent',
            'operational_state' => 'active',
            'manual_schedule_override_at' => null,
            'provider_idempotency_key' => 'showcase-chain-message-marcus-provider-key',
            'meta' => $this->meta(['scenario' => 'message_chain_history']),
        ]);

        $this->refs['outbound_acknowledgement'] = $this->row('scheduled_messages', [
            'dedupe_key' => 'showcase-reply-acknowledgement-nina',
        ], [
            'recipient_type' => 'App\\Modules\\Core\\Models\\Contact',
            'recipient_id' => $this->refs['contact_nina'],
            'context_type' => 'App\\Modules\\Core\\Models\\Contact',
            'context_id' => $this->refs['contact_nina'],
            'behavior_owner_type' => null,
            'behavior_owner_id' => null,
            'message_template_version_id' => null,
            'message_chain_enrollment_id' => null,
            'message_chain_step_variant_id' => null,
            'channel' => 'sms',
            'message_type' => 'reply_acknowledgement',
            'reply_profile_key' => 'webinar_homebuyer',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => 'App\\Modules\\Messaging\\Payloads\\SmsPayload',
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['flow_route_send_message'],
            'definition_config_path' => 'messaging.sms.definitions.transactional.webinar.va-homebuyer-game-plan.reply_acknowledgement',
            'payload' => [
                'to' => '+13125550103',
                'message' => 'Got it! We received your message. One of us will reach out shortly. Ready to get started now? Begin here: https://plumcreekfunding.my1003app.com/1645881/register — Stacey',
            ],
            'send_at' => $this->now->subHours(2)->addMinute(),
            'status' => 'sent',
            'operational_state' => 'active',
            'manual_schedule_override_at' => null,
            'provider_idempotency_key' => 'showcase-provider-idempotency-reply-ack',
            'meta' => $this->meta([
                'message_role' => 'reply',
                'reply_purpose' => 'high_intent_acknowledgement',
            ]),
        ]);

        $this->refs['outbound_future'] = $this->row('scheduled_messages', [
            'dedupe_key' => 'showcase-future-email-ava',
        ], [
            'recipient_type' => 'App\\Modules\\Core\\Models\\Contact',
            'recipient_id' => $this->refs['contact_ava'],
            'context_type' => 'App\\Modules\\Core\\Models\\Contact',
            'context_id' => $this->refs['contact_ava'],
            'behavior_owner_type' => null,
            'behavior_owner_id' => null,
            'message_template_version_id' => $this->refs['email_template_version'],
            'message_chain_enrollment_id' => null,
            'message_chain_step_variant_id' => null,
            'channel' => 'email',
            'message_type' => 'direct',
            'reply_profile_key' => 'showcase_high_intent',
            'purpose' => 'transactional',
            'scope' => 'direct_message',
            'payload_class' => 'App\\Modules\\Messaging\\Payloads\\EmailPayload',
            'queue' => 'emails',
            'dispatch_keys' => ['direct_message'],
            'definition_config_path' => 'showcase.direct_message',
            'payload' => [
                'to' => 'ava.morgan+showcase@example.test',
                'subject' => 'Your mortgage strategy call details',
                'body' => '<p>Hi Ava,</p><p>Your appointment is confirmed for 7 PM Central.</p><p>—Stacey</p>{media}',
                'media' => isset($this->refs['media_signature']) ? [['media_asset_id' => $this->refs['media_signature'], 'size' => 'signature', 'width' => 96]] : [],
            ],
            'send_at' => $this->now->addDay(),
            'status' => 'pending',
            'operational_state' => 'held',
            'manual_schedule_override_at' => $this->now,
            'provider_idempotency_key' => 'showcase-future-provider-key',
            'meta' => $this->meta(['hold_reason' => 'Operator review']),
        ]);

        $this->refs['delivery_attempt'] = $this->row('scheduled_message_delivery_attempts', [
            'scheduled_message_id' => $this->refs['outbound_high_intent'],
            'attempt_number' => 1,
        ], [
            'claim_token' => 'showcase-claim-token-001',
            'status' => 'sent',
            'claimed_at' => $this->now->subHours(3)->subSeconds(5),
            'lease_expires_at' => $this->now->subHours(3)->addMinutes(2),
            'provider_submission_started_at' => $this->now->subHours(3)->subSeconds(3),
            'completed_at' => $this->now->subHours(3),
            'destination' => '+13125550103',
            'provider' => 'telnyx',
            'provider_message_id' => 'showcase-telnyx-message-001',
            'reason_code' => 'accepted',
            'reason' => 'Accepted by provider and delivered to the handset.',
        ]);

        $this->row('scheduled_message_outbox_events', [
            'scheduled_message_id' => $this->refs['outbound_high_intent'],
        ], [
            'delivery_attempt_id' => $this->refs['delivery_attempt'],
            'event_type' => 'sent',
            'occurred_at' => $this->now->subHours(3),
            'reason_code' => 'provider_accepted',
            'reason' => 'Telnyx accepted the message.',
            'status' => 'published',
            'available_at' => $this->now->subHours(3),
            'claim_token' => 'showcase-outbox-claim',
            'claim_expires_at' => $this->now->subHours(2),
            'attempts' => 1,
            'last_attempted_at' => $this->now->subHours(3),
            'published_at' => $this->now->subHours(3)->addSeconds(2),
            'last_error' => 'Earlier publish attempt timed out; retry succeeded.',
        ]);

        $this->row('scheduled_message_render_contexts', [
            'scheduled_message_id' => $this->refs['outbound_high_intent'],
        ], [
            'values' => ['first_name' => 'Nina', 'contact_name' => 'Nina Patel', 'sender_name' => 'Stacey'],
            'content_hash' => hash('sha256', self::MARKER.'-render-context'),
            'rendered_at' => $this->now->subHours(3)->subMinute(),
            'expires_at' => $this->now->addDays(30),
        ]);

        $this->row('scheduled_message_components', [
            'scheduled_message_id' => $this->refs['outbound_high_intent'],
            'sort_order' => 1,
        ], [
            'message_template_version_id' => $this->refs['template_version'],
            'role' => 'consent_acknowledgement',
            'intent_key' => 'showcase_sms_consent',
            'message_consent_id' => $this->refs['consent_sms'],
            'placement_key' => 'footer',
        ]);

        $this->row('scheduled_message_cta_engagements', [
            'scheduled_message_id' => $this->refs['outbound_high_intent'],
            'cta_key' => 'start_preapproval',
            'classification' => 'likely_human',
        ], [
            'occurrence_count' => 2,
            'first_occurred_at' => $this->now->subHours(2)->subMinutes(30),
            'last_occurred_at' => $this->now->subHours(2),
        ]);

        $this->row('scheduled_message_edits', [
            'scheduled_message_id' => $this->refs['outbound_future'],
            'action' => 'replace',
        ], [
            'message_template_version_id' => $this->refs['email_template_version'],
            'actor_id' => $this->refs['owner'],
            'actor_email' => 'stacey.showcase@example.test',
            'override_payload' => ['subject' => 'Updated appointment information', 'body' => 'The time has changed to 7 PM Central.'],
            'reason' => 'Underlying event time changed.',
        ]);

        $this->row('scheduled_message_operational_events', [
            'scheduled_message_id' => $this->refs['outbound_future'],
            'action' => 'hold',
        ], [
            'actor_id' => $this->refs['owner'],
            'actor_email' => 'stacey.showcase@example.test',
            'reason' => 'Review timing after webinar resync.',
            'previous_send_at' => $this->now->addHours(23),
            'current_send_at' => $this->now->addDay(),
            'previous_operational_state' => 'active',
            'current_operational_state' => 'held',
            'occurred_at' => $this->now,
        ]);

        $this->row('scheduled_message_bulk_edits', [
            'source_scope' => 'message_chain',
            'source_id' => $this->refs['chain'],
            'message_template_version_id' => $this->refs['template_version'],
        ], [
            'maximum_scheduled_message_id' => max($this->refs['outbound_high_intent'], $this->refs['outbound_future']),
            'channel' => 'sms',
            'matching_count_at_creation' => 12,
            'override_payload' => ['send_at' => $this->now->addDay()->toIso8601String()],
            'action' => 'reschedule',
            'actor_id' => $this->refs['owner'],
            'actor_email' => 'stacey.showcase@example.test',
            'reason' => 'Webinar moved from Eastern to Central time.',
        ]);

        $this->refs['suppression'] = $this->row('message_suppressions', [
            'channel' => 'email',
            'destination' => 'marcus.chen+showcase@example.test',
        ], [
            'reason' => 'bounce',
            'provider' => 'resend',
            'source_event_id' => 'showcase-resend-bounce-001',
            'suppressed_at' => $this->now->subDays(3),
            'released_at' => $this->now->subDay(),
            'meta' => $this->meta(['smtp_code' => '550', 'diagnostic' => 'Mailbox unavailable']),
        ]);

        $this->refs['permission_invitation'] = $this->row('contact_permission_invitations', [
            'token' => hash('sha256', self::MARKER.'-permission-token'),
        ], [
            'contact_id' => $this->refs['contact_olivia'],
            'scheduled_message_id' => $this->refs['outbound_future'],
            'context_type' => 'App\\Modules\\Core\\Models\\ContactImportBatch',
            'context_id' => $this->refs['import_batch'],
            'channel' => 'email',
            'source' => 'imported_contact',
            'status' => 'accepted',
            'claimed_at' => $this->now->subDays(6),
            'sent_at' => $this->now->subDays(6),
            'failed_at' => $this->now->subDays(6)->addMinutes(2),
            'accepted_at' => $this->now->subDays(5),
            'accepted_channels' => ['email', 'sms'],
            'failure_reason' => 'First provider attempt timed out; retry succeeded.',
            'meta' => $this->meta(['landing_page' => '/permissions/showcase']),
        ]);

        $this->seededModules[] = 'messaging';
    }

    private function seedInboundMessaging(): void
    {
        if (! $this->moduleAvailable('inbound_messaging', 'inbound_messages')) {
            return;
        }

        $clientReplyProfile = DB::table('inbound_reply_profiles')
            ->where('key', 'webinar_homebuyer')
            ->where('is_active', true)
            ->first();

        if (is_object($clientReplyProfile)) {
            $this->refs['reply_profile'] = (int) $clientReplyProfile->id;
            $clientReplyIntent = DB::table('inbound_reply_intents')
                ->where('inbound_reply_profile_id', $this->refs['reply_profile'])
                ->where('key', 'high_intent')
                ->first();
            $this->refs['reply_intent'] = is_object($clientReplyIntent)
                ? (int) $clientReplyIntent->id
                : null;
        } else {
            $this->refs['reply_profile'] = $this->row('inbound_reply_profiles', [
                'key' => 'showcase_high_intent',
            ], [
                'label' => 'Showcase · High-intent replies',
                'description' => 'Recognizes clear requests to begin, schedule, call, or apply.',
                'is_active' => true,
                'source' => 'database',
                'source_config_path' => 'showcase.inbound.high_intent',
                'source_version' => 'showcase-1',
                'is_customized' => true,
                'customized_at' => $this->now->subDays(4),
                'last_synced_at' => $this->now->subDays(4),
                'meta' => $this->meta(['creates_task' => true, 'priority' => 'urgent']),
            ]);

            $this->refs['reply_intent'] = $this->row('inbound_reply_intents', [
                'inbound_reply_profile_id' => $this->refs['reply_profile'],
                'key' => 'high_intent',
            ], [
                'label' => 'High intent',
                'description' => 'The person explicitly asked to begin or requested a call.',
                'is_active' => true,
                'sort_order' => 10,
            ]);

            $this->row('inbound_reply_rules', [
                'inbound_reply_intent_id' => $this->refs['reply_intent'],
                'match_type' => 'keyword',
                'normalized_value' => 'yes',
            ], [
                'value' => 'YES',
                'is_active' => true,
                'sort_order' => 10,
            ]);
        }

        $this->refs['email_route'] = $this->row('inbound_email_routes', [
            'key' => 'showcase_realtor_replies',
        ], [
            'local_part' => 'realtor-replies+showcase',
            'label' => 'Showcase · Realtor replies',
            'source' => 'broadcasts',
            'context_key' => 'showcase_realtor_outreach',
            'is_active' => true,
            'contact_extraction_enabled' => true,
            'contact_extraction_definition' => [
                'strategy' => 'reply_address_then_body',
                'fields' => ['email', 'phone', 'name'],
            ],
        ]);

        $this->refs['webhook_receipt'] = $this->row('webhook_inbox_receipts', [
            'receipt_key' => hash('sha256', self::MARKER.'-inbound-receipt'),
        ], [
            'client_key' => 'showcase-client',
            'provider' => 'telnyx',
            'provider_event_id' => 'showcase-inbound-event-001',
            'signature_fingerprint' => hash('sha256', self::MARKER.'-signature'),
            'event_type' => 'message.received',
            'payload_fingerprint' => hash('sha256', self::MARKER.'-payload'),
            'payload' => [
                'data' => [
                    'id' => 'showcase-inbound-event-001',
                    'payload' => ['from' => '+13125550103', 'to' => '+13125550999', 'text' => 'CALL'],
                ],
            ],
            'status' => 'completed',
            'attempts' => 2,
            'claim_token' => '38868bcc-d436-4fc4-90a5-30d3be31a85a',
            'claim_expires_at' => $this->now->subHours(2),
            'last_attempted_at' => $this->now->subHours(2)->subMinutes(2),
            'completed_at' => $this->now->subHours(2),
            'failed_at' => $this->now->subHours(2)->subMinutes(3),
            'outcome' => ['inbound_message_id' => 'resolved-after-insert', 'contact_matched' => true],
            'last_error' => 'First contact match attempt timed out; retry completed.',
        ]);

        $this->refs['inbound_high_intent'] = $this->row('inbound_messages', [
            'provider_event_key' => hash('sha256', self::MARKER.'-inbound-event-key'),
        ], [
            'webhook_inbox_receipt_id' => $this->refs['webhook_receipt'],
            'sender_type' => 'App\\Modules\\Core\\Models\\Contact',
            'sender_id' => $this->refs['contact_nina'],
            'related_contact_id' => $this->refs['contact_nina'],
            'client_key' => 'showcase-client',
            'channel' => 'sms',
            'provider' => 'telnyx',
            'provider_event_id' => 'showcase-inbound-event-001',
            'provider_message_id' => 'showcase-inbound-message-001',
            'provider_message_key' => hash('sha256', self::MARKER.'-inbound-message-key'),
            'provider_context_id' => 'showcase-telnyx-thread-001',
            'message_id' => '<showcase-inbound-message-001@example.test>',
            'from_type' => 'phone',
            'from_value' => '+13125550103',
            'reply_to_value' => '+13125550103',
            'to_type' => 'phone',
            'to_value' => '+13125550999',
            'subject' => null,
            'body' => 'CALL',
            'classification' => 'normal_reply',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'correlated_scheduled_message_id' => $this->refs['outbound_high_intent'] ?? null,
            'automated_response_scheduled_message_id' => $this->refs['outbound_acknowledgement'] ?? null,
            'reply_intent_key' => 'high_intent',
            'reply_correlation_method' => 'provider_context',
            'inbound_email_route_key' => null,
            'inbound_email_route_source' => null,
            'inbound_email_route_context' => null,
            'contact_extraction_status' => 'succeeded',
            'contact_extraction_definition_hash' => hash('sha256', self::MARKER.'-contact-extraction'),
            'contact_extraction_error' => null,
            'contact_extraction_attempted_at' => $this->now->subHours(2)->subMinutes(4),
            'received_at' => $this->now->subHours(2)->subMinutes(5),
            'processed_at' => $this->now->subHours(2),
            'inbox_status' => 'done',
            'reviewed_at' => $this->now->subHours(2),
            'completed_at' => $this->now->subHours(2)->addMinute(),
            'automated_handled_at' => $this->now->subHours(2)->addMinute(),
        ]);

        $this->row('inbound_messages', [
            'provider_event_key' => hash('sha256', self::MARKER.'-completed-help-event-key'),
        ], [
            'webhook_inbox_receipt_id' => $this->refs['webhook_receipt'],
            'sender_type' => 'App\\Modules\\Core\\Models\\Contact',
            'sender_id' => $this->refs['contact_marcus'],
            'related_contact_id' => $this->refs['contact_marcus'],
            'client_key' => 'showcase-client',
            'channel' => 'sms',
            'provider' => 'telnyx',
            'provider_event_id' => 'showcase-completed-help-event-001',
            'provider_message_id' => 'showcase-completed-help-message-001',
            'provider_message_key' => hash('sha256', self::MARKER.'-completed-help-message-key'),
            'provider_context_id' => 'showcase-completed-help-thread-001',
            'message_id' => '<showcase-completed-help-message-001@example.test>',
            'from_type' => 'phone',
            'from_value' => '+13125550102',
            'reply_to_value' => '+13125550102',
            'to_type' => 'phone',
            'to_value' => '+13125550999',
            'subject' => 'Help request',
            'body' => 'HELP',
            'classification' => 'help',
            'purpose' => 'transactional',
            'scope' => 'sms_help',
            'correlated_scheduled_message_id' => $this->refs['outbound_high_intent'] ?? null,
            'automated_response_scheduled_message_id' => $this->refs['outbound_future'] ?? null,
            'reply_intent_key' => 'help',
            'reply_correlation_method' => 'provider_context',
            'inbound_email_route_key' => 'showcase_realtor_replies',
            'inbound_email_route_source' => 'broadcasts',
            'inbound_email_route_context' => 'showcase_realtor_outreach',
            'contact_extraction_status' => 'succeeded',
            'contact_extraction_definition_hash' => hash('sha256', self::MARKER.'-completed-help-extraction'),
            'contact_extraction_error' => null,
            'contact_extraction_attempted_at' => $this->now->subDays(4),
            'received_at' => $this->now->subDays(4),
            'processed_at' => $this->now->subDays(4)->addMinute(),
            'inbox_status' => 'done',
            'reviewed_at' => $this->now->subDays(4)->addMinutes(2),
            'completed_at' => $this->now->subDays(4)->addMinutes(3),
            'automated_handled_at' => $this->now->subDays(4)->addSeconds(10),
        ]);

        $this->refs['unmatched_receipt'] = $this->row('webhook_inbox_receipts', [
            'receipt_key' => hash('sha256', self::MARKER.'-unmatched-receipt'),
        ], [
            'client_key' => 'showcase-client',
            'provider' => 'telnyx',
            'provider_event_id' => 'showcase-unmatched-event-001',
            'signature_fingerprint' => hash('sha256', self::MARKER.'-unmatched-signature'),
            'event_type' => 'message.received',
            'payload_fingerprint' => hash('sha256', self::MARKER.'-unmatched-payload'),
            'payload' => ['from' => '+13125550888', 'text' => 'Can somebody tell me what this was about?'],
            'status' => 'completed',
            'attempts' => 1,
            'claim_token' => '2754e9aa-d620-473f-903c-8dfcd6fcf45d',
            'claim_expires_at' => $this->now->subMinutes(40),
            'last_attempted_at' => $this->now->subMinutes(45),
            'completed_at' => $this->now->subMinutes(44),
            'failed_at' => null,
            'outcome' => ['contact_matched' => false],
            'last_error' => null,
        ]);

        $this->refs['inbound_unmatched'] = $this->row('inbound_messages', [
            'provider_event_key' => hash('sha256', self::MARKER.'-unmatched-event-key'),
        ], [
            'webhook_inbox_receipt_id' => $this->refs['unmatched_receipt'],
            'sender_type' => null,
            'sender_id' => null,
            'related_contact_id' => null,
            'client_key' => 'showcase-client',
            'channel' => 'sms',
            'provider' => 'telnyx',
            'provider_event_id' => 'showcase-unmatched-event-001',
            'provider_message_id' => 'showcase-unmatched-message-001',
            'provider_message_key' => hash('sha256', self::MARKER.'-unmatched-message-key'),
            'provider_context_id' => 'showcase-unmatched-thread-001',
            'message_id' => '<showcase-unmatched-message-001@example.test>',
            'from_type' => 'phone',
            'from_value' => '+13125550888',
            'reply_to_value' => '+13125550888',
            'to_type' => 'phone',
            'to_value' => '+13125550999',
            'subject' => 'Unknown sender reply',
            'body' => 'Can somebody tell me what this was about?',
            'classification' => 'normal_reply',
            'purpose' => 'marketing',
            'scope' => 'unknown',
            'correlated_scheduled_message_id' => null,
            'automated_response_scheduled_message_id' => null,
            'reply_intent_key' => null,
            'reply_correlation_method' => 'none',
            'inbound_email_route_key' => null,
            'inbound_email_route_source' => null,
            'inbound_email_route_context' => null,
            'contact_extraction_status' => 'failed',
            'contact_extraction_definition_hash' => hash('sha256', self::MARKER.'-unmatched-extraction'),
            'contact_extraction_error' => 'No contact matched the normalized sender phone.',
            'contact_extraction_attempted_at' => $this->now->subMinutes(45),
            'received_at' => $this->now->subMinutes(46),
            'processed_at' => $this->now->subMinutes(44),
            'inbox_status' => 'new',
            'reviewed_at' => null,
            'completed_at' => null,
            'automated_handled_at' => null,
        ]);

        if (isset($this->refs['task_high_intent'])) {
            $taskMeta = $this->decodedArray(
                DB::table('tasks')
                    ->where('id', $this->refs['task_high_intent'])
                    ->value('meta'),
            );
            data_set($taskMeta, 'automation.provenance.contact_id', $this->refs['contact_nina']);
            data_set(
                $taskMeta,
                'automation.provenance.subject_type',
                'App\\Modules\\InboundMessaging\\Models\\InboundMessage',
            );
            data_set($taskMeta, 'automation.provenance.subject_id', $this->refs['inbound_high_intent']);
            $this->update('tasks', $this->refs['task_high_intent'], ['meta' => $taskMeta]);

            $this->update('scheduled_messages', $this->refs['outbound_acknowledgement'] ?? null, [
                'context_type' => 'App\\Modules\\InboundMessaging\\Models\\InboundMessage',
                'context_id' => $this->refs['inbound_high_intent'],
                'behavior_owner_type' => isset($this->clientReplyFlow()->flow_route_point_id)
                    ? 'App\\Modules\\FlowRoutes\\Models\\FlowRoutePoint'
                    : null,
                'behavior_owner_id' => $this->clientReplyFlow()->flow_route_point_id ?? null,
            ]);

            DB::table('task_links')
                ->where('task_id', $this->refs['task_high_intent'])
                ->delete();

            $this->row('task_links', [
                'task_id' => $this->refs['task_high_intent'],
                'linkable_type' => 'App\\Modules\\InboundMessaging\\Models\\InboundMessage',
                'linkable_id' => $this->refs['inbound_high_intent'],
                'role' => 'subject',
            ]);
            $this->row('task_links', [
                'task_id' => $this->refs['task_high_intent'],
                'linkable_type' => 'App\\Modules\\Core\\Models\\Contact',
                'linkable_id' => $this->refs['contact_nina'],
                'role' => 'context',
            ]);

            $this->seedTaskTemplateDefaultLinks();
        }

        $this->seededModules[] = 'inbound_messaging';
    }

    private function seedInternalNotifications(): void
    {
        if (! $this->moduleAvailable('internal_notifications', 'team_members')) {
            return;
        }

        $this->refs['team_member'] = $this->row('team_members', [
            'email' => 'stacey.showcase@example.test',
        ], [
            'user_id' => $this->refs['owner'],
            'name' => 'Stacey Showcase',
            'phone' => '+13125550999',
            'role' => 'Branch Manager',
            'is_active' => true,
            'meta' => $this->meta(['timezone' => 'America/Chicago', 'escalation_order' => 1]),
        ]);

        foreach ([
            ['sms', 'transactional', 'inbound_replies'],
            ['email', 'transactional', 'task_assigned'],
            ['email', 'transactional', 'daily_digest'],
        ] as [$channel, $purpose, $scope]) {
            $this->row('team_member_notification_preferences', [
                'team_member_id' => $this->refs['team_member'],
                'channel' => $channel,
                'purpose' => $purpose,
                'scope' => $scope,
            ], [
                'is_enabled' => true,
                'meta' => $this->meta([
                    'quiet_hours' => ['start' => '21:00', 'end' => '07:00'],
                    'immediate_for' => ['high_intent_reply'],
                ]),
            ]);
        }

        $this->seededModules[] = 'internal_notifications';
    }

    private function seedCampaigns(): void
    {
        if (! $this->moduleAvailable('campaigns', 'campaigns')) {
            return;
        }

        $this->refs['campaign'] = $this->row('campaigns', [
            'key' => 'showcase_va_nurture',
        ], [
            'name' => 'Showcase · VA Homebuyer Nurture',
            'description' => 'Realistic automated follow-up with explicit eligibility, reentry, and message history.',
            'message_chain_id' => $this->refs['chain'] ?? null,
            'family_key' => 'va_homebuyer',
            'priority' => 80,
            'eligibility_filter' => [
                'operator' => 'and',
                'rules' => [
                    ['field' => 'status', 'operator' => 'in', 'value' => ['showcase_prospect', 'showcase_requires_action']],
                    ['field' => 'tag', 'operator' => 'contains', 'value' => 'Webinar Registrant'],
                ],
            ],
            'enrollment_mode' => 'automatic',
            'reentry_policy' => 'when_eligible_again',
            'ineligible_behavior' => 'pause',
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar_follow_up',
            'status' => 'active',
            'source_version' => 'showcase-1',
            'is_customized' => true,
            'customized_at' => $this->now->subDays(6),
            'meta' => $this->meta(['daily_cap' => 50, 'owner' => 'Stacey']),
        ]);

        $this->refs['campaign_step'] = $this->row('campaign_steps', [
            'campaign_id' => $this->refs['campaign'],
            'step_number' => 1,
        ], [
            'name' => 'Ask whether they want to begin preapproval',
            'dispatch_key' => 'showcase_va_ask_to_begin',
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar_follow_up',
            'variant_strategy' => 'first_available',
            'is_active' => true,
            'criteria' => [['field' => 'contact.phone', 'operator' => 'present']],
            'source_version' => 'showcase-1',
            'is_customized' => true,
            'customized_at' => $this->now->subDays(6),
            'meta' => $this->meta(['delay_minutes' => 0, 'reply_profile_key' => 'showcase_high_intent']),
        ]);

        $this->refs['campaign_variant'] = $this->row('campaign_step_variants', [
            'campaign_step_id' => $this->refs['campaign_step'],
            'key' => 'sms',
        ], [
            'name' => 'SMS with reply prompt',
            'sort_order' => 10,
            'dispatch_key' => 'showcase_va_ask_to_begin_sms',
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar_follow_up',
            'is_active' => true,
            'criteria' => [['field' => 'consent.sms.marketing', 'operator' => 'granted']],
            'dependency_rules' => ['requires' => ['messaging', 'inbound_messaging']],
            'source_config_path' => 'showcase.campaigns.va.steps.0.variants.sms',
            'source_version' => 'showcase-1',
            'is_customized' => true,
            'customized_at' => $this->now->subDays(6),
            'meta' => $this->meta(['template_version_id' => $this->refs['template_version'] ?? null]),
        ]);

        $this->refs['campaign_enrollment'] = $this->row('campaign_enrollments', [
            'dedupe_key' => 'showcase-va-nurture-nina-cycle-1',
        ], [
            'contact_id' => $this->refs['contact_nina'],
            'campaign_id' => $this->refs['campaign'],
            'message_chain_enrollment_id' => $this->refs['chain_enrollment'] ?? null,
            'source_type' => isset($this->refs['registration'])
                ? 'App\\Modules\\Webinars\\Models\\WebinarRegistration'
                : 'App\\Modules\\Core\\Models\\Contact',
            'source_id' => $this->refs['registration'] ?? $this->refs['contact_nina'],
            'campaign_key' => 'showcase_va_nurture',
            'start_context' => ['reason' => 'attended_webinar', 'webinar_title' => 'VA Homebuyer Game Plan'],
            'started_at' => $this->now->subDays(2),
            'meta' => $this->meta(['eligibility_cycle' => 1, 'auto_enrolled' => true]),
        ]);

        if ($this->hasTable('campaign_eligibility_states')) {
            $this->row('campaign_eligibility_states', [
                'campaign_id' => $this->refs['campaign'],
                'contact_id' => $this->refs['contact_nina'],
            ], [
                'is_eligible' => true,
                'eligibility_cycle' => 2,
                'became_eligible_at' => $this->now->subDays(2),
                'became_ineligible_at' => null,
                'last_evaluated_at' => $this->now->subMinutes(5),
            ]);
            $this->row('campaign_eligibility_states', [
                'campaign_id' => $this->refs['campaign'],
                'contact_id' => $this->refs['contact_marcus'],
            ], [
                'is_eligible' => false,
                'eligibility_cycle' => 1,
                'became_eligible_at' => $this->now->subMonths(2),
                'became_ineligible_at' => $this->now->subDays(2),
                'last_evaluated_at' => $this->now->subMinutes(5),
            ]);
        }

        if ($this->hasTable('campaign_touch_programs')) {
            $this->refs['touch_program'] = $this->row('campaign_touch_programs', [
                'key' => 'showcase_past_client_anniversary',
            ], [
                'campaign_id' => $this->refs['campaign'],
                'name' => 'Showcase · Past Client Anniversary',
                'audience_type' => 'filter',
                'audience_key' => 'showcase_past_client',
                'audience_filter' => ['status_keys' => ['showcase_past_client'], 'tags' => ['Past Client']],
                'recurrence' => 'annual',
                'repeat_years' => 10,
                'starts_on' => $this->now->subYear()->toDateString(),
                'is_active' => true,
                'meta' => $this->meta(['business_calendar_key' => 'showcase_business_calendar']),
            ]);

            $this->refs['touch_date'] = $this->row('campaign_touch_dates', [
                'campaign_touch_program_id' => $this->refs['touch_program'],
                'key' => 'closing_anniversary',
            ], [
                'name' => 'Closing anniversary',
                'source_type' => 'contact_field',
                'source_key' => 'mortgage.closed_on',
                'month' => 3,
                'day' => 15,
                'offset_days' => 0,
                'send_time' => '10:30:00',
                'sort_order' => 10,
                'is_active' => true,
                'meta' => $this->meta(['timezone' => 'contact']),
            ]);

            $this->refs['touch_variant'] = $this->row('campaign_touch_variants', [
                'campaign_touch_date_id' => $this->refs['touch_date'],
                'key' => 'email',
            ], [
                'name' => 'Anniversary email',
                'sort_order' => 10,
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'annual_touch',
                'message_template_preset_id' => $this->refs['preset'] ?? null,
                'is_active' => true,
                'meta' => $this->meta(['fallback_channel' => 'sms']),
            ]);

            $this->row('campaign_touch_dispatches', [
                'campaign_touch_variant_id' => $this->refs['touch_variant'],
                'contact_id' => $this->refs['contact_marcus'],
                'occurrence_year' => (int) $this->now->year,
            ], [
                'due_at' => $this->now->addMonth(),
                'scheduled_message_id' => $this->refs['outbound_future'] ?? null,
                'status' => 'scheduled',
                'reason' => 'Closing anniversary is due next month.',
                'meta' => $this->meta(['calculated_from' => 'mortgage.closed_on']),
            ]);
        }

        $this->seededModules[] = 'campaigns';
    }

    private function seedBroadcasts(): void
    {
        if (! $this->moduleAvailable('broadcasts', 'broadcasts')) {
            return;
        }

        $this->refs['broadcast'] = $this->row('broadcasts', [
            'name' => 'Showcase · Don’t Send Me a Buyer · Daily 25',
        ], [
            'user_id' => $this->refs['owner'],
            'message_template_id' => $this->refs['email_template'] ?? null,
            'message_template_version_id' => $this->refs['email_template_version'] ?? null,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'realtor_outreach',
            'dispatch_key' => 'broadcast_send',
            'message_type' => 'broadcast',
            'payload_class' => 'App\\Modules\\Messaging\\Payloads\\EmailPayload',
            'queue' => 'marketing',
            'status' => 'completed',
            'send_at' => $this->now->subDay()->setTime(9, 0),
            'recipient_filter' => [
                'operator' => 'and',
                'rules' => [
                    ['field' => 'tag', 'operator' => 'contains', 'value' => 'Realtor Outreach'],
                    ['field' => 'broadcast_history', 'operator' => 'not_sent', 'value' => 'showcase-dont-send-me-a-buyer'],
                ],
                'limit' => 25,
            ],
            'recipient_count' => 25,
            'scheduled_count' => 24,
            'cancelled_at' => null,
            'completed_at' => $this->now->subDay()->setTime(9, 12),
            'meta' => $this->meta([
                'series_key' => 'showcase-dont-send-me-a-buyer',
                'batch_number' => 13,
                'excluded' => ['suppressed' => 1, 'already_sent' => 300],
            ]),
        ]);

        $broadcastPayload = [
            'subject' => 'Don’t send me a buyer',
            'body' => '<p>Olivia, what if your next buyer arrived already prepared and easy to work with?</p>',
            'to' => 'olivia.reed+showcase@example.test',
        ];
        $this->refs['broadcast_message_sent'] = $this->row('scheduled_messages', [
            'dedupe_key' => 'showcase-broadcast-olivia-sent',
        ], [
            'recipient_type' => 'App\\Modules\\Core\\Models\\Contact',
            'recipient_id' => $this->refs['contact_olivia'],
            'context_type' => 'App\\Modules\\Broadcasts\\Models\\Broadcast',
            'context_id' => $this->refs['broadcast'],
            'behavior_owner_type' => null,
            'behavior_owner_id' => null,
            'message_template_version_id' => $this->refs['email_template_version'] ?? null,
            'message_chain_enrollment_id' => null,
            'message_chain_step_variant_id' => null,
            'channel' => 'email',
            'message_type' => 'broadcast',
            'reply_profile_key' => 'showcase_high_intent',
            'purpose' => 'marketing',
            'scope' => 'realtor_outreach',
            'payload_class' => 'App\\Modules\\Messaging\\Payloads\\EmailPayload',
            'queue' => 'marketing',
            'dispatch_keys' => ['broadcast_send'],
            'definition_config_path' => 'showcase.broadcasts.dont_send_me_a_buyer',
            'payload' => $broadcastPayload,
            'send_at' => $this->now->subDay()->setTime(9, 0),
            'status' => 'sent',
            'operational_state' => 'active',
            'manual_schedule_override_at' => null,
            'provider_idempotency_key' => 'showcase-broadcast-olivia-provider-key',
            'meta' => $this->meta(['broadcast_id' => $this->refs['broadcast']]),
        ]);
        $this->refs['broadcast_message_pending'] = $this->row('scheduled_messages', [
            'dedupe_key' => 'showcase-broadcast-ava-pending',
        ], [
            'recipient_type' => 'App\\Modules\\Core\\Models\\Contact',
            'recipient_id' => $this->refs['contact_ava'],
            'context_type' => 'App\\Modules\\Broadcasts\\Models\\Broadcast',
            'context_id' => $this->refs['broadcast'],
            'behavior_owner_type' => null,
            'behavior_owner_id' => null,
            'message_template_version_id' => $this->refs['email_template_version'] ?? null,
            'message_chain_enrollment_id' => null,
            'message_chain_step_variant_id' => null,
            'channel' => 'email',
            'message_type' => 'broadcast',
            'reply_profile_key' => 'showcase_high_intent',
            'purpose' => 'marketing',
            'scope' => 'realtor_outreach',
            'payload_class' => 'App\\Modules\\Messaging\\Payloads\\EmailPayload',
            'queue' => 'marketing',
            'dispatch_keys' => ['broadcast_send'],
            'definition_config_path' => 'showcase.broadcasts.dont_send_me_a_buyer',
            'payload' => array_replace($broadcastPayload, ['to' => 'ava.morgan+showcase@example.test']),
            'send_at' => $this->now->addDay()->setTime(9, 0),
            'status' => 'pending',
            'operational_state' => 'active',
            'manual_schedule_override_at' => null,
            'provider_idempotency_key' => 'showcase-broadcast-ava-provider-key',
            'meta' => $this->meta(['broadcast_id' => $this->refs['broadcast']]),
        ]);

        $this->row('broadcasts', [
            'name' => 'Showcase · Cancelled Draft',
        ], [
            'user_id' => $this->refs['owner'],
            'message_template_id' => null,
            'message_template_version_id' => null,
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'realtor_outreach',
            'dispatch_key' => 'broadcast_send',
            'message_type' => 'broadcast',
            'payload_class' => 'App\\Modules\\Messaging\\Payloads\\SmsPayload',
            'queue' => 'marketing',
            'status' => 'cancelled',
            'send_at' => $this->now->subDays(3),
            'recipient_filter' => ['type' => 'tag', 'tag' => 'Realtor Outreach'],
            'recipient_count' => 25,
            'scheduled_count' => 0,
            'cancelled_at' => $this->now->subDays(3)->subHour(),
            'completed_at' => null,
            'meta' => $this->meta(['cancel_reason' => 'Audience required review']),
        ]);

        foreach ([
            ['olivia', 'sent', $this->refs['broadcast_message_sent'], $this->now->subDay(), null],
            ['ava', 'scheduled', $this->refs['broadcast_message_pending'], null, null],
            ['marcus', 'skipped', null, null, 'email_suppressed'],
        ] as [$contactKey, $status, $scheduledMessageId, $sentAt, $terminalReason]) {
            $this->row('broadcast_recipients', [
                'broadcast_id' => $this->refs['broadcast'],
                'contact_id' => $this->refs['contact_'.$contactKey],
            ], [
                'status' => $status,
                'scheduled_message_id' => $scheduledMessageId,
                'sent_at' => $sentAt,
                'terminal_reason' => $terminalReason ?? 'Completed without a terminal error.',
                'meta' => $this->meta(['sequence' => $contactKey, 'audience_snapshot' => ['tag' => 'Realtor Outreach']]),
            ]);
        }

        $this->seededModules[] = 'broadcasts';
    }

    private function seedWebinars(): void
    {
        if (! $this->moduleAvailable('webinars', 'webinars')) {
            return;
        }

        $this->refs['webinar_profile'] = $this->row('webinar_schedule_profiles', [
            'key' => 'showcase_va_schedule',
        ], [
            'name' => 'Showcase · VA Webinar Schedule',
            'description' => 'Complete reminder and follow-up schedule for surface inspection.',
            'message_template_set_key' => 'showcase_va_templates',
            'status' => 'active',
            'is_default' => false,
            'is_active' => true,
            'is_customized' => true,
            'customized_at' => $this->now->subDays(8),
            'source' => 'manual',
            'source_config_path' => 'showcase.webinars.schedule',
            'source_version' => 1,
            'last_synced_at' => $this->now->subDays(8),
            'meta' => $this->meta(['timezone_label' => 'Central', 'owner' => 'Stacey']),
        ]);

        $this->refs['webinar_profile_item'] = $this->row('webinar_schedule_profile_items', [
            'webinar_schedule_profile_id' => $this->refs['webinar_profile'],
            'key' => 'one_day_reminder',
        ], [
            'label' => 'One-day reminder',
            'context_key' => 'webinar_registration',
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar_reminder',
            'surface' => 'webinar_registration',
            'message_type' => 'webinar_reminder',
            'dispatch_key' => 'webinar_one_day_reminder',
            'message_template_key' => 'showcase_va_high_intent_follow_up',
            'source_config_path' => 'showcase.webinars.schedule.items.one_day',
            'is_enabled' => true,
            'is_active' => true,
            'is_customized' => true,
            'customized_at' => $this->now->subDays(8),
            'sort_order' => 20,
            'timing' => 'scheduled',
            'schedule' => ['type' => 'anchored', 'minutes' => -1440],
            'conditions' => [['field' => 'registration.status', 'operator' => 'in', 'value' => ['registered', 'confirmed']]],
            'meta' => $this->meta(['editable_on_resync' => true]),
        ]);

        $this->refs['webinar_series'] = $this->row('webinar_series', [
            'slug' => 'showcase-va-homebuyer-game-plan',
        ], [
            'title' => 'Showcase · VA Homebuyer Game Plan',
            'status' => 'active',
            'platform' => 'zoom',
            'provider_event_type' => 'meeting',
            'webinar_schedule_profile_id' => $this->refs['webinar_profile'],
            'meta' => $this->meta([
                'display_timezone' => 'America/Chicago',
                'cta' => ['label' => 'Book a call', 'url' => 'https://example.test/book'],
                'host_name' => 'Stacey Showcase',
            ]),
        ]);

        $this->refs['webinar_past'] = $this->row('webinars', [
            'external_id' => 'showcase-zoom-meeting-001',
            'provider_event_type' => 'meeting',
        ], [
            'webinar_series_id' => $this->refs['webinar_series'],
            'replacement_of_webinar_id' => null,
            'webinar_schedule_profile_id' => $this->refs['webinar_profile'],
            'title' => 'VA Homebuyer Game Plan · September',
            'slug' => 'showcase-va-homebuyer-september',
            'platform' => 'zoom',
            'host_account_key' => 'stacey-showcase',
            'provider_lifecycle_status' => 'archived',
            'provider_missing_at' => $this->now->subDays(2),
            'provider_archived_at' => $this->now->subDay(),
            'hidden_at' => $this->now->subHours(12),
            'hidden_reason' => 'operator_removed',
            'join_url' => 'https://zoom.example.test/j/showcase-001',
            'registration_url' => 'https://example.test/webinars/showcase-va-homebuyer-september/register',
            'playback_token' => 'showcase-playback-token-001',
            'playback_url' => 'https://video.example.test/showcase-va-replay',
            'playback_passcode' => 'VA2026',
            'starts_at' => $this->now->subDays(2)->setTime(19, 0),
            'ends_at' => $this->now->subDays(2)->setTime(20, 5),
            'timezone' => 'America/Chicago',
            'description' => 'Live VA homebuyer class covering eligibility, financing, and next steps.',
            'meta' => $this->meta(['attendance_synced_at' => $this->now->subDay()->toIso8601String()]),
            'provider_settings' => ['approval_type' => 'automatic', 'recording' => 'cloud', 'waiting_room' => true],
        ]);

        $this->refs['webinar_upcoming'] = $this->row('webinars', [
            'external_id' => 'showcase-zoom-meeting-002',
            'provider_event_type' => 'meeting',
        ], [
            'webinar_series_id' => $this->refs['webinar_series'],
            'replacement_of_webinar_id' => $this->refs['webinar_past'],
            'webinar_schedule_profile_id' => $this->refs['webinar_profile'],
            'title' => 'VA Homebuyer Game Plan · October',
            'slug' => 'showcase-va-homebuyer-october',
            'platform' => 'zoom',
            'host_account_key' => 'stacey-showcase',
            'provider_lifecycle_status' => 'active',
            'provider_missing_at' => null,
            'provider_archived_at' => null,
            'hidden_at' => null,
            'hidden_reason' => null,
            'join_url' => 'https://zoom.example.test/j/showcase-002',
            'registration_url' => 'https://example.test/webinars/showcase-va-homebuyer-october/register',
            'playback_token' => 'showcase-playback-token-002',
            'playback_url' => 'https://video.example.test/showcase-va-upcoming-placeholder',
            'playback_passcode' => 'OCT2026',
            'starts_at' => $this->now->addDays(10)->setTime(19, 0),
            'ends_at' => $this->now->addDays(10)->setTime(20, 0),
            'timezone' => 'America/Chicago',
            'description' => 'Upcoming occurrence with a fully populated message schedule.',
            'meta' => $this->meta(['ad_tracking_key' => 'showcase-meta-va-october']),
            'provider_settings' => ['approval_type' => 'automatic', 'recording' => 'cloud', 'waiting_room' => true],
        ]);

        $this->refs['registration'] = $this->row('webinar_registrations', [
            'join_token' => 'showcase-nina-registration-token',
        ], [
            'contact_id' => $this->refs['contact_nina'],
            'webinar_id' => $this->refs['webinar_past'],
            'replacement_of_registration_id' => null,
            'webinar_slug' => 'showcase-va-homebuyer-september',
            'status' => 'attended',
            'source' => 'facebook_ad',
            'meta' => $this->meta([
                'attendance_minutes' => 54,
                'join_clicked_at' => $this->now->subDays(2)->setTime(18, 57)->toIso8601String(),
                'utm_source' => 'facebook',
                'utm_campaign' => 'showcase_va_september',
            ]),
            'registered_at' => $this->now->subDays(12),
            'attended_at' => $this->now->subDays(2)->setTime(19, 2),
            'cancelled_at' => null,
        ]);

        $this->row('webinar_registrations', [
            'join_token' => 'showcase-olivia-cancelled-token',
        ], [
            'contact_id' => $this->refs['contact_olivia'],
            'webinar_id' => $this->refs['webinar_past'],
            'replacement_of_registration_id' => null,
            'webinar_slug' => 'showcase-va-homebuyer-september',
            'status' => 'cancelled',
            'source' => 'realtor_outreach',
            'meta' => $this->meta(['cancel_reason' => 'Schedule conflict']),
            'registered_at' => $this->now->subDays(14),
            'attended_at' => null,
            'cancelled_at' => $this->now->subDays(3),
        ]);

        $this->refs['replacement_registration'] = $this->row('webinar_registrations', [
            'join_token' => 'showcase-nina-replacement-token',
        ], [
            'contact_id' => $this->refs['contact_nina'],
            'webinar_id' => $this->refs['webinar_upcoming'],
            'replacement_of_registration_id' => $this->refs['registration'],
            'webinar_slug' => 'showcase-va-homebuyer-october',
            'status' => 'registered',
            'source' => 'operator_reschedule',
            'meta' => $this->meta(['reschedule_reason' => 'Requested another session']),
            'registered_at' => $this->now->subDay(),
            'attended_at' => null,
            'cancelled_at' => null,
        ]);

        $this->row('webinar_registration_responses', [
            'webinar_registration_id' => $this->refs['registration'],
            'question_key' => 'homebuying_timeline',
        ], [
            'question_label' => 'When are you hoping to buy?',
            'question_type' => 'single_select',
            'answer_key' => 'within_90_days',
            'answer_label' => 'Within 90 days',
            'answer_text' => 'Before my lease ends in November.',
            'definition_version' => '2026-09',
            'sort_order' => 10,
        ]);

        $this->row('webinar_waitlist_signups', [
            'contact_id' => $this->refs['contact_ava'],
            'webinar_series_id' => $this->refs['webinar_series'],
        ], [
            'notified_at' => $this->now->subDays(3),
            'notification_mode' => 'recurring',
            'expires_at' => $this->now->addMonths(3),
            'ended_at' => $this->now->addMonths(4),
            'source_page' => '/webinars/showcase-va/waitlist',
            'meta' => $this->meta(['preferred_channel' => 'sms', 'missed_occurrence_id' => $this->refs['webinar_past']]),
        ]);

        if ($this->hasTable('webinar_schedule_profile_chain_bindings') && isset($this->refs['chain'])) {
            $this->row('webinar_schedule_profile_chain_bindings', [
                'webinar_schedule_profile_id' => $this->refs['webinar_profile'],
                'key' => 'showcase_registration_messages',
            ], [
                'message_area_key' => 'registration',
                'message_chain_id' => $this->refs['chain'],
                'dispatch_key' => 'webinar_registration',
                'surface' => 'webinar_registration',
                'is_active' => true,
            ]);
        }

        if ($this->hasTable('webinar_series_message_chain_bindings') && isset($this->refs['chain'])) {
            $this->row('webinar_series_message_chain_bindings', [
                'webinar_series_id' => $this->refs['webinar_series'],
                'key' => 'showcase_follow_up_messages',
            ], [
                'message_area_key' => 'follow_up',
                'message_chain_id' => $this->refs['chain'],
                'dispatch_key' => 'webinar_ended',
                'surface' => 'webinar_follow_up',
                'is_active' => true,
            ]);
        }

        $this->row('webinar_occurrence_suppressions', [
            'platform' => 'zoom',
            'provider_event_type' => 'meeting',
            'external_id' => 'showcase-off-grid-duplicate-001',
        ], [
            'webinar_series_id' => $this->refs['webinar_series'],
            'external_uuid' => 'showcase-off-grid-uuid-001',
            'reason' => 'operator_removed',
            'suppressed_at' => $this->now->subDays(4),
            'meta' => $this->meta(['title' => 'Off-grid duplicate', 'operator' => 'Stacey Showcase']),
        ]);

        $this->row('webinar_schedule_changes', [
            'webinar_id' => $this->refs['webinar_upcoming'],
            'notification_mode' => 'automatic',
        ], [
            'previous_starts_at' => $this->now->addDays(10)->setTime(18, 0),
            'current_starts_at' => $this->now->addDays(10)->setTime(19, 0),
            'previous_timezone' => 'America/New_York',
            'current_timezone' => 'America/Chicago',
            'status' => 'completed',
            'channels' => ['email', 'sms'],
            'last_registration_id' => $this->refs['replacement_registration'],
            'messages_queued' => 24,
            'messages_cancelled' => 24,
            'queued_at' => $this->now->subHours(5),
            'completed_at' => $this->now->subHours(4),
            'template_version_ids' => [
                'email' => $this->refs['email_template_version'] ?? null,
                'sms' => $this->refs['template_version'] ?? null,
            ],
        ]);

        $this->seededModules[] = 'webinars';
    }

    private function seedScheduling(): void
    {
        if (! $this->moduleAvailable('scheduling', 'appointments')) {
            return;
        }

        $this->refs['scheduling_host'] = $this->row('scheduling_hosts', [
            'key' => 'showcase_stacey',
        ], [
            'name' => 'Stacey Showcase',
            'status' => 'active',
            'hostable_type' => 'App\\Models\\User',
            'hostable_id' => $this->refs['owner'],
            'timezone' => 'America/Chicago',
            'capacity' => 1,
            'email' => 'stacey.showcase@example.test',
            'phone' => '+13125550999',
            'sort_order' => 10,
            'source' => 'manual',
            'meta' => $this->meta(['calendar_provider' => 'zoom', 'display_color' => '#ec4899']),
        ]);

        $this->refs['service'] = $this->row('bookable_services', [
            'key' => 'showcase_mortgage_strategy_call',
        ], [
            'name' => 'Showcase · Mortgage Strategy Call',
            'description' => 'A complete appointment type with booking, reminder, and after-booking settings.',
            'status' => 'active',
            'duration_mode' => 'range',
            'duration_minutes' => 30,
            'minimum_duration_minutes' => 20,
            'maximum_duration_minutes' => 60,
            'slot_interval_minutes' => 15,
            'buffer_before_minutes' => 10,
            'buffer_after_minutes' => 15,
            'minimum_notice_minutes' => 1440,
            'booking_horizon_days' => 60,
            'cancellation_notice_minutes' => 720,
            'reschedule_notice_minutes' => 720,
            'timezone' => 'America/Chicago',
            'appointment_format' => 'remote',
            'in_person_arrangement' => 'business_location',
            'remote_method' => 'virtual_meeting',
            'location_type' => 'virtual',
            'location_details' => ['provider' => 'zoom', 'instructions' => 'The meeting link appears after confirmation.'],
            'capacity' => 1,
            'requires_confirmation' => true,
            'is_public' => true,
            'sort_order' => 10,
            'source' => 'manual',
            'provider' => 'zoom',
            'external_id' => 'showcase-appointment-type-001',
            'external_url' => 'https://example.test/book/showcase-strategy-call',
            'meta' => $this->meta([
                'booking_form' => ['phone' => 'required', 'loan_goal' => 'required'],
                'reminders' => ['confirmation', '3_days', '24_hours', '1_hour'],
                'after_booking' => ['tag' => 'Strategy Call Booked', 'status' => 'showcase_requires_action'],
            ]),
        ]);

        $this->row('bookable_service_hosts', [
            'bookable_service_id' => $this->refs['service'],
            'scheduling_host_id' => $this->refs['scheduling_host'],
        ], [
            'is_active' => true,
            'capacity_override' => 1,
            'sort_order' => 10,
            'meta' => $this->meta(['routing_weight' => 100]),
        ]);

        $this->refs['availability_window'] = $this->row('scheduling_availability_windows', [
            'bookable_service_id' => $this->refs['service'],
            'scheduling_host_id' => $this->refs['scheduling_host'],
            'weekday' => 2,
            'start_time' => '09:00:00',
        ], [
            'window_type' => 'weekly',
            'timezone' => 'America/Chicago',
            'end_time' => '16:30:00',
            'starts_at' => $this->now->startOfWeek()->addDay()->setTime(9, 0),
            'ends_at' => $this->now->startOfWeek()->addDay()->setTime(16, 30),
            'capacity' => 1,
            'is_available' => true,
            'source' => 'manual',
            'meta' => $this->meta(['label' => 'Tuesday office hours', 'recurrence' => 'weekly']),
        ]);

        if ($this->hasTable('scheduling_resources')) {
            $this->refs['resource'] = $this->row('scheduling_resources', [
                'key' => 'showcase_zoom_room',
            ], [
                'name' => 'Showcase Zoom Room',
                'status' => 'active',
                'source' => 'provider',
                'sort_order' => 10,
                'meta' => $this->meta(['provider_account' => 'stacey-showcase', 'meeting_capacity' => 100]),
            ]);

            $this->row('scheduling_host_resources', [
                'scheduling_host_id' => $this->refs['scheduling_host'],
                'scheduling_resource_id' => $this->refs['resource'],
            ], [
                'capacity' => 1,
                'is_active' => true,
                'source' => 'provider',
                'sort_order' => 10,
            ]);

            $this->row('bookable_service_resource_requirements', [
                'bookable_service_id' => $this->refs['service'],
                'scheduling_resource_id' => $this->refs['resource'],
            ], [
                'quantity' => 1,
                'is_active' => true,
                'source' => 'manual',
                'sort_order' => 10,
            ]);
        }

        $eventId = null;
        if ($this->hasTable('events')) {
            $eventId = $this->row('events', [
                'title' => 'Showcase · Nina Patel Mortgage Strategy Call',
            ], [
                'type_key' => 'appointment',
                'description' => 'Calendar representation of the booked strategy call.',
                'status' => 'published',
                'attendance_mode' => 'virtual',
                'starts_at' => $this->now->addDays(2)->setTime(10, 0),
                'ends_at' => $this->now->addDays(2)->setTime(10, 30),
                'timezone' => 'America/Chicago',
                'announcement_at' => $this->now->subDay(),
                'primary_external_reference_id' => null,
                'venue_name' => 'Zoom',
                'address_line_1' => '1840 Example Avenue',
                'address_line_2' => 'Suite 200',
                'city' => 'Naperville',
                'region' => 'IL',
                'postal_code' => '60540',
                'country' => 'US',
            ]);
            if ($this->hasTable('event_external_references')) {
                $externalReferenceId = $this->row('event_external_references', [
                    'provider_key' => 'zoom',
                    'reference_type' => 'meeting',
                    'external_id' => 'showcase-scheduling-meeting-001',
                ], [
                    'event_id' => $eventId,
                    'url' => 'https://zoom.example.test/j/showcase-scheduling-001',
                    'label' => 'Join Zoom meeting',
                ]);
                $this->update('events', $eventId, ['primary_external_reference_id' => $externalReferenceId]);
            }
        }

        $this->refs['appointment_original'] = $this->row('appointments', [
            'idempotency_key' => 'showcase-appointment-original-001',
        ], [
            'bookable_service_id' => $this->refs['service'],
            'scheduling_host_id' => $this->refs['scheduling_host'],
            'contact_id' => $this->refs['contact_nina'],
            'location_reference_type' => 'App\\Modules\\Scheduling\\Models\\SchedulingResource',
            'location_reference_id' => $this->refs['resource'] ?? $this->refs['scheduling_host'],
            'primary_attendee_type' => 'App\\Modules\\Core\\Models\\Contact',
            'primary_attendee_id' => $this->refs['contact_nina'],
            'source_context_type' => isset($this->refs['registration'])
                ? 'App\\Modules\\Webinars\\Models\\WebinarRegistration'
                : 'App\\Modules\\Core\\Models\\Contact',
            'source_context_id' => $this->refs['registration'] ?? $this->refs['contact_nina'],
            'rescheduled_from_id' => null,
            'status' => 'canceled',
            'title' => 'Mortgage strategy call with Nina Patel',
            'description' => 'Discuss VA eligibility, target payment, and preapproval documents.',
            'location_type' => 'virtual',
            'location_details' => ['provider' => 'zoom', 'join_url' => 'https://zoom.example.test/j/showcase-scheduling-001'],
            'timezone' => 'America/Chicago',
            'starts_at' => $this->now->addDay()->setTime(10, 0),
            'ends_at' => $this->now->addDay()->setTime(10, 30),
            'confirmed_at' => $this->now->subDay(),
            'completed_at' => null,
            'no_show_at' => null,
            'canceled_at' => $this->now->subHours(6),
            'cancellation_reason' => 'Rescheduled at the borrower’s request.',
            'source' => 'public_booking',
            'created_by_type' => 'App\\Modules\\Core\\Models\\Contact',
            'created_by_id' => $this->refs['contact_nina'],
            'meta' => $this->meta(['loan_goal' => 'Purchase before lease ends', 'event_id' => $eventId]),
        ]);

        $this->refs['appointment_completed'] = $this->row('appointments', [
            'idempotency_key' => 'showcase-appointment-completed-001',
        ], [
            'bookable_service_id' => $this->refs['service'],
            'scheduling_host_id' => $this->refs['scheduling_host'],
            'contact_id' => $this->refs['contact_marcus'],
            'location_reference_type' => 'App\\Modules\\Scheduling\\Models\\SchedulingResource',
            'location_reference_id' => $this->refs['resource'] ?? $this->refs['scheduling_host'],
            'primary_attendee_type' => 'App\\Modules\\Core\\Models\\Contact',
            'primary_attendee_id' => $this->refs['contact_marcus'],
            'source_context_type' => 'App\\Modules\\Core\\Models\\Contact',
            'source_context_id' => $this->refs['contact_marcus'],
            'rescheduled_from_id' => null,
            'status' => 'completed',
            'title' => 'Annual mortgage review with Marcus Chen',
            'description' => 'Completed appointment history example.',
            'location_type' => 'phone',
            'location_details' => ['phone' => '+13125550102'],
            'timezone' => 'America/Chicago',
            'starts_at' => $this->now->subMonth()->setTime(11, 0),
            'ends_at' => $this->now->subMonth()->setTime(11, 30),
            'confirmed_at' => $this->now->subMonth()->subDay(),
            'completed_at' => $this->now->subMonth()->setTime(11, 30),
            'no_show_at' => null,
            'canceled_at' => null,
            'cancellation_reason' => null,
            'source' => 'crm',
            'created_by_type' => 'App\\Models\\User',
            'created_by_id' => $this->refs['owner'],
            'meta' => $this->meta(['outcome' => 'No changes planned']),
        ]);

        $this->row('appointments', [
            'idempotency_key' => 'showcase-appointment-no-show-001',
        ], [
            'bookable_service_id' => $this->refs['service'],
            'scheduling_host_id' => $this->refs['scheduling_host'],
            'contact_id' => $this->refs['contact_daniel'],
            'location_reference_type' => 'App\\Modules\\Scheduling\\Models\\SchedulingResource',
            'location_reference_id' => $this->refs['resource'] ?? $this->refs['scheduling_host'],
            'primary_attendee_type' => 'App\\Modules\\Core\\Models\\Contact',
            'primary_attendee_id' => $this->refs['contact_daniel'],
            'source_context_type' => 'App\\Modules\\Core\\Models\\Contact',
            'source_context_id' => $this->refs['contact_daniel'],
            'rescheduled_from_id' => null,
            'status' => 'no_show',
            'title' => 'Mortgage strategy call with Daniel Brooks',
            'description' => 'No-show state for follow-up UX.',
            'location_type' => 'virtual',
            'location_details' => ['provider' => 'zoom'],
            'timezone' => 'America/Chicago',
            'starts_at' => $this->now->subMonths(2)->setTime(14, 0),
            'ends_at' => $this->now->subMonths(2)->setTime(14, 30),
            'confirmed_at' => $this->now->subMonths(2)->subDay(),
            'completed_at' => null,
            'no_show_at' => $this->now->subMonths(2)->setTime(14, 15),
            'canceled_at' => null,
            'cancellation_reason' => null,
            'source' => 'public_booking',
            'created_by_type' => 'App\\Modules\\Core\\Models\\Contact',
            'created_by_id' => $this->refs['contact_daniel'],
            'meta' => $this->meta(['follow_up_required' => true]),
        ]);

        $this->refs['appointment'] = $this->row('appointments', [
            'idempotency_key' => 'showcase-appointment-rescheduled-001',
        ], [
            'bookable_service_id' => $this->refs['service'],
            'scheduling_host_id' => $this->refs['scheduling_host'],
            'contact_id' => $this->refs['contact_nina'],
            'location_reference_type' => 'App\\Modules\\Scheduling\\Models\\SchedulingResource',
            'location_reference_id' => $this->refs['resource'] ?? $this->refs['scheduling_host'],
            'primary_attendee_type' => 'App\\Modules\\Core\\Models\\Contact',
            'primary_attendee_id' => $this->refs['contact_nina'],
            'source_context_type' => isset($this->refs['registration'])
                ? 'App\\Modules\\Webinars\\Models\\WebinarRegistration'
                : 'App\\Modules\\Core\\Models\\Contact',
            'source_context_id' => $this->refs['registration'] ?? $this->refs['contact_nina'],
            'rescheduled_from_id' => $this->refs['appointment_original'],
            'status' => 'confirmed',
            'title' => 'Mortgage strategy call with Nina Patel',
            'description' => 'Discuss VA eligibility, target payment, and preapproval documents.',
            'location_type' => 'virtual',
            'location_details' => ['provider' => 'zoom', 'join_url' => 'https://zoom.example.test/j/showcase-scheduling-001'],
            'timezone' => 'America/Chicago',
            'starts_at' => $this->now->addDays(2)->setTime(10, 0),
            'ends_at' => $this->now->addDays(2)->setTime(10, 30),
            'confirmed_at' => $this->now->subHours(5),
            'completed_at' => null,
            'no_show_at' => null,
            'canceled_at' => null,
            'cancellation_reason' => null,
            'source' => 'reschedule',
            'created_by_type' => 'App\\Models\\User',
            'created_by_id' => $this->refs['owner'],
            'meta' => $this->meta(['loan_goal' => 'Purchase before lease ends', 'event_id' => $eventId]),
        ]);

        $this->row('appointment_attendees', [
            'appointment_id' => $this->refs['appointment'],
            'attendee_type' => 'App\\Modules\\Core\\Models\\Contact',
            'attendee_id' => $this->refs['contact_nina'],
        ], [
            'contact_id' => $this->refs['contact_nina'],
            'name' => 'Nina Patel',
            'email' => 'nina.patel+showcase@example.test',
            'phone' => '+13125550103',
            'role' => 'primary',
            'status' => 'accepted',
            'responded_at' => $this->now->subHours(5),
            'joined_at' => null,
            'canceled_at' => null,
            'meta' => $this->meta(['verification_channel' => 'sms']),
        ]);

        $this->row('appointment_attendees', [
            'appointment_id' => $this->refs['appointment_completed'],
            'attendee_type' => 'App\\Modules\\Core\\Models\\Contact',
            'attendee_id' => $this->refs['contact_marcus'],
        ], [
            'contact_id' => $this->refs['contact_marcus'],
            'name' => 'Marcus Chen',
            'email' => 'marcus.chen+showcase@example.test',
            'phone' => '+13125550102',
            'role' => 'primary',
            'status' => 'attended',
            'responded_at' => $this->now->subMonth()->subDay(),
            'joined_at' => $this->now->subMonth()->setTime(10, 58),
            'canceled_at' => null,
            'meta' => $this->meta(['attendance_minutes' => 31]),
        ]);

        $this->row('appointment_attendees', [
            'appointment_id' => $this->refs['appointment_original'],
            'attendee_type' => 'App\\Modules\\Core\\Models\\Contact',
            'attendee_id' => $this->refs['contact_nina'],
        ], [
            'contact_id' => $this->refs['contact_nina'],
            'name' => 'Nina Patel',
            'email' => 'nina.patel+showcase@example.test',
            'phone' => '+13125550103',
            'role' => 'primary',
            'status' => 'canceled',
            'responded_at' => $this->now->subDay(),
            'joined_at' => null,
            'canceled_at' => $this->now->subHours(6),
            'meta' => $this->meta(['rescheduled_to_id' => $this->refs['appointment']]),
        ]);

        $this->row('appointment_lifecycle_events', [
            'event_id' => '0cff2f4c-7a6e-4c91-b73d-91a95118b92d',
        ], [
            'appointment_id' => $this->refs['appointment'],
            'event_key' => 'rescheduled',
            'from_status' => 'scheduled',
            'to_status' => 'confirmed',
            'actor_type' => 'App\\Models\\User',
            'actor_id' => $this->refs['owner'],
            'source' => 'crm',
            'reason' => 'Borrower requested a later appointment.',
            'context' => ['previous_appointment_id' => $this->refs['appointment_original'], 'channel' => 'phone'],
            'occurred_at' => $this->now->subHours(6),
        ]);

        $this->refs['slot_offer'] = $this->row('bookable_slot_offers', [
            'offer_id' => 'showcase-slot-offer-001',
        ], [
            'bookable_service_id' => $this->refs['service'],
            'scheduling_host_id' => $this->refs['scheduling_host'],
            'reschedule_appointment_id' => $this->refs['appointment_original'],
            'starts_at' => $this->now->addDays(2)->setTime(10, 0),
            'ends_at' => $this->now->addDays(2)->setTime(10, 30),
            'display_timezone' => 'America/Chicago',
            'capacity' => 1,
            'remaining_capacity' => 0,
            'location_type' => 'virtual',
            'location_details' => ['provider' => 'zoom'],
            'source_scopes' => ['weekly_availability', 'resource_availability'],
            'source_window_ids' => [$this->refs['availability_window']],
            'issued_at' => $this->now->subHours(8),
            'expires_at' => $this->now->subHours(7),
            'consumed_at' => $this->now->subHours(7)->addMinutes(5),
            'meta' => $this->meta(['public_label' => '10:00 AM']),
        ]);

        $this->refs['booking_hold'] = $this->row('booking_holds', [
            'hold_id' => 'showcase-booking-hold-001',
        ], [
            'bookable_slot_offer_id' => $this->refs['slot_offer'],
            'bookable_service_id' => $this->refs['service'],
            'scheduling_host_id' => $this->refs['scheduling_host'],
            'appointment_id' => $this->refs['appointment'],
            'idempotency_key' => 'showcase-booking-hold-idempotency-001',
            'status' => 'converted',
            'starts_at' => $this->now->addDays(2)->setTime(10, 0),
            'ends_at' => $this->now->addDays(2)->setTime(10, 30),
            'occupancy_starts_at' => $this->now->addDays(2)->setTime(9, 50),
            'occupancy_ends_at' => $this->now->addDays(2)->setTime(10, 45),
            'capacity' => 1,
            'location_type' => 'virtual',
            'location_details' => ['provider' => 'zoom'],
            'held_at' => $this->now->subHours(8),
            'expires_at' => $this->now->subHours(7),
            'released_at' => $this->now->subHours(7)->addMinutes(5),
            'converted_at' => $this->now->subHours(7)->addMinutes(4),
            'meta' => $this->meta(['verification' => 'sms_code']),
        ]);

        if (isset($this->refs['resource'])) {
            $this->row('scheduling_resource_occupancies', [
                'scheduling_resource_id' => $this->refs['resource'],
                'appointment_id' => $this->refs['appointment'],
            ], [
                'scheduling_host_id' => $this->refs['scheduling_host'],
                'booking_hold_id' => $this->refs['booking_hold'],
                'quantity' => 1,
                'occupancy_starts_at' => $this->now->addDays(2)->setTime(9, 50),
                'occupancy_ends_at' => $this->now->addDays(2)->setTime(10, 45),
            ]);
        }

        if ($this->hasTable('scheduling_booking_offers')) {
            $this->refs['booking_offer'] = $this->row('scheduling_booking_offers', [
                'bookable_service_id' => $this->refs['service'],
                'code' => 'SHOWCASE50',
            ], [
                'name' => 'Showcase · First 50 Webinar Attendees',
                'status' => 'active',
                'starts_at' => $this->now->subMonth(),
                'ends_at' => $this->now->addMonth(),
                'claim_limit' => 50,
                'ineligible_message' => 'This booking offer is reserved for webinar attendees.',
                'exhausted_message' => 'All 50 priority strategy calls have been claimed.',
                'meta' => $this->meta(['public_badge' => 'Priority booking']),
            ]);

            $this->row('scheduling_booking_offer_conditions', [
                'scheduling_booking_offer_id' => $this->refs['booking_offer'],
                'sort_order' => 10,
            ], [
                'provider' => 'webinar_registration',
                'criteria' => ['status' => 'attended', 'series_slug' => 'showcase-va-homebuyer-game-plan'],
            ]);

            $this->refs['booking_reward'] = $this->row('scheduling_booking_offer_rewards', [
                'scheduling_booking_offer_id' => $this->refs['booking_offer'],
                'max_claim_number' => 50,
            ], [
                'name' => 'Priority strategy call',
                'sort_order' => 10,
            ]);

            $this->row('scheduling_booking_offer_reward_actions', [
                'scheduling_booking_offer_reward_id' => $this->refs['booking_reward'],
                'sort_order' => 10,
            ], [
                'provider' => 'contact_tag',
                'payload' => ['tag' => 'Priority Strategy Call'],
            ]);

            $this->row('scheduling_booking_offer_claims', [
                'appointment_id' => $this->refs['appointment'],
            ], [
                'scheduling_booking_offer_id' => $this->refs['booking_offer'],
                'contact_id' => $this->refs['contact_nina'],
                'qualification_scope_key' => 'showcase-va-webinar',
                'claim_number' => 17,
                'qualification_meta' => ['registration_id' => $this->refs['registration'] ?? null, 'status' => 'attended'],
                'claimed_at' => $this->now->subHours(7),
            ]);
        }

        $this->seededModules[] = 'scheduling';
    }

    private function seedFlowRoutes(): void
    {
        if (! $this->moduleAvailable('flow_routes', 'flow_routes')) {
            return;
        }

        $this->refs['flow_capability'] = $this->row('flow_route_capabilities', [
            'key' => 'showcase_create_high_intent_task',
        ], [
            'module_key' => 'tasks',
            'capability_type' => 'action',
            'point_type' => 'create_task',
            'handler_key' => 'tasks.create',
            'event_key' => 'inbound.reply.high_intent',
            'action_key' => 'create_task',
            'name' => 'Create a high-intent follow-up task',
            'description' => 'Creates a task with the contact, inbound reply, and triggering outbound message attached.',
            'category' => 'follow_up',
            'surface' => 'client',
            'supported_subjects' => ['contact', 'inbound_message'],
            'required_modules' => ['tasks', 'inbound_messaging', 'messaging'],
            'input_schema' => ['task_template_key' => ['type' => 'string', 'required' => true]],
            'output_schema' => ['task_id' => ['type' => 'integer']],
            'available_fields' => ['contact.id', 'inbound_message.id', 'scheduled_message.id'],
            'defaults' => ['priority' => 'urgent', 'due_offset_minutes' => 15],
            'is_active' => true,
            'source' => 'module',
            'source_version' => 'showcase-1',
            'is_customized' => true,
            'customized_at' => $this->now->subDays(3),
            'meta' => $this->meta(['ux_label' => 'Create immediate follow-up']),
        ]);

        $this->refs['flow_route'] = $this->row('flow_routes', [
            'key' => 'showcase_high_intent_reply',
            'version' => 1,
        ], [
            'contact_status_id' => $this->refs['status_requires_action'],
            'owner_type' => 'App\\Modules\\InboundMessaging\\Models\\InboundReplyProfile',
            'owner_id' => $this->refs['reply_profile'] ?? $this->refs['contact_nina'],
            'owner_group' => 'inbound_replies',
            'name' => 'Showcase · High-intent reply handling',
            'description' => 'Turns a meaningful reply into visible, contextual work.',
            'is_current_version' => true,
            'trigger_type' => 'automation_event',
            'trigger_key' => 'inbound.reply.high_intent',
            'is_active' => true,
            'source_version' => 'showcase-1',
            'is_customized' => true,
            'customized_at' => $this->now->subDays(3),
            'meta' => $this->meta(['authoring_kind' => 'automatic_behavior']),
        ]);

        $this->refs['flow_point'] = $this->row('flow_route_points', [
            'flow_route_id' => $this->refs['flow_route'],
            'key' => 'create_follow_up_task',
        ], [
            'flow_route_capability_id' => $this->refs['flow_capability'],
            'type' => 'create_task',
            'name' => 'Create contextual follow-up',
            'description' => 'Create one urgent task that shows who replied, what they said, and what they answered.',
            'sort_order' => 10,
            'is_start' => true,
            'is_active' => true,
            'next_flow_route_point_id' => null,
            'definition' => ['task_template_key' => (string) $this->refs['task_template_key']],
            'settings' => ['priority' => 'urgent', 'due_offset_minutes' => 15],
            'cancel_conditions' => [['event' => 'contact.deleted'], ['event' => 'task.completed']],
            'source_version' => 'showcase-1',
            'is_customized' => true,
            'customized_at' => $this->now->subDays(3),
            'meta' => $this->meta(['preview_text' => 'Immediate follow-up']),
        ]);
        $this->refs['flow_point_complete'] = $this->row('flow_route_points', [
            'flow_route_id' => $this->refs['flow_route'],
            'key' => 'wait_for_task_completion',
        ], [
            'flow_route_capability_id' => $this->refs['flow_capability'],
            'type' => 'event_wait',
            'name' => 'Wait for the follow-up outcome',
            'description' => 'Keep the route visible until the contextual task is completed.',
            'sort_order' => 20,
            'is_start' => false,
            'is_active' => true,
            'next_flow_route_point_id' => null,
            'definition' => ['event_key' => 'task.completed'],
            'settings' => ['timeout_days' => 7],
            'cancel_conditions' => [['event' => 'contact.deleted']],
            'source_version' => 'showcase-1',
            'is_customized' => true,
            'customized_at' => $this->now->subDays(3),
            'meta' => $this->meta(['terminal_on_event' => true]),
        ]);
        $this->update('flow_route_points', $this->refs['flow_point'], [
            'next_flow_route_point_id' => $this->refs['flow_point_complete'],
        ]);

        $this->row('flow_route_trigger_bindings', [
            'trigger_type' => 'automation_event',
            'trigger_key' => 'inbound.reply.high_intent',
            'flow_route_id' => $this->refs['flow_route'],
        ], [
            'context_type' => 'App\\Modules\\InboundMessaging\\Models\\InboundReplyProfile',
            'context_id' => $this->refs['reply_profile'] ?? $this->refs['contact_nina'],
            'is_active' => true,
            'meta' => $this->meta(['intent_key' => 'start_preapproval']),
        ]);

        $this->row('flow_route_capability_bindings', [
            'flow_route_capability_id' => $this->refs['flow_capability'],
            'context_type' => 'App\\Modules\\InboundMessaging\\Models\\InboundReplyProfile',
            'context_id' => $this->refs['reply_profile'] ?? $this->refs['contact_nina'],
        ], [
            'owner_type' => 'App\\Modules\\Core\\Access\\Models\\Team',
            'owner_id' => $this->refs['team'] ?? $this->refs['owner'],
            'module_key' => 'tasks',
            'visibility' => 'client',
            'sort_order' => 10,
            'label' => 'Create high-intent follow-up',
            'description' => 'Create an urgent task with full message context.',
            'help_text' => 'Use when a person clearly asks to start, apply, schedule, or receive a call.',
            'defaults' => ['task_template_key' => (string) $this->refs['task_template_key']],
            'constraints' => ['requires_contact' => true],
            'input_overrides' => ['priority' => 'urgent'],
            'output_overrides' => ['link_roles' => ['subject', 'context', 'result']],
            'is_enabled' => true,
            'is_customized' => true,
            'customized_at' => $this->now->subDays(3),
            'meta' => $this->meta(['owner_group' => 'sales']),
        ]);

        $this->refs['flow_progress'] = $this->row('contact_flow_route_progress', [
            'contact_id' => $this->refs['contact_nina'],
            'flow_route_id' => $this->refs['flow_route'],
            'subject_type' => 'App\\Modules\\InboundMessaging\\Models\\InboundMessage',
            'subject_id' => $this->refs['inbound_high_intent'] ?? $this->refs['contact_nina'],
        ], [
            'contact_status_id' => $this->refs['status_requires_action'],
            'contact_workflow_profile_id' => $this->refs['workflow_nina'] ?? null,
            'current_flow_route_point_id' => $this->refs['flow_point'],
            'status' => 'waiting',
            'started_at' => $this->now->subHours(2),
            'completed_at' => $this->now->subHour(),
            'cancelled_at' => $this->now->subMinutes(50),
            'failed_at' => $this->now->subMinutes(45),
            'resume_at' => $this->now->addMinutes(5),
            'waiting_event_key' => 'task.completed',
            'cancellation_reason' => 'Earlier run superseded by a newer inbound reply.',
            'failure_reason' => 'First task creation attempt encountered a transient lock.',
            'meta' => $this->meta(['task_id' => $this->refs['task_high_intent'] ?? null]),
        ]);

        $this->refs['flow_plan_previous'] = $this->row('contact_flow_route_plans', [
            'contact_flow_route_progress_id' => $this->refs['flow_progress'],
            'revision' => 0,
        ], [
            'contact_id' => $this->refs['contact_nina'],
            'subject_type' => 'App\\Modules\\InboundMessaging\\Models\\InboundMessage',
            'subject_id' => $this->refs['inbound_high_intent'] ?? $this->refs['contact_nina'],
            'flow_route_id' => $this->refs['flow_route'],
            'status' => 'superseded',
            'source' => 'automation',
            'flow_route_version' => 1,
            'snapshot_at' => $this->now->subHours(3),
            'started_at' => $this->now->subHours(3),
            'completed_at' => null,
            'cancelled_at' => null,
            'failed_at' => null,
            'superseded_at' => $this->now->subHours(2),
            'reconciled_from_plan_id' => null,
            'cancellation_reason' => null,
            'failure_reason' => null,
            'route_snapshot' => ['key' => 'showcase_high_intent_reply', 'version' => 1],
            'meta' => $this->meta(['superseded_reason' => 'Route definition refreshed']),
        ]);

        $this->refs['flow_plan'] = $this->row('contact_flow_route_plans', [
            'contact_flow_route_progress_id' => $this->refs['flow_progress'],
            'revision' => 1,
        ], [
            'contact_id' => $this->refs['contact_nina'],
            'subject_type' => 'App\\Modules\\InboundMessaging\\Models\\InboundMessage',
            'subject_id' => $this->refs['inbound_high_intent'] ?? $this->refs['contact_nina'],
            'flow_route_id' => $this->refs['flow_route'],
            'status' => 'active',
            'source' => 'automation',
            'flow_route_version' => 1,
            'snapshot_at' => $this->now->subHours(2),
            'started_at' => $this->now->subHours(2),
            'completed_at' => $this->now->subHour(),
            'cancelled_at' => $this->now->subMinutes(50),
            'failed_at' => $this->now->subMinutes(45),
            'superseded_at' => $this->now->subMinutes(40),
            'reconciled_from_plan_id' => $this->refs['flow_plan_previous'],
            'cancellation_reason' => 'Showcase cancellation history.',
            'failure_reason' => 'Showcase retry history.',
            'route_snapshot' => ['key' => 'showcase_high_intent_reply', 'version' => 1],
            'meta' => $this->meta(['trigger_event' => 'inbound.reply.high_intent']),
        ]);

        $this->refs['flow_plan_item'] = $this->row('contact_flow_route_plan_items', [
            'contact_flow_route_plan_id' => $this->refs['flow_plan'],
            'key' => 'create_follow_up_task',
            'sequence' => 1,
            'attempt' => 1,
        ], [
            'contact_flow_route_progress_id' => $this->refs['flow_progress'],
            'flow_route_id' => $this->refs['flow_route'],
            'flow_route_point_id' => $this->refs['flow_point'],
            'flow_route_capability_id' => $this->refs['flow_capability'],
            'point_type' => 'create_task',
            'sort_order' => 10,
            'source' => 'automation',
            'status' => 'completed',
            'result_reason' => 'Task created and linked to full reply context.',
            'available_at' => $this->now->subHours(2),
            'started_at' => $this->now->subHours(2),
            'completed_at' => $this->now->subHours(2)->addSecond(),
            'skipped_at' => $this->now->subHour(),
            'cancelled_at' => $this->now->subMinutes(50),
            'failed_at' => $this->now->subMinutes(45),
            'resume_at' => $this->now->addMinutes(5),
            'waiting_event_key' => 'task.completed',
            'definition_snapshot' => ['task_template_key' => (string) $this->refs['task_template_key']],
            'settings_snapshot' => ['priority' => 'urgent'],
            'cancel_conditions_snapshot' => [['event' => 'task.completed']],
            'correlation' => ['inbound_message_id' => $this->refs['inbound_high_intent'] ?? null],
            'result_payload' => ['task_id' => $this->refs['task_high_intent'] ?? null],
            'meta' => $this->meta(['attempt_note' => 'Succeeded after retry']),
        ]);

        $this->row('contact_flow_route_progress_items', [
            'contact_flow_route_progress_id' => $this->refs['flow_progress'],
            'key' => 'create_follow_up_task',
            'sequence' => 1,
            'attempt' => 1,
        ], [
            'contact_flow_route_plan_id' => $this->refs['flow_plan'],
            'contact_flow_route_plan_item_id' => $this->refs['flow_plan_item'],
            'flow_route_id' => $this->refs['flow_route'],
            'flow_route_point_id' => $this->refs['flow_point'],
            'flow_route_capability_id' => $this->refs['flow_capability'],
            'created_subject_type' => 'App\\Modules\\Tasks\\Models\\Task',
            'created_subject_id' => $this->refs['task_high_intent'] ?? null,
            'point_type' => 'create_task',
            'status' => 'completed',
            'result_reason' => 'Created an urgent follow-up task.',
            'started_at' => $this->now->subHours(2),
            'completed_at' => $this->now->subHours(2)->addSecond(),
            'skipped_at' => $this->now->subHour(),
            'cancelled_at' => $this->now->subMinutes(50),
            'failed_at' => $this->now->subMinutes(45),
            'resume_at' => $this->now->addMinutes(5),
            'waiting_event_key' => 'task.completed',
            'correlation_key' => 'showcase-high-intent-nina',
            'correlation_type' => 'inbound_message',
            'correlation' => ['inbound_message_id' => $this->refs['inbound_high_intent'] ?? null],
            'result_payload' => ['task_id' => $this->refs['task_high_intent'] ?? null],
            'meta' => $this->meta(['operator_visible' => true]),
        ]);

        $this->seededModules[] = 'flow_routes';
    }

    private function seedDocuments(): void
    {
        if (! $this->moduleAvailable('documents', 'document_requests')) {
            return;
        }

        $this->refs['document_requirement'] = $this->row('document_requirement_definitions', [
            'key' => 'showcase_income_documentation',
        ], [
            'name' => 'Showcase · Income Documentation',
            'description' => 'Recent paystubs or other income documentation for preapproval.',
            'instructions' => 'Upload the two most recent paystubs. PDF, JPG, and PNG are accepted.',
            'status' => 'active',
            'category' => 'income',
            'is_required_by_default' => true,
            'allows_multiple_uploads' => true,
            'requires_review' => true,
            'accepted_mime_types' => ['application/pdf', 'image/jpeg', 'image/png'],
            'max_file_size_kb' => 20480,
            'expires_after_days' => 30,
            'sort_order' => 10,
            'source' => 'manual',
            'provider' => 'spaces',
            'external_id' => 'showcase-document-requirement-001',
            'external_url' => 'https://example.test/documents/requirements/showcase-income',
            'settings' => ['minimum_uploads' => 2, 'allow_camera' => true],
            'meta' => $this->meta(['loan_program' => 'VA']),
        ]);

        $this->refs['document_request'] = $this->row('document_requests', [
            'request_token' => hash('sha256', self::MARKER.'-document-request'),
        ], [
            'document_requirement_definition_id' => $this->refs['document_requirement'],
            'contact_id' => $this->refs['contact_nina'],
            'subject_type' => 'App\\Modules\\Core\\Models\\Contact',
            'subject_id' => $this->refs['contact_nina'],
            'requested_by_type' => 'App\\Models\\User',
            'requested_by_id' => $this->refs['owner'],
            'assigned_to_type' => 'App\\Models\\User',
            'assigned_to_id' => $this->refs['loan_officer'],
            'title' => 'Upload Nina Patel’s income documentation',
            'instructions' => 'Please upload both recent paystubs before the strategy call.',
            'status' => 'satisfied',
            'priority' => 'high',
            'requested_at' => $this->now->subDays(3),
            'sent_at' => $this->now->subDays(3)->addMinute(),
            'opened_at' => $this->now->subDays(2),
            'first_uploaded_at' => $this->now->subDay(),
            'last_uploaded_at' => $this->now->subHours(18),
            'satisfied_at' => $this->now->subHours(12),
            'waived_at' => $this->now->subMonths(2),
            'expired_at' => $this->now->subMonth(),
            'cancelled_at' => $this->now->subMonths(3),
            'expires_at' => $this->now->addDays(27),
            'source' => 'flow_route',
            'provider' => 'spaces',
            'external_id' => 'showcase-document-request-001',
            'external_url' => 'https://example.test/documents/requests/showcase-income',
            'settings' => ['send_reminders' => true, 'reminder_days' => [3, 7]],
            'meta' => $this->meta(['appointment_id' => $this->refs['appointment'] ?? null]),
        ]);

        $this->refs['document_upload_original'] = $this->row('document_uploads', [
            'external_id' => 'showcase-document-upload-001',
        ], [
            'document_request_id' => $this->refs['document_request'],
            'document_requirement_definition_id' => $this->refs['document_requirement'],
            'contact_id' => $this->refs['contact_nina'],
            'subject_type' => 'App\\Modules\\Core\\Models\\Contact',
            'subject_id' => $this->refs['contact_nina'],
            'uploaded_by_type' => 'App\\Modules\\Core\\Models\\Contact',
            'uploaded_by_id' => $this->refs['contact_nina'],
            'replaces_document_upload_id' => null,
            'title' => 'Nina Patel · Paystub 1',
            'status' => 'replaced',
            'review_status' => 'rejected',
            'disk' => 'spaces',
            'path' => 'showcase/documents/nina-paystub-original.pdf',
            'original_filename' => 'Nina_Patel_Paystub.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 483210,
            'checksum' => hash('sha256', self::MARKER.'-document-original'),
            'storage_visibility' => 'private',
            'submitted_at' => $this->now->subDay(),
            'reviewed_at' => $this->now->subHours(20),
            'approved_at' => $this->now->subMonths(2),
            'rejected_at' => $this->now->subHours(20),
            'expires_at' => $this->now->addYear(),
            'source' => 'public_upload',
            'provider' => 'spaces',
            'external_url' => 'https://cdn.example.test/showcase/nina-paystub-original.pdf',
            'metadata' => ['pages' => 2, 'virus_scan' => 'clean'],
            'meta' => $this->meta(['rejection_reason' => 'Second page was unreadable.']),
        ]);

        $this->refs['document_upload'] = $this->row('document_uploads', [
            'external_id' => 'showcase-document-upload-002',
        ], [
            'document_request_id' => $this->refs['document_request'],
            'document_requirement_definition_id' => $this->refs['document_requirement'],
            'contact_id' => $this->refs['contact_nina'],
            'subject_type' => 'App\\Modules\\Core\\Models\\Contact',
            'subject_id' => $this->refs['contact_nina'],
            'uploaded_by_type' => 'App\\Modules\\Core\\Models\\Contact',
            'uploaded_by_id' => $this->refs['contact_nina'],
            'replaces_document_upload_id' => $this->refs['document_upload_original'],
            'title' => 'Nina Patel · Paystub 1 · Replacement',
            'status' => 'approved',
            'review_status' => 'approved',
            'disk' => 'spaces',
            'path' => 'showcase/documents/nina-paystub-replacement.pdf',
            'original_filename' => 'Nina_Patel_Paystub_Clear.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 512448,
            'checksum' => hash('sha256', self::MARKER.'-document-replacement'),
            'storage_visibility' => 'private',
            'submitted_at' => $this->now->subHours(18),
            'reviewed_at' => $this->now->subHours(12),
            'approved_at' => $this->now->subHours(12),
            'rejected_at' => null,
            'expires_at' => $this->now->addYear(),
            'source' => 'public_upload',
            'provider' => 'spaces',
            'external_url' => 'https://cdn.example.test/showcase/nina-paystub-replacement.pdf',
            'metadata' => ['pages' => 2, 'virus_scan' => 'clean', 'ocr_confidence' => 0.98],
            'meta' => $this->meta(['reviewer' => 'Ben Loan Officer']),
        ]);

        $this->row('document_review_events', [
            'document_upload_id' => $this->refs['document_upload'],
            'event' => 'approved',
        ], [
            'document_request_id' => $this->refs['document_request'],
            'actor_type' => 'App\\Models\\User',
            'actor_id' => $this->refs['loan_officer'],
            'from_status' => 'pending',
            'to_status' => 'approved',
            'reason' => 'meets_requirement',
            'notes' => 'Both pages are legible and the pay period is current.',
            'occurred_at' => $this->now->subHours(12),
            'meta' => $this->meta(['review_duration_seconds' => 43]),
        ]);

        $this->seededModules[] = 'documents';
    }

    private function seedReporting(): void
    {
        if (! $this->moduleAvailable('reporting', 'reporting_sessions')) {
            return;
        }

        $sessionValues = [
            'token_hash' => hash('sha256', self::MARKER.'-reporting-session'),
            'host' => 'showcase.example.test',
            'surface' => 'webinar_registration',
            'started_at' => $this->now->subDays(2)->subMinutes(12),
            'last_seen_at' => $this->now->subDays(2)->subMinutes(5),
            'absolute_expires_at' => $this->now->addDays(28),
            'landing_path' => '/webinars/showcase-va-homebuyer-september',
            'referrer_host' => 'facebook.com',
            'utm_source' => 'facebook',
            'utm_medium' => 'paid_social',
            'utm_campaign' => 'showcase_va_september',
            'utm_content' => 'veteran_family_video',
            'utm_term' => 'va home loan',
            'external_platform' => 'meta',
            'external_campaign_id' => 'showcase-meta-campaign-001',
            'external_group_id' => 'showcase-meta-adset-001',
            'external_creative_id' => 'showcase-meta-creative-001',
            'external_placement' => 'facebook_feed',
            'click_id_hashes' => ['fbclid' => hash('sha256', self::MARKER.'-fbclid')],
            'traffic_class' => 'likely_human',
            'classifier_key' => 'browser_signals',
            'classifier_version' => 2,
            'classification_reasons' => ['navigation_timing', 'pointer_activity', 'form_interaction'],
            'device_class' => 'mobile',
            'browser_family' => 'Chrome Mobile',
            'os_family' => 'iOS',
        ];
        $this->refs['reporting_session'] = $this->row('reporting_sessions', [
            'token_hash' => $sessionValues['token_hash'],
            'host' => $sessionValues['host'],
        ], array_diff_key($sessionValues, ['token_hash' => true, 'host' => true]));

        $this->row('reporting_observations', [
            'event_id' => 'b2f27167-d57f-45d3-b63e-bf6462a30fb0',
        ], [
            'payload_hash' => hash('sha256', self::MARKER.'-observation-payload'),
            'reporting_session_id' => $this->refs['reporting_session'],
            'event_key' => 'webinar.registration.completed',
            'event_version' => 1,
            'source' => 'browser',
            'occurred_at' => $this->now->subDays(2)->subMinutes(5),
            'received_at' => $this->now->subDays(2)->subMinutes(5)->addSecond(),
            'host' => $sessionValues['host'],
            'surface' => $sessionValues['surface'],
            'path' => '/webinars/showcase-va-homebuyer-september/thank-you',
            'referrer_host' => $sessionValues['referrer_host'],
            'utm_source' => $sessionValues['utm_source'],
            'utm_medium' => $sessionValues['utm_medium'],
            'utm_campaign' => $sessionValues['utm_campaign'],
            'utm_content' => $sessionValues['utm_content'],
            'utm_term' => $sessionValues['utm_term'],
            'external_platform' => $sessionValues['external_platform'],
            'external_campaign_id' => $sessionValues['external_campaign_id'],
            'external_group_id' => $sessionValues['external_group_id'],
            'external_creative_id' => $sessionValues['external_creative_id'],
            'external_placement' => $sessionValues['external_placement'],
            'click_id_hashes' => $sessionValues['click_id_hashes'],
            'traffic_class' => $sessionValues['traffic_class'],
            'classifier_key' => $sessionValues['classifier_key'],
            'classifier_version' => $sessionValues['classifier_version'],
            'classification_reasons' => $sessionValues['classification_reasons'],
            'device_class' => $sessionValues['device_class'],
            'browser_family' => $sessionValues['browser_family'],
            'os_family' => $sessionValues['os_family'],
            'properties' => ['registration_id' => $this->refs['registration'] ?? null, 'step' => 'complete'],
        ]);

        $this->row('reporting_external_measurements', [
            'identity_hash' => hash('sha256', self::MARKER.'-external-measurement'),
        ], [
            'period_start' => $this->now->subDays(30)->toDateString(),
            'period_end' => $this->now->toDateString(),
            'platform' => 'meta',
            'account_id' => 'showcase-meta-account-001',
            'account_timezone' => 'America/Chicago',
            'campaign_id' => 'showcase-meta-campaign-001',
            'group_id' => 'showcase-meta-adset-001',
            'creative_id' => 'showcase-meta-creative-001',
            'campaign_name' => 'VA Homebuyer Game Plan',
            'group_name' => 'Veterans · Chicago',
            'creative_name' => 'Family testimonial video',
            'placement' => 'facebook_feed',
            'identity_quality' => 'exact',
            'currency' => 'USD',
            'impressions' => 23927,
            'reach' => 19782,
            'link_clicks' => 611,
            'outbound_clicks' => 587,
            'landing_page_views' => 524,
            'spend' => '847.3200',
            'result_type' => 'webinar_registration',
            'results' => '19.000000',
            'source' => 'csv_import',
            'source_file_hash' => hash('sha256', self::MARKER.'-meta-csv'),
            'meta' => $this->meta(['filename' => 'meta-showcase-september.csv']),
            'imported_at' => $this->now->subHour(),
        ]);

        $this->row('reporting_daily_metrics', [
            'metric_key' => 'webinar_registration_conversion',
            'metric_version' => 1,
            'dimension_hash' => hash('sha256', self::MARKER.'-daily-metric-dimension'),
        ], [
            'metric_date' => $this->now->toDateString(),
            'dimensions' => ['series' => 'showcase-va-homebuyer-game-plan', 'traffic_class' => 'likely_human'],
            'numerator' => 19,
            'denominator' => 524,
            'projected_through' => $this->now,
        ]);

        $this->row('reporting_projection_checkpoints', [
            'projector_key' => 'showcase_webinar_funnel',
            'projector_version' => 1,
        ], [
            'cursor' => 'reporting-observation-showcase-001',
            'window_start' => $this->now->subDays(45),
            'window_end' => $this->now,
            'projected_through' => $this->now->subMinutes(5),
            'meta' => $this->meta(['rows_processed' => 524]),
        ]);

        $this->seededModules[] = 'reporting';
    }

    private function clientReplyTaskTemplate(): ?object
    {
        if ($this->clientReplyTaskTemplate !== null) {
            return $this->clientReplyTaskTemplate;
        }

        $this->clientReplyTaskTemplate = DB::table('task_templates')
            ->where('is_active', true)
            ->where(function ($query): void {
                $query
                    ->where('category', 'reply_follow_up')
                    ->orWhere('key', 'like', '%.high_intent_reply_follow_up');
            })
            ->where('key', 'not like', 'showcase_%')
            ->orderByRaw("case when `key` like '%.high_intent_reply_follow_up' then 0 else 1 end")
            ->orderBy('id')
            ->first();

        return $this->clientReplyTaskTemplate;
    }

    private function clientStatusId(string $key): ?int
    {
        $id = DB::table('contact_statuses')
            ->where('key', $key)
            ->where('is_active', true)
            ->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    private function clientReplyFlow(): ?object
    {
        if ($this->clientReplyFlow !== null) {
            return $this->clientReplyFlow;
        }

        $template = $this->clientReplyTaskTemplate();

        if ($template === null || ! $this->hasTable('flow_route_points')) {
            return null;
        }

        $candidates = DB::table('flow_route_points')
            ->join('flow_routes', 'flow_routes.id', '=', 'flow_route_points.flow_route_id')
            ->where('flow_route_points.type', 'create_task')
            ->where('flow_route_points.is_active', true)
            ->where('flow_routes.is_active', true)
            ->where('flow_routes.key', 'not like', 'showcase_%')
            ->orderByRaw("case when flow_routes.`key` like '%webinar%' then 0 else 1 end")
            ->orderBy('flow_routes.id')
            ->get([
                'flow_routes.id as flow_route_id',
                'flow_routes.key as flow_route_key',
                'flow_routes.name as flow_route_name',
                'flow_route_points.id as flow_route_point_id',
                'flow_route_points.key as flow_route_point_key',
                'flow_route_points.name as flow_route_point_name',
                'flow_route_points.flow_route_capability_id',
                'flow_route_points.type as point_type',
                'flow_route_points.definition',
            ]);

        foreach ($candidates as $candidate) {
            if (data_get($this->decodedArray($candidate->definition ?? null), 'task_template_key') === $template->key) {
                $this->clientReplyFlow = $candidate;

                return $this->clientReplyFlow;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function clientReplyProvenance(): array
    {
        $flow = $this->clientReplyFlow();
        $status = DB::table('contact_statuses')
            ->where('key', 'prospect_nurture')
            ->first();

        if (! is_object($status) && isset($this->refs['workflow_nina'])) {
            $status = DB::table('contact_workflow_profiles')
                ->join('contact_statuses', 'contact_statuses.id', '=', 'contact_workflow_profiles.contact_status_id')
                ->where('contact_workflow_profiles.id', $this->refs['workflow_nina'])
                ->first([
                    'contact_statuses.id',
                    'contact_statuses.key',
                    'contact_statuses.name',
                ]);
        }

        return array_filter([
            'flow_route_id' => $flow->flow_route_id ?? null,
            'flow_route_key' => $flow->flow_route_key ?? null,
            'flow_route_name' => $flow->flow_route_name ?? null,
            'flow_route_point_id' => $flow->flow_route_point_id ?? null,
            'flow_route_point_key' => $flow->flow_route_point_key ?? null,
            'flow_route_point_name' => $flow->flow_route_point_name ?? null,
            'flow_route_capability_id' => $flow->flow_route_capability_id ?? null,
            'point_type' => $flow->point_type ?? null,
            'contact_status_id' => $status->id ?? null,
            'contact_status_key' => $status->key ?? null,
            'contact_status_name' => $status->name ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function seedTaskTemplateDefaultLinks(): void
    {
        $template = $this->clientReplyTaskTemplate;

        if (! is_object($template)) {
            return;
        }

        foreach ($this->decodedArray($template->link_defaults ?? null) as $default) {
            if (! is_array($default)) {
                continue;
            }

            $role = $default['role'] ?? null;
            $source = $default['source'] ?? null;

            if (! is_string($role) || ! in_array($role, ['subject', 'context', 'result'], true)) {
                continue;
            }

            $link = match ($source) {
                'current_contact' => [
                    'linkable_type' => 'App\\Modules\\Core\\Models\\Contact',
                    'linkable_id' => $this->refs['contact_nina'],
                ],
                'current_subject' => [
                    'linkable_type' => 'App\\Modules\\InboundMessaging\\Models\\InboundMessage',
                    'linkable_id' => $this->refs['inbound_high_intent'],
                ],
                default => null,
            };

            if ($link === null) {
                continue;
            }

            $this->row('task_links', [
                'task_id' => $this->refs['task_high_intent'],
                ...$link,
                'role' => $role,
            ]);
        }
    }

    /** @return array<int|string, mixed> */
    private function decodedArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Insert or update one deterministic showcase row and return its primary key.
     * Unknown columns are ignored so the seeder can run during incremental module work;
     * assertCoveredColumns() catches newly added surface columns after all rows exist.
     *
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $values
     */
    private function row(string $table, array $identity, array $values = []): int
    {
        if (! $this->hasTable($table)) {
            throw new RuntimeException("Surface showcase expected table [{$table}] to exist.");
        }

        $columns = array_flip($this->columns($table));
        $identity = array_intersect_key($identity, $columns);
        $values = array_intersect_key($values, $columns);

        if ($identity === []) {
            throw new RuntimeException("Surface showcase has no usable identity columns for [{$table}].");
        }

        $this->recordCoverage($table, $identity + $values);

        $databaseIdentity = $this->databaseValues($identity);
        $databaseValues = $this->databaseValues($values);
        $query = DB::table($table);

        foreach ($databaseIdentity as $column => $value) {
            $value === null
                ? $query->whereNull($column)
                : $query->where($column, $value);
        }

        $existing = $query->first();

        if ($existing !== null) {
            if (isset($columns['updated_at'])) {
                $databaseValues['updated_at'] = $this->now;
            }

            $updateQuery = DB::table($table);
            foreach ($databaseIdentity as $column => $value) {
                $value === null
                    ? $updateQuery->whereNull($column)
                    : $updateQuery->where($column, $value);
            }
            $updateQuery->update($databaseValues);

            return property_exists($existing, 'id') ? (int) $existing->id : 0;
        }

        $insert = $databaseIdentity + $databaseValues;
        if (isset($columns['created_at']) && ! array_key_exists('created_at', $insert)) {
            $insert['created_at'] = $this->now;
        }
        if (isset($columns['updated_at']) && ! array_key_exists('updated_at', $insert)) {
            $insert['updated_at'] = $this->now;
        }

        if (! isset($columns['id'])) {
            DB::table($table)->insert($insert);

            return 0;
        }

        return (int) DB::table($table)->insertGetId($insert);
    }

    /** @param array<string, mixed> $values */
    private function update(string $table, int|string|null $id, array $values): void
    {
        if ($id === null || ! $this->hasTable($table)) {
            return;
        }

        $columns = array_flip($this->columns($table));
        $values = array_intersect_key($values, $columns);
        $this->recordCoverage($table, $values);

        if (isset($columns['updated_at'])) {
            $values['updated_at'] = $this->now;
        }

        DB::table($table)->where('id', $id)->update($this->databaseValues($values));
    }

    private function moduleAvailable(string $module, string $requiredTable): bool
    {
        if (! $this->hasTable($requiredTable)) {
            return false;
        }

        return in_array(
            $module,
            app(ModuleManager::class)->enabledKeysWithDependencies(),
            true,
        );
    }

    private function requireTable(string $table, string $module): void
    {
        if (! $this->hasTable($table)) {
            throw new RuntimeException(
                "SurfaceShowcaseSeeder cannot seed enabled module [{$module}] because table [{$table}] is missing.",
            );
        }
    }

    private function hasTable(string $table): bool
    {
        return Schema::hasTable($table);
    }

    /** @return array<int, string> */
    private function columns(string $table): array
    {
        return $this->columnCache[$table] ??= Schema::getColumnListing($table);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function databaseValues(array $values): array
    {
        foreach ($values as $column => $value) {
            if (is_array($value)) {
                $values[$column] = json_encode(
                    $value,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $values */
    private function recordCoverage(string $table, array $values): void
    {
        foreach ($values as $column => $value) {
            if ($value !== null) {
                $this->coveredColumns[$table][$column] = true;
            }
        }
    }

    private function assertCoveredColumns(): void
    {
        $ignored = array_fill_keys([
            'id',
            'created_at',
            'updated_at',
            'deleted_at',
        ], true);
        $missing = [];

        foreach ($this->coveredColumns as $table => $covered) {
            foreach ($this->columns($table) as $column) {
                if (! isset($ignored[$column]) && ! isset($covered[$column])) {
                    $missing[] = "{$table}.{$column}";
                }
            }
        }

        if ($missing !== []) {
            sort($missing);

            throw new RuntimeException(
                'Surface showcase schema coverage is incomplete: '.implode(', ', $missing),
            );
        }
    }

    /** @param array<string, mixed> $values */
    private function meta(array $values = []): array
    {
        return ['showcase' => self::MARKER] + $values;
    }
}