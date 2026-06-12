<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssociateOverride extends Model
{
    protected $guarded = [];
    protected $casts = ['force' => 'boolean'];
}
