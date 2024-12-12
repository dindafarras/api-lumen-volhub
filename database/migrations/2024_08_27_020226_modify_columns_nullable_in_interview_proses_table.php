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
        Schema::table('interview_proses', function (Blueprint $table) {
            $table->dropColumn('tgl_interview');
            $table->dropColumn('lokasi_interview');
            $table->dropColumn('status_interview');
            $table->dropColumn('note_interview');
        });

        Schema::table('interview_proses', function (Blueprint $table) {
            $table->date('tgl_interview')->nullable();
            $table->string('lokasi_interview', 255)->nullable();
            $table->enum('status_interview', ['Not scheduled yet', 'On progress', 'Interview completed'])->nullable();
            $table->text('note_interview')->nullable();
        });
    }

    public function down()
    {
        Schema::table('interview_proses', function (Blueprint $table) {
            $table->dropColumn('tgl_interview');
            $table->dropColumn('lokasi_interview');
            $table->dropColumn('status_interview');
            $table->dropColumn('note_interview');
        });

        Schema::table('interview_proses', function (Blueprint $table) {
            $table->date('tgl_interview')->nullable(false);
            $table->string('lokasi_interview', 255)->nullable(false);
            $table->enum('status_interview', ['Not scheduled yet', 'On progress', 'Interview completed'])->nullable(false);
            $table->text('note_interview')->nullable(false);
        });
    }
};
