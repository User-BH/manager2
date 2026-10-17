<?php

namespace Tests\Feature;

use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\Membership;
use App\Services\Messenger\ConversationDirectory;
use App\Services\Units\TenureService;
use App\Support\PrivateFiles;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;
use ZipArchive;

/**
 * فایل و رسانه در پیام‌رسان — مرحلهٔ ۱۰ · فاز ۱۲ (بندهای ۴۳، ۴۶، ۴۸ و ۱۲۰ ِ فایلِ معماری).
 *
 * فهرستِ بندِ ۱۲۰ یک‌به‌یک، با فایل‌های **واقعی** (سرآیندِ واقعیِ MP4، MP3، PDF، GIF و زیپِ OOXML)، نه فایلِ ساختگی که
 * نوعش را خودش اعلام کند: حجمِ بیش از سقف، نوعِ نادرست، پسوندِ نادرست، polyglot، نامِ مسیردار، دانلودِ غیرمجاز، مجتمعِ
 * دیگر و پس از پایانِ عضویت. به‌علاوه: هر نوع جای خودش، سقفِ قابلِ تنظیم، و فایلِ بزرگ که به حافظه نمی‌آید.
 */
class MessengerFilesTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    private User $owner;

    private User $neighbour;

    private Conversation $group;

    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(PrivateFiles::name());
        $this->complex = Complex::factory()->create(['messenger_enabled' => true]);
        $this->manager = $this->person($this->complex, UserRole::ComplexAdmin);
        $this->owner = $this->person($this->complex, UserRole::Owner);
        $this->neighbour = $this->person($this->complex, UserRole::Owner);
        app(TenureService::class)->open(Unit::factory()->create(['complex_id' => $this->complex->id]), $this->owner, ResidentRelation::Owner, 100, now()->subYear());
        app(TenureService::class)->open(Unit::factory()->create(['complex_id' => $this->complex->id]), $this->neighbour, ResidentRelation::Owner, 100, now()->subYear());

        app(TenantContext::class)->set(null);
        $this->group = app(ConversationDirectory::class)->channel($this->complex);
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /** بندِ ۴۳: تصویر، ویدیو، صدا و سند — هر کدام نوعِ خودش. */
    public function test_each_kind_of_file_lands_as_its_own_type(): void
    {
        $cases = [
            'photo.png' => ['image', UploadedFile::fake()->image('x.png')->get()],
            'clip.mp4' => ['video', $this->mp4Header()],
            'song.mp3' => ['audio', "ID3\x03\x00\x00\x00\x00\x00\x0A".str_repeat("\x00", 10)."\xFF\xFB\x90\x64".str_repeat("\x00", 400)],
            'rules.pdf' => ['file', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"],
            'notes.txt' => ['file', "یادداشتِ ساده\nخطِ دوم\n"],
            'budget.docx' => ['file', $this->docx()],
        ];

        foreach ($cases as $name => [$kind, $bytes]) {
            $id = $this->send($this->owner, $this->file($name, $bytes))->assertCreated()->json('message.id');
            $this->assertSame($kind, Message::withoutGlobalScopes()->find($id)->attachment_kind, $name);
        }

        // متن و ایموجی (چندکدی: رنگِ پوست، پرچم، ZWJ) همان که نوشته شد برمی‌گردد
        $text = 'سلام همسایه 👋🏽 جلسه‌ی ساختمان 🇮🇷 👨‍👩‍👧';
        $id = $this->as($this->owner)->postJson("/api/v1/messenger/conversations/{$this->group->id}/messages", ['body' => $text])
            ->assertCreated()->json('message.id');
        $this->assertSame($text, Message::withoutGlobalScopes()->find($id)->body);
    }

    /** بندِ ۴۶: سقف از سرور، از پیکربندی — و عوض‌شدنی. */
    public function test_limits_are_server_side_and_configurable(): void
    {
        config(['messenger.uploads.image_kb' => 1]);
        $big = $this->file('big.png', UploadedFile::fake()->image('x.png', 400, 400)->get().str_repeat("\x00", 4096));

        $this->send($this->owner, $big)->assertUnprocessable()->assertJsonValidationErrors('attachment');

        config(['messenger.uploads.image_kb' => 20 * 1024]);
        $this->send($this->owner, $this->file('big.png', UploadedFile::fake()->image('x.png', 400, 400)->get()))->assertCreated();

        // کلاینت همان سقف‌ها را می‌خواند، نه نسخه‌ی خودش
        $this->as($this->owner)->getJson('/api/v1/messenger/conversations')->assertOk()
            ->assertJsonPath('uploads.limitsKb.image', 20 * 1024)
            ->assertJsonPath('uploads.limitsKb.video', 500 * 1024);
    }

    /** بندِ ۱۲۰: نوعِ نادرست، پسوندِ نادرست و HTML ِ پنهان در پسوندِ بی‌خطر. */
    public function test_extension_and_content_must_agree(): void
    {
        $pdf = "%PDF-1.4\n%%EOF";
        foreach ([
            'photo.jpg' => $pdf,                                              // پسوندِ تصویر، محتوای PDF
            'run.php' => UploadedFile::fake()->image('x.png')->get(),          // محتوای تصویر، پسوندِ اجرایی
            'page.html' => '<html><body>hi</body></html>',                     // پسوندِ نپذیرفته
            'notes.txt' => '<html><body><script>alert(1)</script></body></html>', // HTML در جامه‌ی متن
        ] as $name => $bytes) {
            $this->send($this->owner, $this->file($name, $bytes))->assertUnprocessable()->assertJsonValidationErrors('attachment');
        }

        $this->assertSame(0, Message::withoutGlobalScopes()->whereNotNull('attachment_path')->count());
    }

    /**
     * بندِ ۱۲۰: polyglot. GIF ای که اسکریپت هم دارد از سنجش به‌عنوانِ تصویر می‌گذرد — دفاعش در سرو است: نوعِ پاسخ از
     * فهرستِ سرور، `nosniff`، و هرگز `text/html`.
     */
    public function test_a_polyglot_is_never_served_as_something_it_can_run_as(): void
    {
        $id = $this->send($this->owner, $this->file('cat.gif', "GIF89a\x01\x00\x01\x00\x00\x00\x00;<script>alert(1)</script>"))
            ->assertCreated()->json('message.id');

        $response = $this->as($this->neighbour)->get("/api/v1/messenger/{$id}/attachment")->assertOk();
        $this->assertSame('image/gif', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        // سند دانلود می‌شود، درون‌خطی نه — با نامِ تمیزشده
        $doc = $this->send($this->owner, $this->file('budget.docx', $this->docx()))->assertCreated()->json('message.id');
        $download = $this->as($this->neighbour)->get("/api/v1/messenger/{$doc}/attachment")->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('budget.docx', (string) $download->headers->get('Content-Disposition'));
    }

    /** بندِ ۱۲۰: نامِ مسیردار به مسیرِ ذخیره نمی‌رسد. */
    public function test_a_path_in_the_name_never_reaches_storage(): void
    {
        $id = $this->send($this->owner, $this->file('../../../etc/passwd.png', UploadedFile::fake()->image('x.png')->get()))
            ->assertCreated()->json('message.id');

        $message = Message::withoutGlobalScopes()->find($id);
        $this->assertMatchesRegularExpression('#^messages/'.$this->complex->id.'/[A-Za-z0-9]{40}\.png$#', $message->attachment_path);
        $this->assertSame('passwd.png', $message->attachment_name);
    }

    /** بندِ ۱۲۰: دانلودِ غیرمجاز، مجتمعِ دیگر، و پس از پایانِ عضویت. */
    public function test_downloads_follow_who_may_see_the_message(): void
    {
        app(TenantContext::class)->set(null);
        $private = app(ConversationDirectory::class)->userThread($this->complex, $this->owner);
        $id = $this->as($this->owner)->post("/api/v1/messenger/conversations/{$private->id}/messages", [
            'attachment' => $this->file('lease.pdf', "%PDF-1.4\n%%EOF"),
        ], ['Accept' => 'application/json'])->assertCreated()->json('message.id');

        $this->as($this->owner)->get("/api/v1/messenger/{$id}/attachment")->assertOk();
        $this->as($this->manager)->get("/api/v1/messenger/{$id}/attachment")->assertOk();
        $this->as($this->neighbour)->get("/api/v1/messenger/{$id}/attachment")->assertNotFound();

        $other = Complex::factory()->create(['messenger_enabled' => true]);
        $this->as($this->person($other, UserRole::ComplexAdmin))->get("/api/v1/messenger/{$id}/attachment")->assertNotFound();

        $groupFile = $this->send($this->owner, $this->file('plan.pdf', "%PDF-1.4\n%%EOF"))->json('message.id');
        $this->as($this->neighbour)->get("/api/v1/messenger/{$groupFile}/attachment")->assertOk();
        app(Membership::class)->end($this->neighbour, $this->manager, $this->complex->id);
        // عضوِ رفته زمینه‌ای ندارد؛ پیامِ مجتمع با دامنه‌ی مستأجر برایش «نیست»
        $this->as($this->neighbour->fresh())->get("/api/v1/messenger/{$groupFile}/attachment")->assertNotFound();
    }

    /**
     * 🔴 ۱۲/۶ (بندِ ۴۸ ِ سندِ هسته): پیوستِ پیامی که مدیریت پنهان کرده با نشانیِ مستقیم هم به ساکن نمی‌رسد. متنِ پیامِ
     * پنهان برای ساکن پاک می‌شود (`MessageModerationTest`)؛ پیوستش با `GET messenger/{id}/attachment` همان قاعده را دارد.
     * مدیر هنوز می‌بیندش تا بتواند پنهان‌کردن را برگرداند.
     */
    public function test_a_hidden_messages_attachment_is_not_served_to_residents(): void
    {
        $id = $this->send($this->owner, $this->file('plan.pdf', '%PDF-1.4
%%EOF'))->assertCreated()->json('message.id');
        $this->as($this->neighbour)->get("/api/v1/messenger/{$id}/attachment")->assertOk();

        $this->as($this->manager)->patchJson("/api/v1/messenger/{$id}/toggle-hide")->assertOk()->assertJsonPath('message.isHidden', true);

        $this->as($this->neighbour)->get("/api/v1/messenger/{$id}/attachment")->assertNotFound();
        $this->as($this->manager)->get("/api/v1/messenger/{$id}/attachment")->assertOk();

        // در فهرستِ پیام‌ها هم: ساکن نه نام می‌بیند نه نشانی؛ مدیر هر دو
        $rowFor = fn (User $user): array => collect($this->as($user)->getJson("/api/v1/messenger/conversations/{$this->group->id}/messages")
            ->assertOk()->json('messages'))->firstWhere('id', $id) ?? [];
        $this->assertArrayHasKey('attachment', $rowFor($this->neighbour), 'پیامِ پنهان در فهرستِ ساکن (با جای‌خالی) هست.');
        $this->assertNull($rowFor($this->neighbour)['attachment']);
        $this->assertSame('plan.pdf', $rowFor($this->manager)['attachment']['name'] ?? null);

        $this->as($this->manager)->patchJson("/api/v1/messenger/{$id}/toggle-hide")->assertOk()->assertJsonPath('message.isHidden', false);
        $this->as($this->neighbour)->get("/api/v1/messenger/{$id}/attachment")->assertOk();
    }

    /** ۱۲/۶ (بندِ ۳۷، «Message timestamps»): هر پیام زمانِ ارسالش را به تقویمِ شمسی و به وقتِ تهران دارد. */
    public function test_every_message_carries_its_jalali_send_time(): void
    {
        $this->travelTo(Carbon::create(2026, 10, 17, 14, 58, 0, 'Asia/Tehran'));
        $id = $this->send($this->owner, $this->file('plan.pdf', '%PDF-1.4
%%EOF'))->assertCreated()->json('message.id');

        $row = collect($this->as($this->neighbour)->getJson("/api/v1/messenger/conversations/{$this->group->id}/messages")->json('messages'))->firstWhere('id', $id);
        $this->assertSame('۱۴۰۵/۰۷/۲۵ ۱۴:۵۸', $row['sentAt'] ?? null);
    }

    /** بندِ ۴۸: فایلِ بزرگ از دیسک جریان می‌یابد، به حافظه نمی‌آید؛ و Range برای پخشِ ویدیو. */
    public function test_large_files_stream_instead_of_loading_into_memory(): void
    {
        $path = $this->tempFile('long.mp4');
        $handle = fopen($path, 'wb');
        fwrite($handle, $this->mp4Header());
        fseek($handle, 30 * 1024 * 1024 - 1);
        fwrite($handle, "\x00");
        fclose($handle);

        /*
         * اوجِ حافظه‌ی همان مسیر با فایلِ کوچک و فایلِ ۳۰ مگابایتی؛ اختلاف باید ناچیز باشد. (اوجِ مطلق معیار نیست: نخستین
         * درخواستِ فرایند بخش‌هایی از چارچوب را بار می‌کند.)
         */
        $peak = function (UploadedFile $file): int {
            memory_reset_peak_usage();
            $before = memory_get_peak_usage();
            $this->send($this->owner, $file)->assertCreated();

            return memory_get_peak_usage() - $before;
        };
        $peak($this->file('warm.mp4', $this->mp4Header()));
        $small = $peak($this->file('small.mp4', $this->mp4Header()));
        $large = $peak(new UploadedFile($path, 'long.mp4', null, null, true));
        $this->assertLessThan(5 * 1024 * 1024, $large - $small, 'آپلودِ ۳۰ مگابایتی به حافظه آمد.');
        $id = (int) Message::withoutGlobalScopes()->where('attachment_name', 'long.mp4')->value('id');

        $response = $this->as($this->neighbour)->get("/api/v1/messenger/{$id}/attachment");
        $response->assertOk();
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse, 'دانلود باید از دیسک جریان یابد.');
        $this->assertSame('video/mp4', $response->headers->get('Content-Type'));

        $partial = $this->as($this->neighbour)->get("/api/v1/messenger/{$id}/attachment", ['Range' => 'bytes=0-99']);
        $partial->assertStatus(206);
        $this->assertSame('100', $partial->headers->get('Content-Length'));
    }

    /** بندِ ۴۵: پیامِ ویدیویی از فیلدِ خودش، با سقفِ خودش؛ پیوستِ عادی هرگز «پیامِ ویدیویی» نمی‌شود. */
    public function test_a_video_message_has_its_own_field_and_limit(): void
    {
        $id = $this->as($this->owner)->post("/api/v1/messenger/conversations/{$this->group->id}/messages", [
            'video_message' => $this->file('note.mp4', $this->mp4Header()),
        ], ['Accept' => 'application/json'])->assertCreated()->json('message.id');
        $this->assertSame('video_message', Message::withoutGlobalScopes()->find($id)->attachment_kind);

        $this->as($this->owner)->post("/api/v1/messenger/conversations/{$this->group->id}/messages", [
            'video_message' => $this->file('note.pdf', "%PDF-1.4\n%%EOF"),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        config(['messenger.uploads.video_message_kb' => 1]);
        $this->as($this->owner)->post("/api/v1/messenger/conversations/{$this->group->id}/messages", [
            'video_message' => $this->file('note.mp4', $this->mp4Header().str_repeat("\x00", 4096)),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    /* ── کمک‌ها ─────────────────────────────────────────────────────────── */

    private function send(User $user, UploadedFile $file): TestResponse
    {
        return $this->as($user)->post("/api/v1/messenger/conversations/{$this->group->id}/messages", ['attachment' => $file], ['Accept' => 'application/json']);
    }

    /** فایلِ واقعی روی دیسک، تا نوع از **محتوا** خوانده شود */
    private function file(string $name, string $bytes): UploadedFile
    {
        $path = $this->tempFile(basename($name));
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function tempFile(string $name): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('mf_', true).'_'.$name;
        $this->temp[] = $path;

        return $path;
    }

    private function mp4Header(): string
    {
        return "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64);
    }

    private function docx(): string
    {
        $path = $this->tempFile('probe.docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('word/document.xml', '<w:document/>');
        $zip->close();

        return (string) file_get_contents($path);
    }

    private function person(Complex $complex, UserRole $role): User
    {
        return User::factory()->create(['complex_id' => $complex->id, 'role' => $role, 'is_active' => true]);
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();

        return $this->actingAs($user);
    }
}
