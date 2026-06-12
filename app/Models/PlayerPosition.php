<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerPosition extends Model
{
    protected $guarded = [];
    protected $casts = [
        'x' => 'float',
        'y' => 'float',
        'recorded_at' => 'datetime',
    ];

    public function player() { return $this->belongsTo(Player::class); }
}
