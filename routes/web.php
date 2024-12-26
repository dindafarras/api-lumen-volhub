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
    $router->get('detailActivity', 'UserApiController@detailActivity');

    //Employers
    $router->get('employers', 'UserApiController@employers');
    $router->get('detailEmployer', 'UserApiController@detailEmployer');

    $router->group([
        'middleware' => 'jwt.auth.user',
    ], function () use ($router) {
        // Profile user
        $router->get('profile', 'UserApiController@profile');
        $router->put('editProfile', 'UserApiController@editProfile');

        // Melakukan pendaftaran
        $router->post('apply', 'UserApiController@applyActivity');
    
        // Kelola Skill
        $router->post('addSkill', 'UserApiController@addSkill');
        $router->delete('deleteSkill', 'UserApiController@deleteSkill');

        // Kelola Experience
        $router->post('addExperience', 'UserApiController@addExperience');
        $router->put('editExperience', 'UserApiController@editExperience');
        $router->delete('deleteExperience', 'UserApiController@deleteExperience');

        //Logout
        $router->post('logout', 'UserApiController@logout');
    });
});

$router->group([
    'prefix' => 'admin',
], function () use ($router) {
    $router->post('login', 'AdminApiController@loginAdmin');

    $router->group([
        'middleware' => 'jwt.auth.admin',
    ], function () use ($router) {
        // Profile admin
        $router->get('profile', 'AdminApiController@profile');
        $router->put('editProfile', 'AdminApiController@editProfile');
        
        // Kelola Kategori
        $router->get('category', 'AdminApiController@category');
        $router->post('addCategory', 'AdminApiController@addCategory');
        $router->put('editCategory', 'AdminApiController@editCategory');
        $router->delete('deleteCategory', 'AdminApiController@deleteCategory');

        // Kelola User
        $router->get('user', 'AdminApiController@user');
        $router->get('detailUser', 'AdminApiController@detailUser');

        // Kelola Mitra
        $router->get('mitra', 'AdminApiController@mitra');
        $router->get('detailMitra', 'AdminApiController@detailMitra');

        //Logout
        $router->post('logout', 'AdminApiController@logout');

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
        'middleware' => 'jwt.auth.employer',
    ], function () use ($router) {
        // Profile Employer
        $router->get('profile', 'EmployerApiController@profile');
        $router->put('editProfile', 'EmployerApiController@editProfile');

        // Kelola Activity
        $router->get('activities', 'EmployerApiController@activities');
        $router->get('detailActivity', 'EmployerApiController@detailActivity');
        $router->post('addActivity', 'EmployerApiController@addActivity');
        $router->put('editActivity', 'EmployerApiController@editActivity');
        $router->delete('deleteActivity', 'EmployerApiController@deleteActivity');

        // Kelola Benefit
        $router->post('addBenefit', 'EmployerApiController@addBenefit');
        $router->delete('deleteBenefit', 'EmployerApiController@deleteBenefit');

        // Kelola Requirement
        $router->post('addRequirement', 'EmployerApiController@addRequirement');
        $router->delete('deleteRequirement', 'EmployerApiController@deleteRequirement');

        // Kelola Pendaftar
        $router->get('applicants', 'EmployerApiController@applicants');
        $router->get('detailApplicant', 'EmployerApiController@detailApplicant');

        $router->put('updateApplicant', 'EmployerApiController@updateApplicant');
        $router->put('updateInterview', 'EmployerApiController@updateInterview');

        //Logout
        $router->post('logout', 'EmployerApiController@logout');
    });
});
