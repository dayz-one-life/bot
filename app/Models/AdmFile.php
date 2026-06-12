<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdmFile extends Model
{
    protected $guarded = [];
    protected $casts = [
        'log_date' => 'datetime',
        'is_complete' => 'boolean',
        'last_processed_line' => 'integer',
        'last_known_size' => 'integer',
    ];
}
