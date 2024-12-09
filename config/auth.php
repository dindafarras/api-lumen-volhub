<?php

return [
    'defaults' => [
        'guard' => 'user',
        'passwords' => 'users',
    ],

    'guards' => [
        'user' => [
            'driver' => 'jwt',
            'provider' => 'users',
        ],
        'admin' => [
            'driver' => 'jwt',
            'provider' => 'admins',
        ],
        'employer' => [
            'driver' => 'jwt',
            'provider' => 'employers',
        ],

    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],
        'admins' => [
            'driver' => 'eloquent',
            'model' => App\Models\Admin::class,
        ],
        'employers' => [
            'driver' => 'eloquent',
            'model' => App\Models\Mitra::class,
        ],
    ],
];