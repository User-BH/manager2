<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * پیام در پرونده‌ی یک درخواست (R25).
 *
 * `BelongsToComplex` عمداً ندارد: جداسازیِ مجتمع را خودِ درخواست انجام
 * می‌دهد و کامنت جز از راهِ آن خوانده نمی‌شود. با افزودنِ اسکوپِ دوم،
 * `complex_id` تکراری می‌شد و دو منبعِ حقیقت پیدا می‌کرد.
 *
 * @property int $id
 * @property int $service_request_id
 * @property int $user_id
 * @property string $body
 * @property bool $is_internal
 * @property Carbon $created_at
 */
class ServiceRequestComment extends Model
{
    protected $fillable = ['service_request_id', 'user_id', 'body', 'is_internal'];

    protected function casts(): array
    {
        return ['is_internal' => 'boolean'];
    }

    /** @return BelongsTo<ServiceRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
