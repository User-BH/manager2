<?php

namespace App\Listeners;

use App\Http\Middleware\EnforceSessionLifetime;
use App\Models\LoginEvent;
use App\Models\User;
use App\Services\Auth\LoginRecorder;
use Illuminate\Auth\Events\Login;

/**
 * هر ورود — از هر راهی — ساعتِ نشست را از نو می‌گذارد و در تاریخچه ثبت
 * می‌شود (مرحلهٔ ۵ · فاز ۱۰).
 *
 * ⚠️ روی رویدادِ `Login` نشسته، نه در کنترلر: سه راهِ ورود داریم (رمز،
 * بازیابیِ رمز، دستگاهِ مورداعتماد) و راهِ چهارمی که فردا اضافه شود هم
 * خودبه‌خود سقفِ عمر و تاریخچه می‌گیرد. نوعِ ورود را فراخواننده در
 * `auth.login_event`ِ درخواست می‌گذارد.
 */
class StartAuthenticatedSession
{
    public const EVENT_ATTRIBUTE = 'auth.login_event';

    public function __construct(private readonly LoginRecorder $recorder) {}

    public function handle(Login $event): void
    {
        $request = request();

        if ($request->hasSession()) {
            $request->session()->put(EnforceSessionLifetime::STARTED_AT, now()->timestamp);
        }

        if ($event->user instanceof User) {
            $this->recorder->success(
                $event->user,
                $request,
                (string) $request->attributes->get(self::EVENT_ATTRIBUTE, LoginEvent::SUCCESS),
            );
        }
    }
}
