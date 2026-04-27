<?php

return [
    'title' => 'No Upcoming Session',
    'meta_description' => 'This webinar series does not currently have an upcoming scheduled session.',

    'hero' => [
        'enabled' => true,
        'title_prefix' => 'There is not an upcoming session currently scheduled for',
        'body' => 'That series is still available, but the next date has not been posted yet. Check the other available webinars below.',
    ],

    'series_list' => [
        'enabled' => true,
        'heading' => 'Other available webinars',
        'empty_hide' => true,
    ],

    'blocks' => [
        'hero',
        'series_list',
    ],
];