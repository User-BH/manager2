<?php

namespace App\Livewire;

use App\Models\Complex;
use App\Models\Message;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('پیام‌رسان')]
class Messenger extends Component
{
    public string $body = '';

    public function send(): void
    {
        $user = Auth::user();
        $complex = $this->complex();

        abort_if($complex === null, 403);

        // Respect the complex-wide switch and per-user restriction.
        if (! $complex->messenger_enabled || ! $user->can_message) {
            $this->addError('body', 'امکان ارسال پیام برای شما فعال نیست.');

            return;
        }

        $this->validate(
            ['body' => ['required', 'string', 'max:1000']],
            ['body.required' => 'متن پیام را وارد کنید.', 'body.max' => 'پیام بیش از حد طولانی است.'],
        );

        Message::create([
            'complex_id' => $complex->id,
            'user_id' => $user->id,
            'body' => $this->body,
            'author_name' => $user->name,
            'author_role' => $user->role->value,
            'unit_label' => $this->unitLabel($user),
        ]);

        $this->body = '';
    }

    public function toggleHide(int $messageId): void
    {
        abort_unless(Auth::user()->isAdmin(), 403);

        $message = Message::findOrFail($messageId);
        $message->update([
            'is_hidden' => ! $message->is_hidden,
            'hidden_by' => Auth::id(),
        ]);
    }

    public function render()
    {
        $complex = $this->complex();

        $messages = $complex
            ? Message::where('complex_id', $complex->id)->with('user')->orderBy('created_at')->limit(200)->get()
            : collect();

        return view('livewire.messenger', [
            'messages' => $messages,
            'complex' => $complex,
            'canSend' => $complex && $complex->messenger_enabled && Auth::user()->can_message,
        ]);
    }

    protected function complex(): ?Complex
    {
        $user = Auth::user();

        return $user->isSuperAdmin()
            ? (session('active_complex_id') ? Complex::find(session('active_complex_id')) : null)
            : $user->complex;
    }

    protected function unitLabel($user): string
    {
        if ($user->isAdmin()) {
            return 'مدیریت';
        }

        $unit = $user->currentUnits()->first();

        return $unit ? $unit->label() : '-';
    }
}
