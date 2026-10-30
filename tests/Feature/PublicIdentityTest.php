<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\User;
use App\Notifications\InvitationReceivedNotification;
use App\Support\PublicIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * پیامِ مدیرانِ سامانه به کاربران با نامِ «تیم ساکنا» (۲۰۲۶-۱۰-۳۰، بندِ ۳ ِ کارفرما) — نامِ شخصیِ مدیر فقط در «فعالیتِ
 * مدیران» ِ پنلِ سوپر ادمین.
 */
class PublicIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_super_admin_is_shown_as_the_sakena_team_and_everyone_else_by_name(): void
    {
        $admin = User::factory()->create(['name' => 'وحید مرادی', 'role' => UserRole::SuperAdmin]);
        $manager = User::factory()->create(['name' => 'مدیرِ آفتاب', 'role' => UserRole::ComplexAdmin]);

        $this->assertSame('تیم ساکنا', PublicIdentity::nameOf($admin));
        $this->assertSame('مدیرِ آفتاب', PublicIdentity::nameOf($manager));
        $this->assertSame('کاربر حذف‌شده', PublicIdentity::nameOf(null));
    }

    public function test_an_invitation_sent_by_a_super_admin_names_the_team_not_the_person(): void
    {
        $complex = Complex::factory()->create(['name' => 'مجتمعِ تازه']);
        $admin = User::factory()->create(['name' => 'علی بافنده', 'role' => UserRole::SuperAdmin]);
        $manager = User::factory()->create(['name' => 'مدیرِ قبلی', 'role' => UserRole::ComplexAdmin, 'complex_id' => $complex->id]);
        $invitee = User::factory()->create(['phone' => '09351234567']);

        $byAdmin = ComplexInvitation::create(['complex_id' => $complex->id, 'phone' => '09351234567', 'role' => UserRole::ComplexAdmin,
            'direction' => 'invitation', 'invited_by' => $admin->id, 'status' => 'pending']);
        $byManager = ComplexInvitation::create(['complex_id' => $complex->id, 'phone' => '09351234567', 'role' => UserRole::Owner,
            'direction' => 'invitation', 'invited_by' => $manager->id, 'status' => 'pending']);

        $fromAdmin = (new InvitationReceivedNotification($byAdmin))->toArray($invitee)['body'];
        $this->assertStringStartsWith('تیم ساکنا شما را', $fromAdmin);
        $this->assertStringNotContainsString('علی بافنده', $fromAdmin);

        $this->assertStringStartsWith('مدیرِ قبلی، مدیرِ «مجتمعِ تازه»،', (new InvitationReceivedNotification($byManager))->toArray($invitee)['body']);
    }
}
