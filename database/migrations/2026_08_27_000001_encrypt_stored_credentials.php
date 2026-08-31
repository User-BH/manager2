<?php

use App\Support\SecretValue;
use App\Support\SystemSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * رمزگذاریِ اعتبارنامه‌هایی که متنِ ساده در دیتابیس بودند (R55 / رفعِ M-01).
 *
 * ─── چه چیزی و چرا ────────────────────────────────────────────────────────
 * `complexes.gateway_config` (رمز و شناسه‌ی درگاهِ بانکی) و
 * `settings.sms_config` (کلید و رمزِ پنلِ پیامک) خام ذخیره می‌شدند. این
 * آسیب‌پذیریِ از راه دور نیست، ولی **تشدیدکننده** است: هر نشتِ دیتابیس
 * مستقیماً به اعتبارنامه‌ی زنده تبدیل می‌شود.
 *
 * ─── چرا این مهاجرت بی‌خطر است ────────────────────────────────────────────
 * ⚠️ سه ویژگی، که هرکدام یک حالتِ خرابی را می‌بندند:
 *
 *   ① **تکرارپذیر**: ردیفی که از قبل رمز است رد می‌شود. اجرای دوباره —
 *     یا ادامه پس از قطعِ میانی — چیزی را دوبار رمز نمی‌کند.
 *   ② **بدونِ نیاز به قطعِ سرویس**: کستِ `EncryptedJson` هر دو شکل را
 *     می‌خواند، پس در فاصله‌ی بینِ شروع و پایانِ مهاجرت، هم ردیفِ رمزشده
 *     و هم ردیفِ ساده درست خوانده می‌شوند.
 *   ③ **بدونِ مدل**: مستقیم با `DB::table` کار می‌کند تا کستِ مدل وسط
 *     نیاید و مقدار را دوباره رمز نکند.
 *
 * ─── چرا نشانه‌ی «مخرب» ندارد ─────────────────────────────────────────────
 * هیچ ستون، جدول یا ردیفی حذف نمی‌شود؛ فقط شکلِ ذخیره عوض می‌شود و
 * `down()` دقیقاً برش می‌گرداند.
 *
 * ⚠️ نسخه‌ی اولِ همین توضیح، نامِ آن نشانه را **داخلِ متن** نوشته بود و
 * `MigrationSafetyTest` این مهاجرتِ بی‌آزار را «مخربِ برچسب‌خورده» دید.
 * همان تله‌ی آشنای کامنت، این بار از سمتِ نشانه‌گذاری.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->mapComplexes(fn (string $value) => SecretValue::isProtected($value)
            ? null
            : SecretValue::protect($value));

        $this->mapSetting(fn (string $value) => SecretValue::isProtected($value)
            ? null
            : SecretValue::protect($value));
    }

    /**
     * ⚠️ برگشت هم لازم است و هم بی‌خطر.
     *
     * اگر `APP_KEY` سالم باشد، این ردیف‌ها را به همان متنِ سادهٔ پیشین
     * برمی‌گرداند. اگر سالم نباشد، `reveal()` استثنا می‌دهد — که همان
     * رفتارِ درست است: بازگرداندن به چیزی که خوانده نمی‌شود، خرابیِ
     * خاموش می‌ساخت.
     */
    public function down(): void
    {
        $this->mapComplexes(fn (string $value) => SecretValue::isProtected($value)
            ? SecretValue::reveal($value, 'complexes.gateway_config')
            : null);

        $this->mapSetting(fn (string $value) => SecretValue::isProtected($value)
            ? SecretValue::reveal($value, 'settings.sms_config')
            : null);
    }

    /**
     * روی هر ردیفِ `gateway_config` اجرا می‌شود؛ `null` یعنی «دست نزن».
     *
     * @param  callable(string): ?string  $transform
     */
    private function mapComplexes(callable $transform): void
    {
        if (! Schema::hasTable('complexes')) {
            return;
        }

        DB::table('complexes')
            ->select('id', 'gateway_config')
            ->whereNotNull('gateway_config')
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($transform): void {
                foreach ($rows as $row) {
                    $value = (string) $row->gateway_config;

                    if ($value === '') {
                        continue;
                    }

                    $next = $transform($value);

                    if ($next === null) {
                        continue;
                    }

                    DB::table('complexes')->where('id', $row->id)->update(['gateway_config' => $next]);
                }
            });
    }

    /** @param  callable(string): ?string  $transform */
    private function mapSetting(callable $transform): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $row = DB::table('settings')
            ->whereNull('complex_id')
            ->where('key', 'sms_config')
            ->first();

        if (! $row || ! is_string($row->value) || $row->value === '') {
            return;
        }

        $next = $transform($row->value);

        if ($next === null) {
            return;
        }

        DB::table('settings')->where('id', $row->id)->update(['value' => $next]);

        /*
         * ⚠️ کش باید باطل شود، وگرنه تا اولین نوشتنِ بعدی، مقدارِ کهنه
         * (ساده) از کش خوانده می‌شود و رمزگشایی روی متنِ ساده می‌افتد.
         */
        SystemSettings::forget();
    }
};
