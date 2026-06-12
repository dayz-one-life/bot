<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    protected $guarded = [];
    protected $casts = [
        'link_rewarded' => 'boolean',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function lives() { return $this->hasMany(Life::class); }
    public function sessions() { return $this->hasMany(GameSession::class); }
    public function bans() { return $this->hasMany(Ban::class); }
    public function referrer() { return $this->belongsTo(Player::class, 'referrer_id'); }
    public function referrals() { return $this->hasMany(Player::class, 'referrer_id'); }

    public function openLife(): ?Life
    {
        return $this->lives()->whereNull('ended_at')->latest('started_at')->first();
    }

    public function openSession(): ?GameSession
    {
        return $this->sessions()->whereNull('disconnected_at')->latest('connected_at')->first();
    }
}
