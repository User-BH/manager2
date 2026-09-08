<?php

namespace App\Console\Commands;

use App\Casts\EncryptedJson;
use App\Support\SecretValue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * آیا رازی هنوز متنِ ساده در دیتابیس مانده؟ (فاز ۴)
 *
 * ─── چرا این دستور پیش از حذفِ تحمل لازم بود ───────────────────────────────
 * ⚠️ تا فاز ۴، خواندنِ رازِ متنِ ساده **بی‌صدا** می‌پذیرفت. آن تحمل عمدی
 * بود و در زمانِ خودش درست: مهاجرتِ رمزنگاری نمی‌توانست لحظه‌ای باشد و
 * قطعِ میانی‌اش نباید پرداخت را می‌شکست.
 *
 * 🔴 ولی تحملِ بی‌صدا معنایش این است که هیچ‌کس نمی‌داند مهاجرت **تمام
 * شده یا نه**. سامانه سالم به نظر می‌رسد، و رازها همچنان خام‌اند.
 *
 * پس پیش از برداشتنِ تحمل، باید راهی برای **دیدن** وضعیت وجود داشته باشد.
 * این همان است: پیش از استقرار بزنید، و اگر چیزی پیدا شد مهاجرت را اجرا
 * کنید.
 *
 * ⚠️ عمداً `DB::table` است نه مدل: کستِ مدل مقدار را رمزگشایی می‌کند و
 * آن‌وقت این دستور تفاوتِ «رمزشده» و «ساده» را اصلاً نمی‌بیند.
 */
class AuditSecrets extends Command
{
    protected $signature = 'secrets:audit';

    protected $description = 'گزارش می‌دهد کدام رازِ ذخیره‌شده هنوز رمز نشده است';

    public function handle(): int
    {
        $plaintext = [];

        foreach ($this->gatewayRows() as $row) {
            if (! EncryptedJson::isWrapped($row->value) && ! SecretValue::isProtected($row->value)) {
                $plaintext[] = "complexes#{$row->id}.gateway_config";
            }
        }

        foreach ($this->settingRows() as $row) {
            if (! SecretValue::isProtected($row->value)) {
                $plaintext[] = "settings.{$row->key}";
            }
        }

        if ($plaintext === []) {
            $this->info('✅ هیچ رازِ متنِ ساده‌ای پیدا نشد.');

            return self::SUCCESS;
        }

        $this->error('🔴 این رازها هنوز متنِ ساده‌اند:');

        foreach ($plaintext as $where) {
            $this->line('   '.$where);
        }

        $this->newLine();
        $this->warn('⚠️ `php artisan migrate --force` را اجرا کنید؛ مهاجرتِ');
        $this->warn('   `encrypt_stored_credentials` تکرارپذیر است و همین‌ها را رمز می‌کند.');
        $this->warn('   اگر پس از آن باز هم پیدا شدند، رویهٔ docs/SECRETS.md را دنبال کنید.');

        return self::FAILURE;
    }

    /** @return iterable<object{id: int, value: string}> */
    private function gatewayRows(): iterable
    {
        foreach (DB::table('complexes')->select('id', 'gateway_config')->cursor() as $row) {
            $value = (string) ($row->gateway_config ?? '');

            if ($value === '') {
                continue;
            }

            yield (object) ['id' => $row->id, 'value' => $value];
        }
    }

    /**
     * ⚠️ فقط کلیدهای حساس. `settings` مقادیرِ عادی هم دارد (مثلِ نامِ سایت)
     * که رمزشده نیستند و نباید هم باشند — گزارششان فقط نویز می‌سازد.
     *
     * @return iterable<object{key: string, value: string}>
     */
    private function settingRows(): iterable
    {
        foreach (DB::table('settings')->select('key', 'value')->cursor() as $row) {
            if (! str_ends_with((string) $row->key, '_config')) {
                continue;
            }

            $value = (string) ($row->value ?? '');

            if ($value === '') {
                continue;
            }

            yield (object) ['key' => $row->key, 'value' => $value];
        }
    }
}
