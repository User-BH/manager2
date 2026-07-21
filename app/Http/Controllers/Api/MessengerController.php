<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Complex;
use App\Models\Message;
use App\Models\User;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * پیام‌رسان داخلی مجتمع.
 *
 * همان منطق کامپوننت Livewire، فقط JSON برمی‌گرداند. کلاینت هر چند ثانیه
 * `since` را می‌فرستد تا فقط پیام‌های جدید را بگیرد، نه کل تاریخچه را.
 */
class MessengerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $complex = $this->messengerComplex();

        if (! $complex) {
            return response()->json([
                'messages' => [],
                'canSend' => false,
                'reason' => 'ابتدا یک مجتمع را انتخاب کنید.',
            ]);
        }

        $query = Message::where('complex_id', $complex->id)->orderBy('created_at');

        // واکشی افزایشی: فقط پیام‌های بعد از آخرین شناسه‌ای که کلاینت دارد
        if ($since = $request->integer('since')) {
            $query->where('id', '>', $since);
        } else {
            $query->limit(200);
        }

        return response()->json([
            'messages' => $query->get()->map(fn (Message $m) => $this->present($m, $user))->values(),
            'canSend' => $complex->messenger_enabled && $user->can_message,
            'reason' => $this->blockReason($complex, $user),
            'isAdmin' => $user->isAdmin(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        $complex = $this->messengerComplex();

        abort_if($complex === null, 409, 'ابتدا یک مجتمع را انتخاب کنید.');

        if (! $complex->messenger_enabled || ! $user->can_message) {
            abort(403, 'امکان ارسال پیام برای شما فعال نیست.');
        }

        $data = $request->validate(
            ['body' => ['required', 'string', 'max:1000']],
            ['body.required' => 'متن پیام را وارد کنید.', 'body.max' => 'پیام بیش از حد طولانی است.'],
        );

        $message = Message::create([
            'complex_id' => $complex->id,
            'user_id' => $user->id,
            'body' => $data['body'],
            'author_name' => $user->name,
            'author_role' => $user->role->value,
            'unit_label' => $this->unitLabel($user),
        ]);

        return response()->json(['message' => $this->present($message, $user)], 201);
    }

    /** مخفی/آشکار کردن پیام توسط مدیر. */
    public function toggleHide(Message $message): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user->isAdmin(), 403);

        $message->update([
            'is_hidden' => ! $message->is_hidden,
            'hidden_by' => $user->id,
        ]);

        return response()->json(['message' => $this->present($message->fresh(), $user)]);
    }

    private function present(Message $message, User $viewer): array
    {
        return [
            'id' => $message->id,
            'body' => $message->body,
            'authorName' => $message->author_name,
            'unitLabel' => $message->unit_label,
            'isMine' => $message->user_id === $viewer->id,
            'isHidden' => (bool) $message->is_hidden,
            'sentAt' => Jalali::dateTime($message->created_at),
        ];
    }

    private function blockReason(Complex $complex, User $user): ?string
    {
        if (! $complex->messenger_enabled) {
            return 'پیام‌رسان این مجتمع توسط مدیر بسته شده است.';
        }

        if (! $user->can_message) {
            return 'ارسال پیام برای شما محدود شده است.';
        }

        return null;
    }

    private function messengerComplex(): ?Complex
    {
        $user = Auth::user();

        return $user->isSuperAdmin()
            ? (session('active_complex_id') ? Complex::find(session('active_complex_id')) : null)
            : $user->complex;
    }

    private function unitLabel(User $user): string
    {
        if ($user->isAdmin()) {
            return 'مدیریت';
        }

        return $user->currentUnits()->first()?->label() ?? '-';
    }
}
