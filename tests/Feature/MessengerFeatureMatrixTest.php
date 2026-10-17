<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ماتریسِ رگرسیونِ پیام‌رسان — مرحلهٔ ۱۲ · فاز ۶ (بندِ ۳۷ ِ سندِ هسته: «قابلیت‌های موجود حفظ شوند»).
 *
 * ۱۲/۰ یافت که همه‌ی قابلیت‌های پیام‌رسان ساخته شده‌اند ولی جایی نیست که بگوید هر کدام را **کدام آزمون** نگه می‌دارد.
 * این‌جا هر قابلیتِ فهرستِ سند به آزمون‌های واقعی‌اش نگاشته می‌شود — سرور (PHPUnit)، رابط (Vitest) و مرورگر
 * (Playwright) — و خودِ آزمون فقط می‌سنجد آن آزمون‌ها **هنوز وجود دارند**. حذف یا تغییرِ نامِ آزمونی که قابلیتی را
 * نگه می‌دارد این ماتریس را قرمز می‌کند: قابلیت بی‌صدا بی‌محافظ نمی‌شود («نباید بدون بررسی حذف یا ساده شود»).
 *
 * شکل: `php:کلاس::متد`، `js:مسیر::عنوانِ it`، `e2e:مسیر::عنوانِ test`.
 */
class MessengerFeatureMatrixTest extends TestCase
{
    /** @var array<string, list<string>> قابلیتِ سند ← آزمون‌هایش */
    public const MATRIX = [
        'Community Group' => [
            'php:MessengerConversationTest::test_residents_post_to_the_community_group_unless_management_closes_it',
            'php:MessengerStructureTest::test_the_group_name_follows_the_community_name',
            'php:MessengerHistoryPolicyTest::test_the_new_resident_sees_the_whole_public_group_history',
        ],
        'Manager ↔ Member private chat' => [
            'php:MessengerStructureTest::test_the_manager_opens_a_private_chat_with_one_member_only',
            'php:MessengerConversationTest::test_a_resident_writes_to_management_and_sees_it_read',
            'e2e:tests/e2e/messenger-journey.spec.ts::ساکن می‌نویسد، مدیر پاسخ می‌دهد، ساکن «خوانده شد» می‌بیند',
        ],
        'Owner ↔ Tenant private chat' => [
            'php:MessengerStructureTest::test_owner_and_tenant_each_get_their_own_manager_chat_and_one_together',
            'php:MessengerStructureTest::test_the_manager_never_reaches_a_pair_or_someone_elses_saved_messages',
        ],
        'Saved Messages' => [
            'php:MessengerStructureTest::test_saved_messages_stay_writable_for_a_muted_member',
        ],
        'Read Receipts' => [
            'php:MessengerFeaturesTest::test_the_read_count_is_shown_to_the_manager_but_not_to_residents',
            'php:MessengerConversationTest::test_read_receipts_never_leak_across_conversations',
            'js:tests/js/messengerThread.test.tsx::پیام‌های من تا readUpTo خوانده‌شده‌اند، نه بیشتر و نه پیامِ دیگران',
        ],
        'Message timestamps' => [
            'php:MessengerFilesTest::test_every_message_carries_its_jalali_send_time',
            'js:tests/js/messengerEnrichment.test.tsx::زمانِ ارسال همان رشته‌ی شمسیِ سرور است',
        ],
        'Emoji' => [
            'js:tests/js/PollCard.test.tsx::با انتخابِ اموجی، آن را برمی‌گرداند و پنل را می‌بندد',
        ],
        'Emoji reactions' => [
            'php:MessengerEnrichmentTest::test_reactions_toggle_and_replace_and_only_counts_are_shown',
            'php:MessengerEnrichmentTest::test_reactions_follow_visibility_and_moderation',
        ],
        'Reply' => [
            'php:MessengerEnrichmentTest::test_a_reply_quotes_its_target_from_the_same_conversation',
            'php:MessengerEnrichmentTest::test_a_reply_cannot_reach_outside_what_the_writer_can_see',
            'e2e:tests/e2e/messenger-journey.spec.ts::پاسخ، واکنش، ویرایش و حذف به طرفِ مقابل می‌رسند',
        ],
        'Forward' => [
            'php:GroupModerationTest::test_forwarding_copies_into_a_conversation_the_sender_can_write_in',
            'php:GroupModerationTest::test_forwarding_checks_the_source_and_the_target_separately',
        ],
        'Copy' => [
            'js:tests/js/messengerModeration.test.tsx::کپی، هدایت و دانلود — و هدایت فقط به گفت‌وگوهای نوشتنی، با client_id',
        ],
        'Edit' => [
            'php:MessengerEnrichmentTest::test_the_author_edits_within_the_window_and_the_mark_stays',
            'php:MessengerEnrichmentTest::test_nobody_edits_what_is_not_theirs_to_edit',
        ],
        'Delete' => [
            'php:MessengerEnrichmentTest::test_deleting_removes_the_content_not_just_the_view',
            'php:MessengerEnrichmentTest::test_only_the_author_deletes_and_never_a_poll_or_moderated_message',
        ],
        'File messages' => [
            'php:MessengerFilesTest::test_each_kind_of_file_lands_as_its_own_type',
            'php:MessengerFilesTest::test_extension_and_content_must_agree',
            'php:MessengerFilesTest::test_limits_are_server_side_and_configurable',
        ],
        'Image' => [
            'php:MessengerFeaturesTest::test_a_manager_can_attach_an_image_to_a_message',
        ],
        'Video' => [
            'php:MessengerFilesTest::test_a_video_message_has_its_own_field_and_limit',
            'js:tests/js/messengerMedia.test.tsx::پیامِ ویدیویی و ویدیو پخش‌کننده دارند',
        ],
        'Audio' => [
            'js:tests/js/messengerEnrichment.test.tsx::پیامِ صوتی با پخش‌کننده، نه لینکِ دانلود',
        ],
        'Voice' => [
            'php:MessengerEnrichmentTest::test_a_voice_message_is_stored_and_served_as_audio',
            'php:MessengerEnrichmentTest::test_a_voice_message_accepts_only_audio_of_bounded_size',
            'js:tests/js/messengerMedia.test.tsx::رها کردن می‌فرستد',
        ],
        'Poll' => [
            'php:MessengerFeaturesTest::test_a_manager_can_publish_a_poll',
            'php:PollTest::test_a_unit_bound_poll_takes_only_one_vote_per_unit',
            'php:PollTest::test_the_winner_is_announced_only_after_the_poll_closes',
        ],
        'Pin' => [
            'php:GroupModerationTest::test_pins_are_many_and_follow_the_matrix',
            'php:GroupModerationTest::test_both_sides_of_a_private_chat_pin',
        ],
        'Moderation' => [
            'php:MessageModerationTest::test_a_hidden_messages_text_never_reaches_a_resident',
            'php:MessengerFilesTest::test_a_hidden_messages_attachment_is_not_served_to_residents',
            'php:MessengerEnrichmentTest::test_a_resident_reports_and_management_resolves_without_seeing_who',
        ],
        'Message permissions' => [
            'php:MessengerConversationTest::test_group_membership_and_posting_rights_are_enforced',
            'php:MessengerConversationTest::test_a_muted_resident_cannot_send',
            'php:MessengerConversationTest::test_a_closed_messenger_refuses_even_management',
        ],
        'Manager moderation' => [
            'php:GroupModerationTest::test_in_the_group_only_the_manager_and_the_moderator_delete_others',
            'php:GroupModerationTest::test_moderation_ends_with_the_membership',
        ],
        // ── بندهای ۳۸، ۳۹ و ۴۸ ──
        'Membership-based access (38)' => [
            'php:MembershipMessagingTest::test_a_suspended_member_neither_reads_nor_writes_that_communitys_messenger',
            'php:MessengerHistoryPolicyTest::test_a_former_member_no_longer_reads_the_public_group',
            'php:MessengerConversationTest::test_a_former_tenant_loses_the_old_units_thread',
            'php:MembershipMessagingTest::test_muting_in_one_community_leaves_the_account_and_the_other_community_untouched',
        ],
        'Cross-community isolation (39)' => [
            'php:BolaSweepTest::test_no_attacker_reaches_another_tenants_or_accounts_resources',
            'php:MessengerConversationTest::test_a_resident_cannot_reach_another_units_private_thread',
            'php:MessengerHistoryPolicyTest::test_nothing_private_of_the_previous_resident_reaches_the_new_one',
        ],
        'Message and attachment by ID (48)' => [
            'php:MessengerFilesTest::test_downloads_follow_who_may_see_the_message',
            'php:MessengerFilesTest::test_a_hidden_messages_attachment_is_not_served_to_residents',
            'php:MessengerConversationTest::test_an_attachment_in_another_thread_stays_private',
            'php:MessengerFilesTest::test_a_polyglot_is_never_served_as_something_it_can_run_as',
        ],
    ];

    /** فهرستِ قابلیت‌های بندِ ۳۷ — همه باید در ماتریس باشند */
    private const DOCUMENT_FEATURES = [
        'Community Group', 'Manager ↔ Member private chat', 'Owner ↔ Tenant private chat', 'Saved Messages', 'Read Receipts',
        'Message timestamps', 'Emoji', 'Emoji reactions', 'Reply', 'Forward', 'Copy', 'Edit', 'Delete', 'File messages',
        'Image', 'Video', 'Audio', 'Voice', 'Poll', 'Pin', 'Moderation', 'Message permissions', 'Manager moderation',
    ];

    public function test_every_feature_of_the_document_has_guards(): void
    {
        $this->assertSame([], array_values(array_diff(self::DOCUMENT_FEATURES, array_keys(self::MATRIX))), 'قابلیتی از بندِ ۳۷ بی آزمون در ماتریس.');
        foreach (self::MATRIX as $feature => $guards) {
            $this->assertNotEmpty($guards, $feature);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function guards(): iterable
    {
        foreach (self::MATRIX as $feature => $guards) {
            foreach ($guards as $guard) {
                yield $feature.' ← '.$guard => [$feature, $guard];
            }
        }
    }

    #[DataProvider('guards')]
    public function test_each_guard_still_exists(string $feature, string $guard): void
    {
        [$kind, $target] = explode(':', $guard, 2);
        [$where, $name] = explode('::', $target, 2);

        if ($kind === 'php') {
            $class = 'Tests\\Feature\\'.$where;
            $this->assertTrue(method_exists($class, $name), "{$feature}: {$class}::{$name} دیگر نیست.");

            return;
        }

        $source = (string) @file_get_contents(base_path($where));
        $this->assertNotSame('', $source, "{$feature}: پرونده‌ی {$where} نیست.");
        $call = $kind === 'js' ? 'it' : 'test';
        $this->assertMatchesRegularExpression(
            '/\b'.$call.'\(\s*[\'"`]'.preg_quote($name, '/').'[\'"`]/u',
            $source,
            "{$feature}: «{$name}» در {$where} دیگر نیست.",
        );
    }
}
