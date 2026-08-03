<?php

namespace App\Http\Resources;

use App\Models\ServiceRequest;
use App\Models\ServiceRequestComment;
use App\Models\User;
use App\Support\Jalali;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * خروجیِ یک درخواست (R25).
 *
 * ─── چرا بیننده لازم است ───────────────────────────────────────────────────
 * دو چیز به **بیننده** بستگی دارد و نه به خودِ درخواست: یادداشت‌های داخلی
 * (که ساکن نباید ببیند) و اینکه چه دکمه‌هایی برایش معنا دارد. اگر این‌ها را
 * کلاینت تصمیم می‌گرفت، کافی بود کسی خروجی خام را نگاه کند تا یادداشتِ
 * مدیریتی را ببیند.
 *
 * @mixin ServiceRequest
 */
class ServiceRequestResource extends JsonResource
{
    public function __construct(ServiceRequest $resource, private readonly User $viewer)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isAdmin = $this->viewer->role->isAdmin();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,

            'category' => $this->category->value,
            'categoryLabel' => $this->category->label(),
            'priority' => $this->priority->value,
            'priorityLabel' => $this->priority->label(),
            'priorityColor' => $this->priority->color(),
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'statusColor' => $this->status->color(),
            'isOpen' => $this->status->isOpen(),

            'unitLabel' => $this->unit?->label(),
            'requesterName' => $this->requester?->name,
            'assignee' => $this->assignee
                ? ['id' => $this->assignee->id, 'name' => $this->assignee->name]
                : null,

            'attachment' => $this->hasAttachment() ? [
                'name' => $this->attachment_name,
                'url' => route('api.service-requests.attachment', $this->resource),
            ] : null,

            'createdAt' => Jalali::dateTime($this->created_at),
            'resolvedAt' => $this->resolved_at ? Jalali::dateTime($this->resolved_at) : null,
            'closedAt' => $this->closed_at ? Jalali::dateTime($this->closed_at) : null,

            /*
             * کامنت‌ها فقط وقتی می‌آیند که رابطه بارگذاری شده باشد؛ در
             * فهرست لازم نیستند و آوردنشان یک N+1 تمام‌عیار می‌شد.
             */
            'comments' => $this->whenLoaded('comments', fn () => $this->comments
                ->filter(fn (ServiceRequestComment $c) => $isAdmin || ! $c->is_internal)
                ->map(fn (ServiceRequestComment $c) => [
                    'id' => $c->id,
                    'body' => $c->body,
                    // نویسنده‌ی حذف‌شده: رابطه هست ولی رکورد ممکن است نباشد
                    'authorName' => $c->author === null ? 'کاربر حذف‌شده' : $c->author->name,
                    'isMine' => $c->user_id === $this->viewer->id,
                    'isInternal' => $c->is_internal,
                    'sentAt' => Jalali::dateTime($c->created_at),
                ])->values()->all()),

            /*
             * اینکه چه کاری از دستِ این بیننده برمی‌آید، سمتِ سرور تصمیم
             * می‌شود. نقشِ بیننده هم صریح می‌آید و نه از روی حدسِ کلاینت
             * (مثلاً مقایسه‌ی نام)، چون دو نفر می‌توانند هم‌نام باشند.
             */
            'can' => [
                'assign' => $isAdmin,
                'noteInternally' => $isAdmin,
                'isRequester' => $this->user_id === $this->viewer->id,
                'isAssignee' => $this->assigned_to === $this->viewer->id,
            ],
        ];
    }
}
