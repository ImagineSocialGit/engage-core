<?php

return [

    'default_workflow_status_key' => 'new',

    'labels' => [
        'singular' => 'lead',
        'plural' => 'leads',
    ],

    'routes' => [
        'plural' => 'leads',
    ],

    'sources' => [

        'webinar' => [
            'enabled' => true,
            'label' => 'Webinar',
            'type' => 'internal',
        ],

        'website' => [
            'enabled' => true,
            'label' => 'Website',
            'type' => 'internal',
        ],

        'realtor' => [
            'enabled' => false,
            'label' => 'Realtor.com',
            'type' => 'external',
        ],

        'zillow' => [
            'enabled' => false,
            'label' => 'Zillow',
            'type' => 'external',
        ],

        'facebook' => [
            'enabled' => false,
            'label' => 'Facebook Lead Ads',
            'type' => 'external',
        ],

        'google_ads' => [
            'enabled' => false,
            'label' => 'Google Ads',
            'type' => 'external',
        ],

        'manual' => [
            'enabled' => true,
            'label' => 'Manual Entry',
            'type' => 'internal',
        ],

    ],

    'queues' => [

        'ingestion' => env('LEAD_INGESTION_QUEUE', 'default'),

        'enrichment' => env('LEAD_ENRICHMENT_QUEUE', 'default'),
    ],

    'deduplication' => [

        'enabled' => true,

        'match_on' => [
            'email',
            'phone',
        ],
    ],

    'spam_protection' => [

        'store_risk_score' => true,

        'auto_flag_threshold' => 75,
    ],

];
