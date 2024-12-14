<?php


$router->group([
    'prefix' => 'user',
], function () use ($router) {
    // Login User
    $router->post('login', 'UserApiController@loginUser');

    //Registrasi User
    $router->post('registrasi', 'UserApiController@registrasiUser');

    //Activities
    $router->get('activities', 'UserApiController@activities');
    $router->get('detailActivity/{activityId}', 'UserApiController@detailActivity');

    $router->group([
        'middleware' => 'jwt.auth',
    ], function () use ($router) {
        // Profile user
        $router->get('profile', 'UserApiController@profile');
        $router->put('editProfile/{userId}', 'UserApiController@editProfile');

        // Melakukan pendaftaran
        $router->post('apply/{userId}/{idActivity}', 'UserApiController@applyActivity');
    
        // Kelola Skill
        $router->post('addSkill/{userId}', 'UserApiController@addSkill');
        $router->delete('deleteSkill/{userId}/{idSkill}', 'UserApiController@deleteSkill');

        // Kelola Experience
        $router->post('addExperience/{userId}', 'UserApiController@addExperience');
        $router->delete('deleteExperience/{userId}/{idExperience}', 'UserApiController@deleteExperience');

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
        $router->get('profile', 'EmployerApiController@profile');
        $router->put('editProfile/{employerId}', 'EmployerApiController@editProfile');

        // Kelola Activity
        $router->get('activities/{employerId}', 'EmployerApiController@activities');
        $router->get('detailActivity/{employerId}/{activityId}', 'EmployerApiController@detailActivity');
        $router->post('addActivity/{employerId}', 'EmployerApiController@addActivity');
        $router->put('editActivity/{employerId}/{activityId}', 'EmployerApiController@editActivity');
        $router->delete('deleteActivity/{employerId}/{activityId}', 'EmployerApiController@deleteActivity');

        // Kelola Benefit
        $router->post('addBenefit/{employerId}/{activityId}', 'EmployerApiController@addBenefit');
        $router->delete('deleteBenefit/{employerId}/{activityId}/{idBenefit}', 'EmployerApiController@deleteBenefit');

        // Kelola Requirement
        $router->post('addRequirement/{employerId}/{activityId}', 'EmployerApiController@addRequirement');
        $router->delete('deleteRequirement/{employerId}/{activityId}/{idRequirement}', 'EmployerApiController@deleteRequirement');

        // Kelola Pendaftar
        $router->get('applicants/{employerId}', 'EmployerApiController@applicants');
        $router->get('detailApplicant/{employerId}/{userId}', 'EmployerApiController@detailApplicant');

        $router->put('updateApplicant/{employerId}/{userId}/{activityId}', 'EmployerApiController@updateApplicant');
        $router->put('updateInterview/{employerId}/{userId}/{activityId}', 'EmployerApiController@updateInterview');
    });
});
