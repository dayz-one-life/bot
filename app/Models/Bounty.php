<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bounty extends Model
{
    protected $guarded = [];
    protected $casts = [
        'placed_at' => 'datetime',
        'ended_at' => 'datetime',
        'token_awarded' => 'boolean',
    ];

    public function player() { return $this->belongsTo(Player::class); }
    public function life() { return $this->belongsTo(Life::class); }

    /** The single open bounty (ended_at IS NULL), or null. */
    public static function active(): ?self
    {
        return static::whereNull('ended_at')->first();
    }
}
