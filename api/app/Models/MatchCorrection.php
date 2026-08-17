<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MatchCorrection extends Model
{
    protected $fillable = ['ingredient', 'sku', 'title', 'hits'];
}
