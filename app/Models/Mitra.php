<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract; // Tambahkan ini
use Tymon\JWTAuth\Contracts\JWTSubject;

class Mitra extends Model implements JWTSubject, AuthenticatableContract
{
    use Authenticatable;

    protected $table = 'mitra';
    public $timestamps = false;
    protected $primaryKey = 'id_mitra';

    protected $fillable = [
        'username',
        'password',
        'email_mitra',
        'nama_mitra',
        'nomor_telephone',
        'industri',
        'ukuran_perusahaan',
        'situs',
        'deskripsi',
        'alamat',
        'bio',
        'logo',
        'gambar',
    ];

    protected $hidden = [
        'password',
    ];

    //TOKEN
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    public function kegiatans()
    {
        return $this->hasMany(Kegiatan::class, 'id_mitra');
    }
}
