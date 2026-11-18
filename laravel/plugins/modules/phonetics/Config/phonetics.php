<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LMS integration contract
|--------------------------------------------------------------------------
| The plugin owns its vocabulary, assignments, exams and results. It does
| not own or alter the host LMS course tables. Map the host LMS catalog here
| when installing in a different Laravel LMS. Table/column identifiers must
| be trusted administrator/developer configuration, never request input.
*/
return [
    'standalone' => env('PHONETICS_STANDALONE', false),
    'middleware' => env('PHONETICS_STANDALONE', false)
        ? [
            'practice' => ['web'],
            'attempt' => ['web', 'auth'],
            'exam' => ['web', 'auth'],
            'admin' => ['web', 'auth', 'can:manage-phonetics'],
        ]
        : [
            'practice' => ['web', 'installed', 'plugin:phonetics'],
            'attempt' => ['web', 'installed', 'plugin:phonetics', 'auth'],
            'exam' => ['web', 'installed', 'plugin:phonetics', 'auth'],
            'admin' => ['web', 'installed', 'auth', 'plugin:phonetics', 'permission:phonetics.manage'],
        ],
    'users' => [
        'table' => env('PHONETICS_USERS_TABLE', 'users'),
        'id' => 'id',
        'name' => 'name',
        'email' => 'email',
        'model' => env('PHONETICS_USER_MODEL'),
    ],
    'driver' => env('PHONETICS_LMS_DRIVER', 'aec'),
    'catalog' => [
        'courses' => [
            'table' => env('PHONETICS_COURSES_TABLE', 'courses'),
            'id' => 'id',
            'title' => 'title',
            'slug' => 'slug',
            'category_id' => 'category_id',
            'price' => 'price',
            'status' => 'status',
        ],
        'categories' => [
            'table' => env('PHONETICS_COURSE_CATEGORIES_TABLE', 'course_categories'),
            'id' => 'id',
            'title' => 'name',
        ],
        'lessons' => [
            'table' => env('PHONETICS_LESSONS_TABLE', 'course_lessons'),
            'id' => 'id',
            'course_id' => 'course_id',
            'title' => 'title',
            'sort_order' => 'sort_order',
        ],
        'batches' => [
            'table' => env('PHONETICS_BATCHES_TABLE', 'study_batches'),
            'id' => 'id',
            'title' => 'name',
            'course_title' => 'course_title',
        ],
        'batch_memberships' => [
            'table' => env('PHONETICS_BATCH_MEMBERSHIPS_TABLE', 'phonetic_batch_memberships'),
            'user_id' => 'user_id',
            'batch_id' => 'batch_ref',
            'driver_column' => 'lms_driver',
            'active_column' => 'is_active',
            'active_value' => 1,
        ],
        'enrollments' => [
            'table' => env('PHONETICS_ENROLLMENTS_TABLE', 'enrollments'),
            'user_id' => 'user_id',
            'course_id' => 'course_id',
            'status' => 'status',
            'active_statuses' => ['active', 'completed'],
        ],
    ],
    'access' => [
        'free_price_column' => 'price',
        'free_price_operator' => '<=',
        'free_price_value' => 0,
        'admin_roles' => ['admin', 'super_admin'],
        'course_active_statuses' => ['published', 'active'],
        'paid_enrollment_statuses' => ['active', 'completed'],
    ],
];
