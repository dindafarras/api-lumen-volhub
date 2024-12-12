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
        Schema::table('data_pendaftar', function (Blueprint $table) {
            $table->dropForeign(['id_interview']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('data_pendaftar', function (Blueprint $table) {
            $table->unsignedBigInteger('id_interview')->nullable();
            $table->foreign('id_interview')->references('id_interview')->on('interview_proses');
        });
    }
};
