<?php

use Illuminate\Support\Facades\Redis;

$router->group([
    'prefix' => 'user',
], function () use ($router) {
    // Login User
    $router->post('login', 'UserApiController@loginUser');

    //Registrasi User
    $router->post('registrasi', 'UserApiController@registrasiUser');

    $router->group([
        'middleware' => 'jwt.auth',
    ], function () use ($router) {
        // Profile user
        $router->get('profile', 'UserApiController@profile');
        $router->put('editProfile/{userId}', 'UserApiController@editProfile');

        // Melakukan pendaftaran
        $router->post('apply/{idUser}/{idActivity}', 'UserApiController@applyActivity');
    
        // Kelola Skill
        $router->post('addSkill/{idUser}', 'UserApiController@addSkill');
        $router->delete('deleteSkill/{idUser}/{idSkill}', 'UserApiController@deleteSkill');

        // Kelola Experience
        $router->post('addExperience/{idUser}', 'UserApiController@addExperience');
        $router->delete('deleteExperience/{idUser}/{idExperience}', 'UserApiController@deleteExperience');

    });
});

$router->group([
    'prefix' => 'admin',
], function () use ($router) {
    $router->post('login', 'AdminApiController@loginAdmin');

    $router->group([
        'middleware' => 'jwt.auth'
    ], function () use ($router) {
        // Profile admin
        $router->get('profile', 'AdminApiController@profile');
        $router->put('editProfile/{idAdmin}', 'AdminApiController@editProfile');
        
        // Kelola Kategori
        $router->get('category', 'AdminApiController@category');
        $router->post('addCategory', 'AdminApiController@addCategory');
        $router->put('editCategory/{idCategory}', 'AdminApiController@editCategory');
        $router->delete('deleteCategory/{idCategory}', 'AdminApiController@deleteCategory');

        // Kelola User
        $router->get('user', 'AdminApiController@user');
        $router->get('detailUser/{idUser}', 'AdminApiController@detailUser');

        // Kelola Mitra
        $router->get('mitra', 'AdminApiController@mitra');
        $router->get('detailMitra/{idMitra}', 'AdminApiController@detailMitra');

    });
});


$router->group([
    'prefix' => 'employer',
], function () use ($router) {
    // Login Mitra
    $router->post('login', 'EmployerApiController@login');

    // Registrasi Mitra
    $router->post('registrasi', 'EmployerApiController@registrasi');

    $router->group([
        'middleware' => 'jwt.auth',
    ], function () use ($router) {
        // Profile Employer
        $router->get('profile/{idEmployer}', 'EmployerApiController@profile');
        $router->put('editProfile/{idEmployer}', 'EmployerApiController@editProfile');

        // Kelola Activity
        $router->get('activities/{idEmployer}', 'EmployerApiController@activities');
        $router->get('detailActivity/{idEmployer}/{idActivity}', 'EmployerApiController@detailActivity');
        $router->post('addActivity/{idEmployer}', 'EmployerApiController@addActivity');
        $router->put('editActivity/{idEmployer}/{idActivity}', 'EmployerApiController@editActivity');
        $router->delete('deleteActivity/{idEmployer}/{idActivity}', 'EmployerApiController@deleteActivity');

        // Kelola Benefit
        $router->post('addBenefit/{idEmployer}/{idActivity}', 'EmployerApiController@addBenefit');
        $router->delete('deleteBenefit/{idEmployer}/{idActivity}/{idBenefit}', 'EmployerApiController@deleteBenefit');

        // Kelola Requirement
        $router->post('addRequirement/{idEmployer}/{idActivity}', 'EmployerApiController@addRequirement');
        $router->delete('deleteRequirement/{idEmployer}/{idActivity}/{idRequirement}', 'EmployerApiController@deleteRequirement');

        // Kelola Pendaftar
        $router->get('applicants/{idEmployer}', 'EmployerApiController@applicants');
        $router->get('detailApplicant/{idEmployer}/{idApplicant}', 'EmployerApiController@detailApplicant');

        $router->put('updateApplicant/{idEmployer}/{idApplicant}/{idActivity}', 'EmployerApiController@updateApplicant');
        $router->put('updateInterview/{idEmployer}/{idApplicant}/{idActivity}', 'EmployerApiController@updateInterview');
    });
});

$router->get('/redis-test', function () {
    // Menyimpan data ke Redis
    Redis::set('test_key', 'Hello from Redis!');
    
    // Mengambil data dari Redis
    $value = Redis::get('test_key');

    return response()->json([
        'status' => 'success',
        'message' => 'Redis connection works!',
        'data' => $value,
    ]);
});