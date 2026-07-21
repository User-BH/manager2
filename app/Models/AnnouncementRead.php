<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ردیف «این کاربر این اطلاعیه را خوانده». نبودِ ردیف یعنی نخوانده.
 */
class AnnouncementRead extends Model
{
    public $timestamps = false;

    protected $fillable = ['announcement_id', 'user_id', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
