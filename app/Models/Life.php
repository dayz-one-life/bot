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
        'birth_announced_at' => 'datetime',
        'eulogy_posted' => 'boolean',
    ];

    public function player() { return $this->belongsTo(Player::class); }
    public function sessions() { return $this->hasMany(GameSession::class); }
    public function announcements() { return $this->hasMany(Announcement::class); }
}
