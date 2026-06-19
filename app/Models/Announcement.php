<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    protected $guarded = [];
    protected $casts = ['was_fallback' => 'boolean'];

    public function life() { return $this->belongsTo(Life::class); }
}
