<?php

namespace App\Services\Assistant\Phrasing;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * بازنویسی با موتورِ مدلِ **محلی** (API ِ هم‌شکلِ Ollama: `POST /api/generate`) — فقط وقتی پس از سیاهه‌ی سخت‌افزار
 * نصب و در `.env` روشن شود (`ASSISTANT_MODEL=local`). هیچ مدلی همراهِ مخزن نیست و چیزی نصب نمی‌شود.
 *
 * 🔴 بی وابستگیِ بیرونی (بندِ ۴.۱۲): نشانی فقط loopback یا شبکه‌ی خصوصی، با IP — نامِ میزبانی که DNS به بیرون
 * بفرستد پذیرفته نمی‌شود. قطعیِ موتور، timeout یا پاسخِ خراب پاسخِ قطعی را از کار نمی‌اندازد: null برمی‌گردد و
 * همان پاسخِ ساخته‌شده از داده می‌رود (`Assistant`).
 */
final class LocalModelPhraser implements Phraser
{
    public function __construct(
        private readonly string $url,
        private readonly string $model,
        private readonly int $timeout,
    ) {
        if (! self::isLocal($url)) {
            throw new InvalidArgumentException('نشانیِ مدلِ دستیار باید محلی باشد (loopback یا شبکه‌ی خصوصی، با IP).');
        }
    }

    public function rephrase(string $answer): ?string
    {
        try {
            $response = Http::timeout($this->timeout)->connectTimeout(min(3, $this->timeout))
                ->acceptJson()
                ->post(rtrim($this->url, '/').'/api/generate', [
                    'model' => $this->model,
                    'stream' => false,
                    'prompt' => "متنِ زیر پاسخِ یک سامانه‌ی مدیریتِ مجتمع است. آن را به فارسیِ روان و کوتاه بازنویسی کن.\n"
                        ."هیچ عدد، تاریخ، نام یا اطلاعاتِ تازه‌ای اضافه نکن و هیچ عددی را تغییر نده.\n\n".$answer,
                ]);

            $text = $response->successful() ? $response->json('response') : null;

            return is_string($text) && trim($text) !== '' ? trim($text) : null;
        } catch (Throwable $e) {
            // موتورِ محلی خاموش یا کند است — پاسخِ قطعی بی آن می‌رود
            Log::warning('assistant.model_unavailable', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function name(): string
    {
        return 'local:'.$this->model;
    }

    public static function isLocal(string $url): bool
    {
        $parts = parse_url($url);
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || $host === '') {
            return false;
        }
        if ($host === 'localhost') {
            return true;
        }
        // link-local (۱۶۹.۲۵۴، متادیتای ابر) جای موتورِ مدل نیست
        if (filter_var($host, FILTER_VALIDATE_IP) === false || str_starts_with($host, '169.254.') || str_starts_with(strtolower($host), 'fe80:')) {
            return false;
        }

        // IP ِ عمومی از این صافی می‌گذرد؛ خصوصی و loopback نه
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
