<?php

namespace Tests\Feature;

use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\Conversation;
use App\Models\Unit;
use App\Models\User;
use App\Services\Messenger\ConversationDirectory;
use App\Services\Units\TenureService;
use App\Support\PrivateFiles;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * پیامِ خطای سرور هم فارسی است — «چند نکته»، بندِ ۴ (فارسی‌سازیِ کاملِ پیام‌رسان).
 *
 * متنِ رابط فقط در React نیست: خطای اعتبارسنجیِ سرور مستقیم زیرِ فیلد می‌نشیند. پانزده قاعده‌ی به‌کاررفته پیام نداشتند و
 * چون زبانِ پشتیبان هم fa است، کاربر کلیدِ خام («validation.mimetypes») می‌دید؛ و فیلدِ بی‌نامِ فارسی نامِ فنی‌اش را
 * («video_message») در جمله می‌گذاشت.
 */
class PersianValidationTest extends TestCase
{
    use RefreshDatabase;

    /** قواعدِ لاراول که پیامِ کاربرپسند می‌خواهند (اصلاح‌گرها مثلِ nullable و bail پیام ندارند) */
    private const RULES = [
        'accepted', 'after', 'after_or_equal', 'alpha', 'alpha_dash', 'alpha_num', 'array', 'before', 'before_or_equal',
        'between', 'boolean', 'confirmed', 'current_password', 'date', 'date_format', 'decimal', 'different', 'digits',
        'digits_between', 'dimensions', 'distinct', 'email', 'ends_with', 'exists', 'file', 'filled', 'gt', 'gte', 'image',
        'in', 'in_array', 'integer', 'ip', 'json', 'lt', 'lte', 'max', 'mimes', 'mimetypes', 'min', 'not_in', 'not_regex',
        'numeric', 'present', 'prohibited', 'prohibits', 'regex', 'required', 'required_if', 'required_unless',
        'required_with', 'required_with_all', 'required_without', 'required_without_all', 'same', 'size', 'starts_with',
        'string', 'unique', 'url', 'uuid',
    ];

    /** FormRequestهای پیام‌رسان و نظرسنجی */
    private const MESSENGER_REQUESTS = [
        'SendConversationMessageRequest', 'StoreMessageRequest', 'ForwardMessageRequest', 'PinMessageRequest',
        'StoreConversationRequest', 'UpdateModeratorRequest', 'ReactMessageRequest', 'ReportMessageRequest',
        'ResolveReportRequest', 'UpdateConversationPostingRequest', 'ReadConversationRequest', 'UpdateMessageRequest',
    ];

    public function test_every_rule_used_by_a_form_request_has_a_persian_message(): void
    {
        $used = [];
        foreach (Finder::create()->files()->in(app_path('Http/Requests'))->name('*.php') as $file) {
            preg_match_all("/'([a-z_]+)(?::[^']*)?'/", $file->getContents(), $m);
            $used = [...$used, ...array_intersect($m[1], self::RULES)];
        }
        $used = array_values(array_unique($used));

        $this->assertGreaterThan(20, count($used), 'قاعده‌ای خوانده نشد؛ محافظ پوچ می‌شود.');
        $missing = array_values(array_filter($used, fn (string $rule) => ! Lang::has("validation.{$rule}", 'fa', false)));
        $this->assertSame([], $missing, 'این قاعده‌ها پیامِ فارسی ندارند.');
    }

    public function test_every_messenger_field_has_a_persian_name(): void
    {
        // همان آرایه‌ای که اعتبارسنج می‌خواند — کلیدِ ستاره‌دار (`poll_options.*`) با نقطه شکسته نمی‌شود
        $names = (array) Lang::get('validation.attributes', [], 'fa');
        $missing = [];
        foreach (self::MESSENGER_REQUESTS as $name) {
            $source = (string) file_get_contents(app_path("Http/Requests/{$name}.php"));
            $rules = substr($source, (int) strpos($source, 'function rules'));
            $rules = substr($rules, 0, (int) strpos($rules, "\n    }"));
            preg_match_all("/^\\s+'([a-z_.*]+)'\\s*=>/m", $rules, $m);

            foreach ($m[1] as $field) {
                if (! isset($names[$field])) {
                    $missing[] = "{$name}: {$field}";
                }
            }
        }

        $this->assertSame([], $missing, 'این فیلدها نامِ فارسی ندارند.');
    }

    /** 🔴 رفتار: خطاهای واقعیِ پیام‌رسان نه کلیدِ خام دارند، نه نامِ فنیِ فیلد، نه هیچ حرفِ لاتینی. */
    public function test_real_messenger_errors_are_persian_end_to_end(): void
    {
        Storage::fake(PrivateFiles::name());
        $complex = Complex::factory()->create(['messenger_enabled' => true]);
        $owner = User::factory()->create(['complex_id' => $complex->id, 'role' => UserRole::Owner, 'is_active' => true]);
        app(TenureService::class)->open(Unit::factory()->create(['complex_id' => $complex->id]), $owner, ResidentRelation::Owner, 100, now()->subYear());
        app(TenantContext::class)->set(null);
        $group = app(ConversationDirectory::class)->channel($complex);
        $send = fn (array $data) => $this->as($owner)->post("/api/v1/messenger/conversations/{$group->id}/messages", $data, ['Accept' => 'application/json']);

        $responses = [
            'پیامِ خالی' => $send([]),
            // فایلِ واقعی: `UploadedFile::fake()` نوع را از نام می‌گوید، نه از محتوا
            'پیامِ ویدیوییِ متنی' => $send(['video_message' => $this->realFile('v.webm', 'یک متنِ ساده، نه ویدیو
')]),
            'صدا و پیوست با هم' => $send([
                'voice' => UploadedFile::fake()->create('v.webm', 5, 'audio/webm'),
                'attachment' => UploadedFile::fake()->image('p.jpg'),
            ]),
            // ساکن: پرسشِ نظرسنجی جای متن را نمی‌گیرد (پیشتر پیامِ خالی ثبت می‌شد)
            'نظرسنجیِ ساکن بی متن' => $send(['poll_question' => 'کدام؟', 'poll_options' => ['الف', 'ب']]),
            'شناسه‌ی ارسالِ نامعتبر' => $send(['body' => 'سلام', 'client_id' => 'شناسه با فاصله']),
            'هدایتِ بی پیام' => $this->as($owner)->postJson("/api/v1/messenger/conversations/{$group->id}/forward", []),
            'واکنشِ نامعتبر' => $this->reactWith($owner, $group),
        ];

        foreach ($responses as $case => $response) {
            $this->assertSame(422, $response->status(), "{$case}: ".$response->getContent());
            $texts = [$response->json('message'), ...collect($response->json('errors') ?? [])->flatten()->all()];
            $this->assertNotEmpty(array_filter($texts), $case);

            foreach ($texts as $text) {
                $this->assertStringNotContainsString('validation.', (string) $text, "{$case}: کلیدِ خام");
                $this->assertDoesNotMatchRegularExpression('/[A-Za-z]/', (string) $text, "{$case}: حرفِ لاتین در «{$text}»");
            }
        }
    }

    private function reactWith(User $user, Conversation $group): TestResponse
    {
        $id = (int) $this->as($user)->postJson("/api/v1/messenger/conversations/{$group->id}/messages", ['body' => 'سلام'])->json('message.id');

        return $this->as($user)->postJson("/api/v1/messenger/conversations/{$group->id}/messages/{$id}/reactions", ['emoji' => str_repeat('x', 40)]);
    }

    private function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pv_');
        file_put_contents($path, $content);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return new UploadedFile($path, $name, null, null, true);
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();

        return $this->actingAs($user);
    }
}
