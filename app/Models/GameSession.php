<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameSession extends Model
{
    protected $guarded = [];
    protected $casts = [
        'connected_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'duration_seconds' => 'integer',
    ];

    public function player() { return $this->belongsTo(Player::class); }
    public function life() { return $this->belongsTo(Life::class); }
}
