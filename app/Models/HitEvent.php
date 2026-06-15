<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HitEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'immutable_datetime',
        'victim_hp' => 'integer',
        'victim_x' => 'float',
        'victim_y' => 'float',
    ];

    public function victim()
    {
        return $this->belongsTo(Player::class, 'victim_player_id');
    }
}
