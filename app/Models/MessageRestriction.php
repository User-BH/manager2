<?php

namespace App\Models;

use App\Models\Concerns\BelongsToComplex;
use Illuminate\Database\Eloquent\Model;

class MessageRestriction extends Model
{
    use BelongsToComplex;

    protected $fillable = ['complex_id', 'user_id', 'created_by', 'reason'];
}
