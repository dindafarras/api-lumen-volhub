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
            $table->date('tgl_interview')->nullable();
            $table->string('lokasi_interview')->nullable();
            $table->enum('status_interview', ['Not scheduled yet', 'On progress', 'Interview Completed'])->nullable();
            $table->text('note_interview')->nullable();
            $table->date('tgl_note')->nullable();
            $table->time('interview_time')->nullable();
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
            $table->dropColumn('tgl_interview')->nullable();
            $table->dropColumn('lokasi_interview')->nullable();
            $table->dropColumn('status_interview', ['Not scheduled yet', 'On progress', 'Interview Completed'])->nullable();
            $table->dropColumn('note_interview')->nullable();
            $table->dropColumn('tgl_note')->nullable();
            $table->dropColumn('interview_time')->nullable();
        });
    }
};
