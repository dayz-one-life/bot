<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BunkerVisit extends Model
{
    protected $guarded = [];

    protected $casts = [
        'visited_at' => 'immutable_datetime',
    ];

    public function player()
    {
        return $this->belongsTo(Player::class);
    }

    public function life()
    {
        return $this->belongsTo(Life::class);
    }
}
