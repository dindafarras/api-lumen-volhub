<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Experience extends Model
{
    use HasFactory;

    protected $table = 'experience';
    public $timestamps = false;
    protected $primaryKey = 'id_experience';

    protected $fillable = [
        'judul_kegiatan',
        'lokasi_kegiatan',
        'tgl_mulai',
        'tgl_selesai',
        'deskripsi',
        'mitra',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'id_user');
    }

}