<?php

namespace Tests\Feature;

use App\Enums\MessageAudience;
use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\ComplexMembership;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\MembershipLedger;
use App\Services\Units\TenureService;
use App\Support\PrivateFiles;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * تصویرِ پروفایل و دادهٔ canonical ِ حساب — مرحلهٔ ۱۰ · فاز ۷ (بندهای ۲، ۴ و ۵ ِ فایلِ معماری).
 *
 * «تصویرِ پروفایل باید canonical باشد؛ اگر کاربر تصویرش را عوض کند، پیام‌رسان و فهرستِ اعضا همان تصویرِ تازه
 * را استفاده کنند.» هر آزمون یکی از این‌ها را می‌سنجد: یک تصویر روی حساب، بی metadata ِ فایلِ اصلی، دیده شدن
 * فقط برای کسانی که با او در یک مجتمع‌اند، و پیام‌رسانی که تصویرِ فعلی را نشان می‌دهد نه نسخه‌ی کپی‌شده.
 */
class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(PrivateFiles::name());

        $this->complex = Complex::factory()->create(['messenger_enabled' => true]);
        $this->manager = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true]);
        $this->owner = User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => UserRole::Owner, 'is_active' => true, 'can_message' => true,
            'first_name' => 'مریم', 'last_name' => 'تصویری',
        ]);
        app(TenureService::class)->open(
            Unit::factory()->create(['complex_id' => $this->complex->id]), $this->owner, ResidentRelation::Owner, 100, now()->subYear(),
        );
    }

    public function test_the_photo_is_re_encoded_square_and_without_the_originals_metadata(): void
    {
        $this->upload($this->owner, $this->jpegWithExif('SECRET-GPS-35.6892,51.3890', 900, 600))->assertOk();

        $path = $this->owner->fresh()->avatar_path;
        $this->assertNotNull($path);
        $bytes = Storage::disk(PrivateFiles::name())->get($path);

        // هیچ چیزی از metadata ِ فایلِ کاربر به فایلِ نگه‌داشته نرسید
        $this->assertStringNotContainsString('SECRET-GPS', $bytes);
        $this->assertStringNotContainsString('Exif', $bytes);
        $this->assertSame([256, 256, IMAGETYPE_JPEG], array_slice(getimagesizefromstring($bytes), 0, 3));
        $this->assertDatabaseHas('audit_logs', ['action' => 'profile.avatar_changed', 'subject_id' => $this->owner->id]);
    }

    public function test_what_is_not_a_real_image_is_refused(): void
    {
        // متنی با پسوندِ تصویر
        $fake = UploadedFile::fake()->createWithContent('photo.png', '<?php echo 1;');
        $this->upload($this->owner, $fake)->assertUnprocessable();

        /*
         * بمبِ تصویری: PNG ِ **واقعی و بازشدنی** با ۷۰۰۰×۶۰۰۰ پیکسل (۴۲ میلیون) که فشرده چند کیلوبایت است. بی سقفِ
         * پیکسل، GD بازش می‌کرد و حافظه را می‌خورد — سرآیندِ ساختگی (نسخه‌ی اولِ این آزمون) این را نمی‌سنجید چون
         * به‌هرحال باز نمی‌شد (خرابکاریِ ۱۰/۷).
         */
        $this->upload($this->owner, UploadedFile::fake()->createWithContent('bomb.png', $this->hugeBlankPng(7000, 6000)))
            ->assertUnprocessable();

        $this->assertNull($this->owner->fresh()->avatar_path);
        $this->assertSame([], Storage::disk(PrivateFiles::name())->allFiles());
    }

    public function test_replacing_and_removing_leave_no_orphan_file(): void
    {
        $this->upload($this->owner, $this->jpegWithExif('a', 300, 300))->assertOk();
        $first = $this->owner->fresh()->avatar_path;
        $this->upload($this->owner, $this->jpegWithExif('b', 300, 300))->assertOk();
        $second = $this->owner->fresh()->avatar_path;

        $this->assertNotSame($first, $second);
        $this->assertSame([$second], Storage::disk(PrivateFiles::name())->allFiles());

        $this->as($this->owner)->deleteJson('/api/v1/profile/avatar')->assertOk()->assertJsonPath('profile.avatarUrl', null);
        $this->assertSame([], Storage::disk(PrivateFiles::name())->allFiles());
        $this->assertNull($this->as($this->owner)->getJson('/api/v1/me')->json('user.avatarUrl'));
    }

    public function test_only_people_who_share_a_complex_see_the_photo(): void
    {
        $url = $this->upload($this->owner, $this->jpegWithExif('x', 300, 300))->json('profile.avatarUrl');
        $this->assertStringContainsString("/users/{$this->owner->id}/avatar?v=", $url);

        // خودش و مدیرِ همان مجتمع
        $this->as($this->owner)->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->as($this->manager)->get($url)->assertOk();

        // عضوِ مجتمعِ دیگر — ۴۰۴، همان پاسخِ «تصویری نیست»
        $stranger = User::factory()->create(['complex_id' => Complex::factory()->create()->id, 'role' => UserRole::Owner, 'is_active' => true]);
        $this->as($stranger)->get($url)->assertNotFound();
        $this->as($stranger)->get('/api/v1/users/'.$this->manager->id.'/avatar')->assertNotFound();
        $this->as($stranger)->get('/api/v1/users/999999/avatar')->assertNotFound();

        // ساکنِ رفته: چهره‌اش برای اعضای فعالِ همان مجتمع در سابقه می‌ماند، ولی خودش دیگر عکسِ مدیر را نمی‌بیند
        app(MembershipLedger::class)->end($this->owner, ComplexMembership::END_REMOVED, $this->manager, $this->complex->id);
        $this->as($this->manager)->get($url)->assertOk();
        $this->upload($this->manager, $this->jpegWithExif('m', 300, 300))->assertOk();
        $managerUrl = $this->as($this->manager)->getJson('/api/v1/me')->json('user.avatarUrl');
        $this->as($this->owner->fresh())->get($managerUrl)->assertNotFound();
    }

    public function test_the_messenger_shows_the_authors_current_photo_not_a_copy(): void
    {
        $this->as($this->owner)->postJson('/api/v1/messenger', [
            'body' => 'سلام همسایه‌ها', 'audience' => MessageAudience::Management->value,
        ])->assertCreated();

        $before = $this->authorAvatar();
        $this->assertNull($before);

        $this->upload($this->owner, $this->jpegWithExif('one', 300, 300))->assertOk();
        $first = $this->authorAvatar();
        $this->upload($this->owner, $this->jpegWithExif('two', 300, 300))->assertOk();
        $second = $this->authorAvatar();

        $this->assertNotNull($first);
        $this->assertNotSame($first, $second, 'پیامِ قدیمی تصویرِ تازه را نشان نداد.');
        $this->assertSame($second, $this->as($this->owner)->getJson('/api/v1/me')->json('user.avatarUrl'));
    }

    public function test_a_manager_cannot_rewrite_the_accounts_email_or_national_id(): void
    {
        $this->owner->forceFill(['email' => 'owner@example.com', 'national_id' => '0012345678'])->save();
        $unit = $this->owner->units()->first();

        $this->as($this->manager)->putJson("/api/v1/residents/{$this->owner->id}", [
            'role' => 'owner', 'unit_id' => $unit->id, 'email' => 'manager-set@example.com', 'national_id' => 'abc',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'national_id']);

        $this->assertSame(['owner@example.com', '0012345678'], [$this->owner->fresh()->email, $this->owner->fresh()->national_id]);

        // بی آن دو، ویرایشِ نقش و واحد مثلِ قبل
        $this->as($this->manager)->putJson("/api/v1/residents/{$this->owner->id}", ['role' => 'owner', 'unit_id' => $unit->id])
            ->assertOk()->assertJsonPath('resident.email', 'owner@example.com');
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    private function authorAvatar(): ?string
    {
        $messages = $this->as($this->manager)->getJson('/api/v1/messenger')->assertOk()->json('messages');
        $mine = collect($messages)->firstWhere('authorName', 'مریم تصویری');
        // پیام باید پیدا شود؛ وگرنه «null» یعنی هیچ — نه «بی تصویر»
        $this->assertNotNull($mine, 'پیامِ نویسنده در پیام‌رسانِ مدیر نیامد.');
        $this->assertArrayHasKey('authorAvatarUrl', $mine);

        return $mine['authorAvatarUrl'];
    }

    private function upload(User $user, UploadedFile $file): TestResponse
    {
        return $this->as($user)->post('/api/v1/profile/avatar', ['avatar' => $file], ['Accept' => 'application/json']);
    }

    /** PNG ِ معتبرِ سیاه‌وسفیدِ یک‌بیتی — بزرگ در پیکسل، کوچک در بایت */
    private function hugeBlankPng(int $width, int $height): string
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $row = "\0".str_repeat("\0", intdiv($width + 7, 8));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NN', $width, $height)."\x01\x00\x00\x00\x00")
            .$chunk('IDAT', (string) gzcompress(str_repeat($row, $height), 9))
            .$chunk('IEND', '');
    }

    /** JPEG ِ واقعی با بخشِ APP1/Exif که نشانه‌ی `$marker` را دارد */
    private function jpegWithExif(string $marker, int $width, int $height): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 90));
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        $exif = "Exif\0\0".'MM'."\0*".pack('N', 8).$marker;
        $segment = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

        return UploadedFile::fake()->createWithContent('phone.jpg', substr($jpeg, 0, 2).$segment.substr($jpeg, 2));
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();

        return $this->actingAs($user);
    }
}
