<?php

namespace App\Services\Messenger;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * پیام‌های بی‌گفت‌وگو → گفت‌وگو (مرحلهٔ ۶ · فاز ۱۲، گامِ Migrate).
 *
 * ─── نگاشت ───────────────────────────────────────────────────────────────────
 * پیام‌رسانِ قبلی «مخاطب‌محور» بود؛ هر مخاطب دقیقاً یک نوع گفت‌وگوست:
 *
 *   all                      → کانالِ مجتمع (`channel`)
 *   management + unit_id     → رشته‌ی خصوصیِ آن واحد با مدیریت (`unit`)
 *   management بی‌واحد       → رشته‌ی خصوصیِ همان ساکن با مدیریت (`user`)
 *   units + مجموعه‌ی واحدها  → گروهِ همان مجموعه (`group`)، فقط-مدیر
 *
 * ⚠️ **هیچ پیامی حذف یا عوض نمی‌شود**؛ فقط `conversation_id` پر می‌شود.
 * ستون‌های قدیم (`audience`، `unit_id`، `message_units`) دست‌نخورده می‌مانند —
 * گامِ Contract استقرارِ جداست (`MIGRATIONS.md`).
 *
 * ⚠️ فقط Query Builder، نه مدل: مهاجرت باید با کدِ امروزِ مدل‌ها گره نخورد.
 *
 * تکرارپذیر است: فقط پیام‌های `conversation_id = null` را برمی‌دارد. همان
 * کار را `messenger:backfill-conversations` پس از استقرار دوباره می‌کند تا
 * پیامی که کدِ قدیمی در فاصله‌ی استقرار نوشته هم گفت‌وگو بگیرد.
 */
class ConversationBackfill
{
    /** @return int شمارِ پیام‌هایی که گفت‌وگو گرفتند */
    public function run(): int
    {
        $assigned = 0;

        DB::table('messages')
            ->whereNull('conversation_id')
            ->orderBy('id')
            ->chunkById(500, function ($messages) use (&$assigned): void {
                DB::transaction(function () use ($messages, &$assigned): void {
                    foreach ($messages as $message) {
                        $conversationId = $this->conversationFor($message);
                        DB::table('messages')->where('id', $message->id)->update(['conversation_id' => $conversationId]);
                        $this->touch($conversationId, $message->id, $message->created_at);
                        $assigned++;
                    }
                });
            });

        return $assigned;
    }

    private function conversationFor(object $message): int
    {
        return match ($message->audience) {
            'all' => $this->ensure($message->complex_id, 'channel', ['type' => 'channel', 'members_can_post' => false]),
            'units' => $this->groupFor($message),
            default => $message->unit_id !== null
                ? $this->ensure($message->complex_id, 'unit:'.$message->unit_id, ['type' => 'unit', 'unit_id' => $message->unit_id, 'members_can_post' => true])
                : $this->ensure($message->complex_id, 'user:'.($message->user_id ?? 'deleted'), ['type' => 'user', 'user_id' => $message->user_id, 'members_can_post' => true]),
        };
    }

    /**
     * پیامِ «به واحدهای انتخابی»: همان مجموعه‌ی واحدها یک گروه است. کلید از
     * مجموعه‌ی مرتب ساخته می‌شود تا دو پیام به همان واحدها در یک گروه بنشینند.
     */
    private function groupFor(object $message): int
    {
        $unitIds = DB::table('message_units')->where('message_id', $message->id)
            ->orderBy('unit_id')->pluck('unit_id')->map(fn ($id) => (int) $id)->all();

        $key = 'units:'.md5(implode(',', $unitIds));

        $existing = DB::table('conversations')->where('complex_id', $message->complex_id)->where('key', $key)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        $labels = DB::table('units')->whereIn('id', $unitIds)->orderBy('unit_number')->pluck('unit_number')
            ->map(fn ($n) => 'واحد '.$n)->implode('، ');

        $id = $this->ensure($message->complex_id, $key, [
            'type' => 'group',
            'title' => Str::limit($labels !== '' ? $labels : 'واحدهای انتخابی', 120, '…'),
            'members_can_post' => false,
            'created_by' => $message->user_id,
        ]);

        foreach ($unitIds as $unitId) {
            DB::table('conversation_units')->insertOrIgnore(['conversation_id' => $id, 'unit_id' => $unitId]);
        }

        return $id;
    }

    /** @param  array<string, mixed>  $attributes */
    private function ensure(int $complexId, string $key, array $attributes): int
    {
        $existing = DB::table('conversations')->where('complex_id', $complexId)->where('key', $key)->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('conversations')->insertGetId([
            'complex_id' => $complexId,
            'key' => $key,
            'created_at' => now(),
            'updated_at' => now(),
            ...$attributes,
        ]);
    }

    private function touch(int $conversationId, int $messageId, ?string $createdAt): void
    {
        DB::table('conversations')
            ->where('id', $conversationId)
            ->where('last_message_id', '<', $messageId)
            ->update(['last_message_id' => $messageId, 'last_message_at' => $createdAt]);
    }
}
