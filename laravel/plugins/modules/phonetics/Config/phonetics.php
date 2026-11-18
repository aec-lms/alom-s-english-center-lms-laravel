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
        'paid_enrollment_statuses' => ['active', 'completed'],
    ],
];
