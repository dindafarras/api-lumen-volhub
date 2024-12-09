<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract; // Tambahkan ini
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Model implements JWTSubject, AuthenticatableContract // Tambahkan AuthenticatableContract
{
    use Authenticatable;

    protected $fillable = [
        'username',
        'password',
        'email_user',
        'nama_user',
        'nomor_telephone',
        'pendidikan_terakhir',
        'gender',
        'domisili',
        'deskripsi',
        'bio',
        'usia',
        'foto_profile',
        'cv',
        'instagram',
        'linkedIn'
    ];

    protected $hidden = [
        'password'
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

    public function skills()
    {
        return $this->belongsToMany(Skill::class, 'user_skill', 'id_user', 'id_skill');
    }
    public function pendaftars()
    {
        return $this->hasMany(Pendaftar::class, 'id_user');
    }
    
    public function experiences()
    {
        return $this->hasMany(Experience::class, 'id_user');
    }

}
