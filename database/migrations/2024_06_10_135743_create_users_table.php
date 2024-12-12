<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('password');
            $table->string('email_user');
            $table->string('nama_user');
            $table->string('nomor_telephone');
            $table->enum('pendidikan_terakhir', ['SD', 'SMP', 'SMA/SMK', 'Diploma (D1 - D4)', 'Sarjana (S1)', 'Magister (S2)', 'Doktor (S3)'])->nullable();
            $table->enum('gender', ['Perempuan', 'Laki-Laki'])->nullable();
            $table->string('domisili')->nullable();
            $table->text('deskripsi')->nullable();
            $table->string('bio')->nullable();
            $table->string('usia')->nullable();
            $table->string('foto_profile')->nullable();
            $table->string('cv')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('users');
    }
};
