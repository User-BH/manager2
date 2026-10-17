<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * اعلان‌های مجتمعیِ موجود مجتمعِ خودشان را می‌گیرند (مرحلهٔ ۱۲ · فاز ۴؛ بندهای ۱۹ و ۲۰ ِ سندِ هسته).
 *
 * از ۱۲/۴ اعلانِ شخصیِ مجتمعی `complexId` در دادهٔ خودش دارد و فقط در زمینه‌ی همان مجتمع دیده می‌شود
 * (`Notifications::inContext`). ردیف‌های پیش از آن کلید ندارند و «حسابی» شمرده می‌شدند — یعنی همان نشتِ پیشین
 * برای تاریخچه. این‌جا مجتمع از رکوردِ مبدأ خوانده می‌شود:
 *
 *   قبض (`billId`) ← `bills`، درخواستِ عضویت (`joinRequestId`) ← `complex_invitations`،
 *   بررسیِ رسید (`paymentId`) ← `payments`، درخواستِ خدمات (`serviceRequestId`) ← `service_requests`.
 *
 * فقط **افزودنِ** یک کلید به JSON است؛ هیچ ردیف یا مقداری حذف نمی‌شود. ردیفی که مبدأش پیدا نشود دست نمی‌خورد
 * (همان رفتارِ پیشین). اعلان‌های حسابی (دستگاهِ تازه، تغییرِ شماره، پاسخِ بازخورد، سلامتِ سامانه) کلید نمی‌گیرند.
 */
return new class extends Migration
{
    /** کلیدِ دادهٔ اعلان ← جدولِ مبدأ */
    private const SOURCES = [
        'billId' => 'bills',
        'joinRequestId' => 'complex_invitations',
        'paymentId' => 'payments',
        'serviceRequestId' => 'service_requests',
    ];

    public function up(): void
    {
        DB::table('notifications')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $data = json_decode((string) $row->data, true);
                if (! is_array($data) || array_key_exists('complexId', $data)) {
                    continue;
                }

                $complexId = $this->complexOf($data);
                if ($complexId === null) {
                    continue;
                }

                $data['complexId'] = $complexId;
                DB::table('notifications')->where('id', $row->id)->update(['data' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
            }
        });
    }

    public function down(): void
    {
        // همان کلیدی که `up` افزود؛ کدِ پیشین آن را نمی‌خواند، پس برداشتنش چیزی را از دست نمی‌دهد
        DB::table('notifications')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $data = json_decode((string) $row->data, true);
                if (! is_array($data) || ! array_key_exists('complexId', $data)) {
                    continue;
                }

                unset($data['complexId']);
                DB::table('notifications')->where('id', $row->id)->update(['data' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
            }
        });
    }

    /** @param  array<string, mixed>  $data */
    private function complexOf(array $data): ?int
    {
        // `paymentId` پیش از `billId`: بررسیِ رسید هر دو را دارد و پرداخت دقیق‌تر است
        foreach (['paymentId', 'joinRequestId', 'serviceRequestId', 'billId'] as $key) {
            if (! isset($data[$key]) || ! is_numeric($data[$key])) {
                continue;
            }

            $complexId = DB::table(self::SOURCES[$key])->where('id', (int) $data[$key])->value('complex_id');
            if ($complexId !== null) {
                return (int) $complexId;
            }
        }

        return null;
    }
};
