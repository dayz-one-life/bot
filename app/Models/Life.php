<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Life extends Model
{
    protected $guarded = [];
    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'playtime_seconds' => 'integer',
        'ban_issued' => 'boolean',
        'death_distance' => 'float',
    ];

    public function player() { return $this->belongsTo(Player::class); }
    public function sessions() { return $this->hasMany(GameSession::class); }
}
