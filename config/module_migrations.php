<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Migration Ownership Registry
    |--------------------------------------------------------------------------
    |
    | This registry declares the durable owner, target path, schema version,
    | and migration manifest for platform and module-owned schema.
    |
    | The platform path is registered unconditionally by the platform migration
    | provider. Module target paths remain architectural destinations until their
    | relocation batches are applied. Installation and upgrade commands will
    | consume module paths in later batches.
    |
    */

    'platform' => [
        'path' => 'database/migrations/platform',
        'schema_version' => 1,
        'migrations' => [
            '0001_01_01_000000_create_users_table.php',
            '0001_01_01_000001_create_cache_table.php',
            '0001_01_01_000002_create_jobs_table.php',
            '2026_04_09_000000_create_dashboard_acknowledgements_table.php',
            '2026_04_15_203545_create_automation_behavior_occurrences_table.php',
            '2026_04_15_203546_create_automation_opportunities_table.php',
            '2026_07_18_220000_create_automation_event_outbox_events_table.php',
            '2026_07_18_220001_create_automation_event_consumer_receipts_table.php',
            '2026_07_19_040000_create_webhook_inbox_receipts_table.php',
            '2026_08_02_013000_create_project_state_resume_items_table.php',
            '2026_08_05_180000_create_module_installations_table.php',
        ],
    ],

    'modules' => [
        'core' => [
            'path' => 'database/migrations/modules/core',
            'schema_version' => 6,
            'migrations' => [
                '2026_04_15_195800_create_contact_statuses_table.php',
                '2026_04_15_195849_create_contact_import_batches_table.php',
                '2026_04_15_195850_create_contacts_table.php',
                '2026_04_15_195851_create_contact_tags_table.php',
                '2026_04_15_203549_create_notes_table.php',
                '2026_07_13_120000_create_site_settings_table.php',
                '2026_08_19_161800_create_contact_import_occurrences_table.php',
                '2026_08_22_113500_add_birthday_to_contacts_table.php',
                '2026_08_26_145500_create_business_calendars_table.php',
                '2026_08_28_214500_create_contact_import_runs_table.php',
                '2026_08_29_191700_add_batch_contact_index_to_contact_import_occurrences_table.php',
            ],
        ],

        'relationships' => [
            'path' => 'database/migrations/modules/relationships',
            'schema_version' => 1,
            'migrations' => [
                '2026_08_19_175000_create_contact_relationships_table.php',
            ],
        ],

        'messaging' => [
            'path' => 'database/migrations/modules/messaging',
            'schema_version' => 4,
            'migrations' => [
                '2026_05_15_215534_create_message_consents_table.php',
                '2026_05_15_215834_create_consent_revocations_table.php',
                '2026_05_19_154534_create_scheduled_messages_table.php',
                '2026_05_19_211539_create_message_template_presets_table.php',
                '2026_05_19_211540_create_message_template_preset_assignments_table.php',
                '2026_05_19_211541_create_message_template_catalog_entries_table.php',
                '2026_05_19_211542_create_scheduled_message_delivery_attempts_table.php',
                '2026_05_19_211546_create_scheduled_message_outbox_events_table.php',
                '2026_05_19_221010_create_message_suppressions_table.php',
                '2026_07_01_000001_create_contact_permission_invitations_table.php',
                '2026_07_30_224000_create_message_template_and_chain_tables.php',
                '2026_07_30_224001_create_message_chain_runtime_support_tables.php',
                '2026_08_20_203000_create_scheduled_message_cta_engagements_table.php',
                '2026_08_29_053500_prune_redundant_messaging_indexes.php',
            ],
        ],

        'inbound_messaging' => [
            'path' => 'database/migrations/modules/inbound_messaging',
            'schema_version' => 7,
            'migrations' => [
                '2026_05_19_154535_create_inbound_messages_table.php',
                '2026_07_19_031500_create_inbound_message_receipts_table.php',
                '2026_08_22_061500_add_email_threading_fields_to_inbound_messages_table.php',
                '2026_08_24_220000_create_inbound_reply_profile_tables.php',
                '2026_08_25_120000_create_inbound_email_routes_and_add_route_evidence.php',
                '2026_08_26_054500_add_inbox_triage_to_inbound_messages_table.php',
                '2026_08_26_124500_consolidate_inbound_webhook_persistence.php',
                '2026_08_30_163500_add_automated_response_tracking_to_inbound_messages_table.php',
            ],
        ],

        'internal_notifications' => [
            'path' => 'database/migrations/modules/internal_notifications',
            'schema_version' => 1,
            'migrations' => [
                '2026_04_10_173406_create_team_members_table.php',
                '2026_04_11_174215_create_team_member_notification_preferences_table.php',
            ],
        ],

        'tasks' => [
            'path' => 'database/migrations/modules/tasks',
            'schema_version' => 1,
            'migrations' => [
                '2026_04_15_203543_create_task_templates_table.php',
                '2026_04_15_203544_create_tasks_table.php',
                '2026_04_15_203545_create_task_links_table.php',
            ],
        ],

        'scheduling' => [
            'path' => 'database/migrations/modules/scheduling',
            'schema_version' => 3,
            'migrations' => [
                '2026_04_15_195859_create_scheduling_hosts_table.php',
                '2026_04_15_195860_create_bookable_services_table.php',
                '2026_04_15_195861_create_scheduling_availability_windows_table.php',
                '2026_04_15_195862_create_appointments_table.php',
                '2026_04_15_195863_create_appointment_attendees_table.php',
                '2026_04_15_195864_create_bookable_service_hosts_table.php',
                '2026_04_15_195865_create_appointment_lifecycle_events_table.php',
                '2026_07_21_180100_create_bookable_slot_offers_table.php',
                '2026_07_21_180101_create_booking_holds_table.php',
                '2026_08_03_190000_create_scheduling_resource_occupancy_tables.php',
                '2026_08_27_161500_add_appointment_format_to_bookable_services_table.php',
            ],
        ],

        'portal' => [
            'path' => 'database/migrations/modules/portal',
            'schema_version' => 1,
            'migrations' => [
                '2026_04_15_195870_create_portal_users_table.php',
                '2026_04_15_195871_create_portal_contact_links_table.php',
                '2026_04_15_195872_create_portal_invitations_table.php',
                '2026_04_15_195873_create_portal_access_grants_table.php',
            ],
        ],

        'forms' => [
            'path' => 'database/migrations/modules/forms',
            'schema_version' => 1,
            'migrations' => [
                '2026_04_15_195880_create_form_definitions_table.php',
                '2026_04_15_195881_create_form_versions_table.php',
                '2026_04_15_195882_create_form_submissions_table.php',
                '2026_04_15_195883_create_form_submission_values_table.php',
            ],
        ],

        'documents' => [
            'path' => 'database/migrations/modules/documents',
            'schema_version' => 1,
            'migrations' => [
                '2026_07_02_000001_create_document_requirement_definitions_table.php',
                '2026_07_02_000002_create_document_requests_table.php',
                '2026_07_02_000003_create_document_uploads_table.php',
                '2026_07_02_000004_create_document_review_events_table.php',
            ],
        ],

        'media' => [
            'path' => 'database/migrations/modules/media',
            'schema_version' => 3,
            'migrations' => [
                '2026_09_03_010000_create_media_assets_table.php',
                '2026_09_04_010100_enforce_unique_media_asset_checksums.php',
                '2026_09_04_010200_add_image_similarity_fingerprints.php',
            ],
        ],

        'commerce' => [
            'path' => 'database/migrations/modules/commerce',
            'schema_version' => 1,
            'migrations' => [
                '2026_07_02_000005_create_commerce_customers_table.php',
                '2026_07_02_000006_create_commerce_products_table.php',
                '2026_07_02_000007_create_commerce_orders_table.php',
                '2026_07_02_000008_create_commerce_order_items_table.php',
                '2026_07_02_000009_create_commerce_order_events_table.php',
            ],
        ],

        'location' => [
            'path' => 'database/migrations/modules/location',
            'schema_version' => 2,
            'migrations' => [
                '2026_04_15_195856_create_locations_table.php',
                '2026_04_15_195857_create_contact_locations_table.php',
                '2026_04_15_195858_create_location_areas_table.php',
                '2026_04_15_195859_create_location_area_assignments_table.php',
            ],
        ],

        'events' => [
            'path' => 'database/migrations/modules/events',
            'schema_version' => 1,
            'migrations' => [
                '2026_08_03_200000_create_events_table.php',
                '2026_08_03_200001_create_event_external_references_table.php',
            ],
        ],

        'workflow' => [
            'path' => 'database/migrations/modules/workflow',
            'schema_version' => 1,
            'migrations' => [
                '2026_04_15_195900_create_contact_workflow_profiles_table.php',
            ],
        ],

        'flow_routes' => [
            'path' => 'database/migrations/modules/flow_routes',
            'schema_version' => 1,
            'migrations' => [
                '2026_04_15_203500_create_flow_routes_table.php',
                '2026_04_15_203505_create_flow_route_trigger_bindings_table.php',
                '2026_04_15_203515_create_flow_route_capabilities_table.php',
                '2026_04_15_203516_create_flow_route_capability_bindings_table.php',
                '2026_04_15_203520_create_flow_route_points_table.php',
                '2026_04_15_203530_create_contact_flow_route_progress_table.php',
                '2026_04_15_203535_create_contact_flow_route_plans_table.php',
                '2026_04_15_203536_create_contact_flow_route_plan_items_table.php',
                '2026_04_15_203537_create_contact_flow_route_progress_items_table.php',
            ],
        ],

        'campaigns' => [
            'path' => 'database/migrations/modules/campaigns',
            'schema_version' => 7,
            'migrations' => [
                '2026_06_12_050337_create_campaigns_table.php',
                '2026_06_12_050338_create_campaign_steps_table.php',
                '2026_06_12_050339_create_campaign_enrollments_table.php',
                '2026_08_22_113501_create_campaign_touch_date_tables.php',
                '2026_08_22_114000_add_campaign_touch_runtime.php',
                '2026_08_23_203900_add_campaign_eligibility_foundation.php',
                '2026_08_25_220000_decouple_campaign_touch_programs_from_campaigns.php',
                '2026_08_27_213000_add_audience_filter_to_campaign_touch_programs_table.php',
            ],
        ],

        'broadcasts' => [
            'path' => 'database/migrations/modules/broadcasts',
            'schema_version' => 2,
            'migrations' => [
                '2026_06_12_065258_create_broadcasts_table.php',
                '2026_06_12_065311_create_broadcast_recipients_table.php',
                '2026_08_29_150000_cut_over_broadcast_message_persistence.php',
            ],
        ],

        'webinars' => [
            'path' => 'database/migrations/modules/webinars',
            'schema_version' => 3,
            'migrations' => [
                '2026_06_18_203546_create_webinar_schedule_profiles_table.php',
                '2026_06_18_203547_create_webinar_series_table.php',
                '2026_06_19_203548_create_webinars_table.php',
                '2026_06_19_203549_create_webinar_registrations_table.php',
                '2026_06_20_155410_create_webinar_waitlist_signups_table.php',
                '2026_07_22_160000_create_webinar_registration_responses_table.php',
                '2026_07_31_210000_create_webinar_schedule_profile_chain_bindings_table.php',
                '2026_08_01_023600_create_webinar_series_message_chain_bindings_table.php',
                '2026_08_27_120000_add_provider_lifecycle_to_webinars_table.php',
                '2026_08_27_194500_add_occurrence_visibility_and_suppressions.php',
            ],
        ],

        'reporting' => [
            'path' => 'database/migrations/modules/reporting',
            'schema_version' => 1,
            'migrations' => [
                '2026_08_15_063500_create_reporting_foundation_tables.php',
            ],
        ],

        'mortgage' => [
            'path' => 'database/migrations/verticals/mortgage',
            'schema_version' => 4,
            'migrations' => [
                '2026_06_02_211108_create_mortgage_stages_table.php',
                '2026_06_02_211116_create_contact_mortgage_profiles_table.php',
                '2026_08_19_180000_create_mortgage_history_and_realtor_tables.php',
            ],
        ],
    ],

];