<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pendaftar extends Model
{
    use HasFactory;

    const CREATED_AT = 'tgl_pendaftaran';
    const UPDATED_AT = null;

    public $timestamps = true;
    protected $table = 'data_pendaftar';
    protected $primaryKey = 'id_pendaftar';
    protected $fillable = [
        'motivasi',
        'status_applicant',
        'tgl_pendaftaran',
        'note_to_applicant',
        'tgl_interview',
        'lokasi_interview',
        'status_interview',
        'note_interview',
        'tgl_interview',
        'interview_time',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'id_user');
    }

    public function kegiatan()
    {
        return $this->belongsTo(Kegiatan::class, 'id_kegiatan');
    }

    


}
