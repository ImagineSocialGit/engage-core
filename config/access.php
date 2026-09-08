<?php

return [
    'default_role' => 'member',
    'legacy_role' => 'owner',

    'roles' => [
        'owner' => [
            'label' => 'Owner',
            'description' => 'Full access to the CRM, team administration, settings, and all registered capabilities.',
            'capabilities' => ['*'],
        ],
        'admin' => [
            'label' => 'Admin',
            'description' => 'Full operational access, including team administration and all registered capabilities.',
            'capabilities' => ['*'],
        ],
        'manager' => [
            'label' => 'Manager',
            'description' => 'Manage visible Contacts, work across their Teams, assign ownership, import, and export.',
            'capabilities' => [
                'contacts.view_team',
                'contacts.view_unassigned',
                'contacts.manage',
                'contacts.import',
                'contacts.assign',
                'contacts.export',
            ],
        ],
        'member' => [
            'label' => 'Team Member',
            'description' => 'Work with Contacts directly assigned to them.',
            'capabilities' => [
                'contacts.manage',
            ],
        ],
        'viewer' => [
            'label' => 'Viewer',
            'description' => 'Read Team-visible Contacts without changing them.',
            'capabilities' => [
                'contacts.view_team',
            ],
        ],
    ],
];