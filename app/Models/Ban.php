<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ban extends Model
{
    protected $guarded = [];
    protected $casts = [
        'banned_at' => 'datetime',
        'expires_at' => 'datetime',
        'expired' => 'boolean',
    ];

    public function player() { return $this->belongsTo(Player::class); }
}
