<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Backup extends Model
{
    protected $fillable = [
        'complex_id', 'type', 'status', 'disk', 'path', 'size', 'note', 'created_by',
    ];
}
