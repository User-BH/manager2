<?php

namespace App\Http\Requests;

/**
 * قواعدِ ساخت و ویرایشِ اطلاعیه.
 *
 * `store` و `update` هر دو از همین کلاس استفاده می‌کنند چون قواعدشان یکی است؛
 * اگر روزی فرق کردند، `UpdateAnnouncementRequest` از این ارث می‌برد و فقط
 * تفاوت‌ها را بازنویسی می‌کند — نه اینکه کلِ قواعد کپی شود.
 */
class StoreAnnouncementRequest extends BaseFormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            // مخاطب سه حالتِ ثابت دارد و با enum سمتِ مدل جور است
            'audience' => ['required', 'in:all,owners,tenants'],
            'is_pinned' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => 'عنوان',
            'body' => 'متن',
            'audience' => 'مخاطب',
            'is_pinned' => 'سنجاق‌شده',
        ];
    }
}
