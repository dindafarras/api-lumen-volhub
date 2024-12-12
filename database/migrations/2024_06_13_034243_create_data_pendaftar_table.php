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
        Schema::create('data_pendaftar', function (Blueprint $table) {
            $table->id('id_pendaftar');
            $table->timestamp('tgl_pendaftaran');
            $table->text('motivasi');
            $table->enum('status_pendaftaran', ['Dalam Review', 'Diterima', 'Ditolak'])->default('Dalam Review');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('data_pendaftar');
    }
};
