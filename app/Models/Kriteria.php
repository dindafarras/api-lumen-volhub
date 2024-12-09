<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Kriteria extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $table = 'kriteria';
    protected $primaryKey = 'id_kriteria';
    protected $fillable = [
        'nama_kriteria',
    ];

    public function kegiatans()
    {
        return $this->belongsToMany(Kegiatan::class, 'kegiatan_kriteria', 'id_kriteria', 'id_kegiatan');
    }
}
