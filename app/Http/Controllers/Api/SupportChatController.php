<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupportChatReplyRequest;
use App\Services\Support\KnowledgeBase;
use App\Services\Support\SupportBot;
use Illuminate\Http\JsonResponse;

/**
 * چت پشتیبانی صفحه‌ی فرود.
 *
 * عمومی است چون بازدیدکننده‌ی هنوز ثبت‌نام‌نکرده مخاطب اصلی‌اش است؛ به همین
 * دلیل هم محدودیت نرخ دارد و هیچ چیزی در دیتابیس ذخیره نمی‌کند.
 */
class SupportChatController extends Controller
{
    public function __construct(protected SupportBot $bot) {}

    /** سوال‌های پیشنهادی برای شروع گفت‌وگو. */
    public function starters(): JsonResponse
    {
        return response()->json(['starters' => KnowledgeBase::starters()]);
    }

    public function reply(SupportChatReplyRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json($this->bot->reply($data['message']));
    }
}
