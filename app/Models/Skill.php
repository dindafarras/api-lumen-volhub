<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Skill extends Model
{
    use HasFactory;
    protected $table = 'skill';
    public $timestamps = false;
    protected $primaryKey = 'id_skill';
    protected $fillable = [
        'nama_skill',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_skill', 'id_skill', 'id_user');
    }

}
