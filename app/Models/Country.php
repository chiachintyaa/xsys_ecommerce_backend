<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    use HasFactory;

    public function states(){
        return $this->hasMany(State::class);
    }

    public function addressCountires(){
        return $this->hasMany(Address::class);
    }


    protected $fillable = [
        'name',
        'slug',
        'status'
    ];


}
