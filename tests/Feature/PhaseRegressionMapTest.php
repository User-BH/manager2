<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * هر اصلاحِ فازهای ۰ تا ۹ تستِ رگرسیون دارد (فاز ۸، گسترده‌شده در فاز ۹).
 *
 * ─── 🔴 چرا این فایل وجود دارد ─────────────────────────────────────────────
 * الزامِ صریحِ اصلاحیه: «تمام اصلاحات فازهای ۰ تا ۷ باید regression test
 * داشته باشند.»
 *
 * ⚠️ آن جمله را می‌شود با «بله، داریم» جواب داد و رد شد. این فایل به‌جایش
 * **فهرست می‌کند** و هر ردیف را می‌سنجد: کلاسِ تست وجود دارد، و متدی که
 * نامش ادعا شده واقعاً در آن هست.
 *
 * ─── مرزِ صریحِ این محافظ ───────────────────────────────────────────────────
 * 🔴 این تست ثابت **نمی‌کند** که آن تست‌ها درست می‌سنجند — هیچ فهرستی
 * نمی‌تواند. کاری که می‌کند این است که نگذارد اصلاحی بی‌محافظ بماند یا
 * محافظش بی‌سروصدا حذف شود.
 *
 * ⚠️ اثباتِ اینکه خودِ آن محافظ‌ها پوچ نیستند کارِ **خرابکاری** است، که در
 * هر فاز جداگانه اجرا و گزارش شده.
 */
class PhaseRegressionMapTest extends TestCase
{
    /**
     * فاز ← اصلاح ← تستی که نبودنش را می‌گیرد.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function fixes(): array
    {
        return [
            /* ── فاز ۰: تناقض‌ها ─────────────────────────────────────────── */
            'صحتِ عددِ timeout در گزارش' => [
                'DocumentedNumbersTest', 'test_the_longest_job_timeout_matches_the_documents', '۰',
            ],
            'مبنای عددِ polling' => [
                'DocumentedNumbersTest', 'test_the_polling_load_arithmetic_still_holds', '۰',
            ],
            'یافته‌های تاریخی هنوز رفع‌اند' => [
                'DocumentedNumbersTest', 'test_every_historical_fix_is_still_in_place', '۰',
            ],
            'شمارشِ اسناد با نقشه می‌خواند' => [
                'FinalReportsTest', 'test_the_audit_numbers_match_the_map', '۰',
            ],

            /* ── فاز ۱: یکپارچگیِ مالی ───────────────────────────────────── */
            'دفتر از هیچ مسیری عوض نمی‌شود' => [
                'LedgerMutationVectorsTest', 'test_the_ledger_refuses_every_mutation_path', '۱',
            ],
            'مفرِ عمدیِ بازیابیِ بکاپ باز مانده' => [
                'LedgerMutationVectorsTest', 'test_the_raw_query_escape_hatch_stays_open_on_purpose', '۱',
            ],
            'گذارِ ممنوعِ وضعیتِ پرداخت' => [
                'PaymentStateMachineTest', 'test_a_forbidden_transition_is_refused', '۱',
            ],
            'پرداختِ ردشده پول جابه‌جا نمی‌کند' => [
                'PaymentStateMachineTest', 'test_settling_a_rejected_payment_does_not_move_money', '۱',
            ],
            'نوعِ مجتمع پیش‌فرضِ خطرناک ندارد' => [
                'ComplexTypeTest', 'test_a_missing_type_is_refused_instead_of_defaulting', '۱',
            ],
            'دیتابیس هم جای خالی را پر نمی‌کند' => [
                'ComplexTypeTest', 'test_the_database_refuses_a_complex_without_a_type', '۱',
            ],

            /* ── فاز ۲: چندمستأجری ───────────────────────────────────────── */
            'هر مسیرِ API محافظ دارد' => [
                'EndpointInventoryTest', 'test_every_api_route_is_authenticated_or_explicitly_public', '۲',
            ],
            'مهمان دادهٔ مستأجری نمی‌بیند' => [
                'EndpointInventoryTest', 'test_no_public_route_touches_a_tenant_model', '۲',
            ],
            'فقط دو مسیر از زمینهٔ مستأجری مستثنا' => [
                'EndpointInventoryTest', 'test_only_the_gateway_callbacks_skip_the_tenant_middleware', '۲',
            ],
            'هر مسیرِ مدل‌بند تستِ حمله دارد' => [
                'EndpointInventoryTest', 'test_every_tenant_bound_route_has_a_cross_tenant_attack', '۲',
            ],
            'اسکوپ روی هر مدلِ مستأجری اثر دارد' => [
                'TenantScopeCoverageTest', 'test_every_tenant_model_is_constrained_to_the_active_complex', '۲',
            ],
            'مسیرهای حمله واقعاً وجود دارند' => [
                'TenantIsolationTest', 'test_each_attacked_route_really_exists', '۲',
            ],

            /* ── فاز ۳: نشست ─────────────────────────────────────────────── */
            'کوکیِ نشست HttpOnly است' => [
                'SessionSecurityTest', 'test_the_session_cookie_is_http_only_on_the_real_response', '۳',
            ],
            'کوکیِ CSRF عمداً خواندنی مانده' => [
                'SessionSecurityTest', 'test_the_csrf_cookie_stays_readable_by_design', '۳',
            ],
            'کدِ یک‌بارمصرف بازپخش نمی‌شود' => [
                'SessionSecurityTest', 'test_the_same_code_cannot_be_verified_twice', '۳',
            ],
            'سقفِ نرخ روی IP هم هست' => [
                'SessionSecurityTest', 'test_many_phones_from_one_ip_hit_the_ip_ceiling', '۳',
            ],

            /* ── فاز ۴: رازها ────────────────────────────────────────────── */
            'رازِ متنِ ساده بلند خطا می‌دهد' => [
                'SecretHandlingTest', 'test_a_legacy_plaintext_row_is_refused_with_a_usable_message', '۴',
            ],
            'پویشگرِ راز ردیفِ خام را می‌بیند' => [
                'SecretHandlingTest', 'test_the_audit_command_finds_a_plaintext_row', '۴',
            ],

            /* ── فاز ۶: قراردادِ پاسخ ────────────────────────────────────── */
            'شکلِ پاسخ بی‌صدا عوض نمی‌شود' => [
                'ResponseShapeTest', 'test_no_critical_response_loses_a_field', '۶',
            ],
            'مقایسه‌گرِ شکل واقعاً می‌گیرد' => [
                'ResponseShapeTest', 'test_the_comparison_actually_detects_a_break', '۶',
            ],

            /* ── فاز ۷: صف، آپلود، SSRF ──────────────────────────────────── */
            'پنجرهٔ صف حاشیه دارد' => [
                'PerformanceBaselineTest', 'test_the_queue_never_retries_a_job_that_is_still_running', '۷',
            ],
            'مقصدِ شبکه از متغیر نمی‌آید' => [
                'OutboundSurfaceTest', 'test_no_request_takes_its_destination_from_a_variable', '۷',
            ],
            'آپلود قالبِ اجراشدنی نمی‌پذیرد' => [
                'OutboundSurfaceTest', 'test_no_upload_rule_accepts_an_executable_format', '۷',
            ],

            /* ── فاز ۸: سناریوهای مالی ───────────────────────────────────── */
            'مبلغ از کلاینت پذیرفته نمی‌شود' => [
                'AdversarialFinanceTest', 'test_a_client_supplied_amount_cannot_change_what_is_owed', '۸',
            ],
            'سندِ معکوس کار می‌کند' => [
                'AdversarialFinanceTest', 'test_a_mistake_is_corrected_by_a_reversing_entry', '۸',
            ],
            'واریزِ هم‌زمان گم نمی‌شود' => [
                'AdversarialFinanceTest', 'test_two_credits_never_lose_one_of_them', '۸',
            ],
            'پرداختِ دوباره پول دوباره نمی‌برد' => [
                'AdversarialFinanceTest', 'test_paying_the_same_bill_twice_from_the_wallet_takes_the_money_once', '۸',
            ],

            /* ── فاز ۹: دروازهٔ نهایی ────────────────────────────────────── */
            'سندِ نهایی هر ۲۴ بخش را دارد' => [
                'FinalPreProductionAuditTest', 'test_every_required_section_is_present', '۹',
            ],
            'شمارشِ مرجع از نقشه بازمحاسبه می‌شود' => [
                'FinalPreProductionAuditTest', 'test_the_canonical_count_is_recomputed_from_the_map', '۹',
            ],
            'هر موردِ محیطی معیارِ شکست دارد' => [
                'FinalPreProductionAuditTest', 'test_every_environment_item_carries_all_six_fields', '۹',
            ],
            'هر موردِ خارج از دامنه آستانه دارد' => [
                'FinalPreProductionAuditTest', 'test_every_out_of_scope_item_states_its_future_threshold', '۹',
            ],
            'حکم با جدولِ سه‌سطحی می‌خواند' => [
                'FinalPreProductionAuditTest', 'test_the_verdict_matches_the_three_level_table', '۹',
            ],
            'فاصلهٔ polling در هیچ سندی جعلی نیست' => [
                'CrossDocumentFactsTest', 'test_no_document_claims_a_polling_interval_that_does_not_exist', '۹',
            ],
            'شمارِ گام‌ها در همهٔ اسناد یکی است' => [
                'CrossDocumentFactsTest', 'test_every_document_agrees_on_the_number_of_release_stages', '۹',
            ],
            'دورهٔ کج به نامِ فایلِ خروجی نمی‌رسد' => [
                'DocumentExportTest', 'test_a_crooked_period_never_reaches_the_export_filename', '۹',
            ],

            /* ── تجمیع: گزارشِ مرجعِ نهایی ─────────────────────────────── */
            'گزارشِ مرجع هر ۲۵ بخش را دارد' => [
                'ConsolidatedAuditTest', 'test_every_required_section_is_present', '۹',
            ],
            'سهمِ نقشه در شمارش بازمحاسبه می‌شود' => [
                'ConsolidatedAuditTest', 'test_the_map_share_of_the_count_is_recomputed', '۹',
            ],
            'جدولِ شمارش با ستون‌هایش می‌خواند' => [
                'ConsolidatedAuditTest', 'test_the_headline_table_adds_up', '۹',
            ],
            'ادعای تأییدِ محیط پشتوانه می‌خواهد' => [
                'ConsolidatedAuditTest', 'test_the_environment_verdict_needs_the_table_to_agree', '۹',
            ],

            /* ── مرحلهٔ ۵، فاز ۱: بدهیِ مرحلهٔ چهارم ─────────────────────── */
            'نگاشتِ شدتِ دیسک تستِ مستقیم دارد' => [
                'DiskThresholdTest', 'test_each_occupancy_maps_to_its_severity', '۵',
            ],
            'آستانهٔ دیسک بی‌سروصدا شل نمی‌شود' => [
                'DiskThresholdTest', 'test_the_thresholds_did_not_drift', '۵',
            ],
            'محیطِ بی‌سنجهٔ دیسک هشدار نمی‌سازد' => [
                'DiskThresholdTest', 'test_an_environment_without_disk_metrics_stays_ok', '۵',
            ],
            'هیچ شکلِ ثبت‌شده‌ای پوچ نیست' => [
                'ResponseShapeTest', 'test_no_recorded_shape_is_vacuous', '۵',
            ],

            /* ── مرحلهٔ ۵، فاز ۷: حریمِ خصوصی، CSP و ایندکس ──────────────── */
            'CSP فرمِ رفتن به بانک را بلاک نمی‌کند' => [
                'GatewayFormActionTest', 'test_the_bill_payment_redirect_can_reach_the_bank', '۵',
            ],
            'خریدِ اشتراک هم به بانک می‌رسد' => [
                'GatewayFormActionTest', 'test_the_subscription_redirect_can_reach_the_bank', '۵',
            ],
            'اجازهٔ فرمِ بیرونی فقط برای همان پاسخ' => [
                'GatewayFormActionTest', 'test_every_other_page_still_submits_only_to_itself', '۵',
            ],
            'صفحهٔ خصوصی ایندکس نمی‌شود' => [
                'PrivacyAndIndexingTest', 'test_every_private_surface_is_noindex', '۵',
            ],
            'robots نوایندکس را از خزنده پنهان نمی‌کند' => [
                'PrivacyAndIndexingTest', 'test_robots_never_hides_a_noindex_page_from_the_crawler', '۵',
            ],
            'ضبطِ جلسه فقط در صفحهٔ عمومی' => [
                'PrivacyAndIndexingTest', 'test_session_recording_is_allowed_only_on_public_pages', '۵',
            ],
            'جدولِ خطا کوئری و دادهٔ شخصی نگه نمی‌دارد' => [
                'PrivacyAndIndexingTest', 'test_a_server_error_is_stored_without_query_or_personal_data', '۵',
            ],
            'لاگ موبایل را در هر شکلی پنهان می‌کند' => [
                'PrivacyAndIndexingTest', 'test_every_form_of_a_mobile_number_is_masked', '۵',
            ],
            'کارت پنهان می‌شود، مرجعِ بانک نه' => [
                'PrivacyAndIndexingTest', 'test_a_card_number_is_masked_but_a_bank_reference_is_not', '۵',
            ],

            /* ── مرحلهٔ ۵، فاز ۹: سخت‌سازیِ بک‌اند و عملیات ─────────────────── */
            'فروشِ سفارشِ دیگر در ملت تأیید نمی‌شود' => [
                'GatewayForgeryTest', 'test_mellat_refuses_a_sale_from_another_order_without_asking_the_bank', '۵',
            ],
            'مبلغِ تأییدشده‌ی سامان سنجیده می‌شود' => [
                'GatewayForgeryTest', 'test_saman_does_not_settle_when_the_verified_amount_differs', '۵',
            ],
            'یک تراکنشِ بانک دو قبض را نمی‌پردازد' => [
                'GatewayForgeryTest', 'test_one_bank_transaction_never_pays_two_bills', '۵',
            ],
            'هر مسیرِ API سقفِ سراسری دارد' => [
                'AbusePreventionTest', 'test_every_api_route_carries_the_global_cap', '۵',
            ],
            'پیام‌رسان سیل‌پذیر نیست' => [
                'AbusePreventionTest', 'test_a_user_cannot_flood_the_messenger', '۵',
            ],
            'رمزِ فعلی حدس‌زدنی نیست' => [
                'AbusePreventionTest', 'test_the_current_password_cannot_be_guessed', '۵',
            ],
            'حالتِ تعمیر کاربر را می‌بندد' => [
                'MaintenanceModeTest', 'test_a_regular_user_sees_the_persian_page_and_a_clear_api_answer', '۵',
            ],
            'بازگشتِ بانک در تعمیر باز است' => [
                'MaintenanceModeTest', 'test_a_real_bank_return_still_settles_during_maintenance', '۵',
            ],
            'کندی سرور را از مدار بیرون نمی‌کند' => [
                'SlowRequestMonitoringTest', 'test_slowness_never_takes_the_server_out_of_rotation', '۵',
            ],
            'کندیِ فراگیر هشدار می‌دهد' => [
                'SlowRequestMonitoringTest', 'test_widespread_slowness_raises_an_alert', '۵',
            ],

            /* ── مرحلهٔ ۵، فاز ۶: جدول، نمودار، آپلود، اعلان، Error UX ────── */
            'هر فهرستِ صفحه‌بندی‌شده در رابط ورق می‌خورد' => [
                'PaginationContractTest', 'test_every_paginated_list_is_actually_pageable_in_the_ui', '۵',
            ],
            'ساعتِ نمایش به وقتِ تهران است' => [
                'JalaliTimezoneTest', 'test_a_moment_is_shown_in_tehran_time', '۵',
            ],
            'ماهِ شمسی نیمه‌شبِ تهران عوض می‌شود' => [
                'JalaliTimezoneTest', 'test_the_current_period_turns_at_tehran_midnight', '۵',
            ],

            /* ── مرحلهٔ ۵، فاز ۸: سفرهای مرورگر و سازگاری ──────────────────── */
            'پیش‌شرطِ کیفِ آزمونِ سرتاسری روی محصول اجرا نمی‌شود' => [
                'E2eSeederTest', 'test_it_refuses_to_run_in_production', '۵',
            ],
            'اجرای دوباره‌ی پیش‌شرطِ کیف اعتبار را دوبرابر نمی‌کند' => [
                'E2eSeederTest', 'test_running_it_again_does_not_double_the_credit', '۵',
            ],
            'مالک‌های پیش‌شرطِ کیف همان مالک‌های سفرِ مرورگرند' => [
                'E2eSeederTest', 'test_the_owners_match_the_browser_journey', '۵',
            ],

            /* ── مرحلهٔ ۵، فاز ۱۰: چرخه‌ی نشست ─────────────────────────────── */
            'تغییرِ رمز نشستِ دزدیده را می‌بندد' => [
                'SessionLifecycleTest', 'test_changing_the_password_signs_out_a_stolen_session', '۵',
            ],
            'شناسه‌ی قبلیِ نشست پس از تغییرِ رمز مرده است' => [
                'SessionLifecycleTest', 'test_the_old_session_id_is_destroyed_by_a_password_change', '۵',
            ],
            'بازیابیِ رمز همه‌ی نشست‌ها را می‌بندد' => [
                'SessionLifecycleTest', 'test_resetting_the_password_signs_out_every_session', '۵',
            ],
            'نشستِ فعال هم سقفِ مطلقِ عمر دارد' => [
                'SessionLifecycleTest', 'test_an_active_session_still_ends_at_the_absolute_lifetime', '۵',
            ],
            'سوپرادمین دستگاهِ مورداعتماد ندارد' => [
                'SessionLifecycleTest', 'test_a_super_admin_never_rides_a_trusted_device', '۵',
            ],
            'هر مسیرِ حساس تأییدِ دوباره‌ی رمز می‌خواهد' => [
                'ReauthenticationTest', 'test_every_sensitive_route_carries_the_guard', '۵',
            ],
            'شماره‌کارتِ مجتمع بدونِ رمز عوض نمی‌شود' => [
                'ReauthenticationTest', 'test_changing_the_card_number_needs_the_password_but_a_plain_save_does_not', '۵',
            ],
            'ورود از دستگاهِ تازه هشدار می‌دهد' => [
                'LoginHistoryTest', 'test_a_login_from_a_new_device_raises_an_alert_and_a_known_one_does_not', '۵',
            ],

            /* ── مرحلهٔ ۶، فاز ۱۱: بلاگ ────────────────────────────────────── */
            'متنِ مقاله اسکریپت و پیوندِ ناامن اجرا نمی‌کند' => [
                'BlogTest', 'test_raw_html_and_dangerous_links_never_execute', '۶',
            ],
            'تصویرِ بیرونی (ردیاب) از مقاله حذف می‌شود' => [
                'BlogTest', 'test_external_links_and_images_are_handled_safely', '۶',
            ],
            'پیش‌نویس با حدسِ آدرس خوانده نمی‌شود' => [
                'BlogTest', 'test_a_draft_is_invisible_to_the_public', '۶',
            ],
            'مقاله‌ی زمان‌بندی‌شده پیش از زمانش دیده نمی‌شود' => [
                'BlogTest', 'test_a_scheduled_post_waits_for_its_time', '۶',
            ],
            'آدرسِ متعارفِ مقاله از هدرِ Host نمی‌آید' => [
                'BlogTest', 'test_the_canonical_url_ignores_a_forged_host', '۶',
            ],
            'نقشه‌ی سایت فقط مقاله‌ی منتشرشده دارد' => [
                'BlogTest', 'test_the_sitemap_lists_only_visible_posts', '۶',
            ],
            'مسیرِ تصویرِ بلاگ فقط فایلِ بارگذاری‌شده را می‌دهد' => [
                'BlogTest', 'test_an_uploaded_image_is_served_and_nothing_else_is', '۶',
            ],
            'ویرایشِ مقاله از پشتِ کش بلافاصله دیده می‌شود' => [
                'BlogTest', 'test_an_edit_is_visible_immediately_despite_the_cache', '۶',
            ],

            /* ── مرحلهٔ ۶، فاز ۱۲: پیام‌رسانِ گفت‌وگومحور ──────────────────── */
            'مستأجرِ رفته رشته‌ی واحدِ قبلی را نمی‌بیند' => [
                'MessengerConversationTest', 'test_a_former_tenant_loses_the_old_units_thread', '۶',
            ],
            'رشته‌ی خصوصیِ واحدِ دیگر بسته است' => [
                'MessengerConversationTest', 'test_a_resident_cannot_reach_another_units_private_thread', '۶',
            ],
            // ⚠️ قاعده در ۱۰/۹ عوض شد (بندهای ۳۲، ۵۴ و ۵۵): کانال گروهِ اصلیِ مجتمع است و اعضا در آن می‌نویسند؛ محافظِ
            // همین ردیف حالا «فقط مدیریت» را می‌سنجد که مدیر می‌گذارد — همان مرزِ نوشتن، نه حذفِ آن
            'ساکن در گروهِ «فقط مدیریت» نمی‌نویسد' => [
                'MessengerConversationTest', 'test_residents_post_to_the_community_group_unless_management_closes_it', '۶',
            ],
            'ارسالِ دوباره پیامِ تکراری نمی‌سازد' => [
                'MessengerConversationTest', 'test_a_retried_send_does_not_duplicate', '۶',
            ],
            'client_idِ مصرف‌شده چیزی لو نمی‌دهد' => [
                'MessengerConversationTest', 'test_a_reused_client_id_elsewhere_reveals_nothing', '۶',
            ],
            'متنِ پیامِ پنهان به ساکن نمی‌رسد' => [
                'MessengerConversationTest', 'test_a_hidden_message_body_never_reaches_residents', '۶',
            ],
            'عضویت و حقِ نوشتنِ گروه' => [
                'MessengerConversationTest', 'test_group_membership_and_posting_rights_are_enforced', '۶',
            ],
            'فیلدهای جعلی در بدنه‌ی پیام نادیده‌اند' => [
                'MessengerConversationTest', 'test_forged_fields_in_the_payload_are_ignored', '۶',
            ],
            'رسیدِ خواندن از گفت‌وگو بیرون نمی‌زند' => [
                'MessengerConversationTest', 'test_read_receipts_never_leak_across_conversations', '۶',
            ],
            'پیامِ منتظرِ انتقال همان حریم را دارد' => [
                'MessengerConversationTest', 'test_a_message_still_awaiting_backfill_keeps_the_same_privacy', '۶',
            ],
            'خواندنِ خودِ نویسنده «خوانده شد» نیست' => [
                'MessengerConversationTest', 'test_reading_your_own_message_does_not_mark_it_read', '۶',
            ],
            'مهاجرت همه‌ی پیام‌های قبلی را نگه می‌دارد' => [
                'MessengerConversationTest', 'test_the_backfill_preserves_every_existing_message', '۶',
            ],

            /* ── مرحلهٔ ۶، فاز ۱۴: غنی‌سازیِ پیام‌رسان ─────────────────────────── */
            '🔴 جست‌وجوی سراسری پیامِ خصوصیِ واحدِ دیگر را نشان نمی‌دهد' => [
                'MessageSearchPrivacyTest', 'test_a_private_unit_thread_is_not_searchable_by_other_residents', '۶',
            ],
            'جست‌وجوی سراسری: مستأجرِ رفته' => [
                'MessageSearchPrivacyTest', 'test_a_former_tenant_cannot_search_the_old_units_thread', '۶',
            ],
            'پاسخ راهِ دیدنِ پیامِ نادیدنی نیست' => [
                'MessengerEnrichmentTest', 'test_a_reply_cannot_reach_outside_what_the_writer_can_see', '۶',
            ],
            'نقلِ پاسخ پس از پنهان‌شدنِ هدف پاک می‌شود' => [
                'MessengerEnrichmentTest', 'test_a_reply_stops_quoting_a_target_that_was_hidden_later', '۶',
            ],
            'حذف یعنی محتوا واقعاً رفته' => [
                'MessengerEnrichmentTest', 'test_deleting_removes_the_content_not_just_the_view', '۶',
            ],
            'ویرایش فقط پیامِ خود، در پنجره، نه پیامِ پنهان‌شده' => [
                'MessengerEnrichmentTest', 'test_nobody_edits_what_is_not_theirs_to_edit', '۶',
            ],
            'ویرایش، واکنش، حذف و نقلِ پاسخ با پرسشِ دوره‌ای می‌رسند' => [
                'MessengerEnrichmentTest', 'test_edits_reactions_and_deletions_reach_the_other_side_by_polling', '۶',
            ],
            'واکنش فقط روی پیامِ دیدنی و از همین گفت‌وگو' => [
                'MessengerEnrichmentTest', 'test_reactions_follow_visibility_and_moderation', '۶',
            ],
            'گزارش بی‌نامِ گزارش‌دهنده به مدیر' => [
                'MessengerEnrichmentTest', 'test_a_resident_reports_and_management_resolves_without_seeing_who', '۶',
            ],
            'پیامِ صوتی فقط صوت و با سقفِ اندازه' => [
                'MessengerEnrichmentTest', 'test_a_voice_message_accepts_only_audio_of_bounded_size', '۶',
            ],
            'میکروفون فقط برای همین مبدأ' => [
                'SecurityHeadersTest', 'test_only_this_origin_may_ask_for_the_microphone', '۶',
            ],

            /* ── مرحلهٔ ۶، فاز ۱۵: دستیارِ پشتیبانیِ قاعده‌محور ─────────────────── */
            'دستیار هرگز با اطمینان غلط نمی‌گوید (مجموعه‌ی اصلی)' => [
                'SupportBotEvaluationTest', 'test_the_tuning_set_stays_accurate_and_never_confidently_wrong', '۶',
            ],
            'مجموعه‌ی کنارگذاشته پس‌رفت نمی‌کند' => [
                'SupportBotEvaluationTest', 'test_the_held_out_set_does_not_regress', '۶',
            ],
            'هر پیوندِ پاسخ به جایی واقعی می‌رود' => [
                'SupportBotEvaluationTest', 'test_every_link_points_somewhere_real', '۶',
            ],
            'کلیدواژه‌ی نرمال‌نشده بی‌صدا نمی‌میرد' => [
                'SupportBotEvaluationTest', 'test_every_keyword_is_already_normalised', '۶',
            ],
            'قابلیتِ خاموش معرفی نمی‌شود' => [
                'SupportBotEvaluationTest', 'test_a_switched_off_feature_is_not_advertised', '۶',
            ],
            'پاسخ متنِ کاربر را بازتاب نمی‌دهد' => [
                'SupportBotEvaluationTest', 'test_the_reply_never_echoes_the_visitors_text', '۶',
            ],

            /* ── پس از فاز ۱۵: بستنِ شمارشِ حساب (Medium) ───────────────────────── */
            '🔴 فراموشیِ رمز برای شماره‌ی با و بی‌حساب یکسان پاسخ می‌دهد' => [
                'AccountEnumerationTest', 'test_forgot_password_answers_identically_with_or_without_an_account', '۶',
            ],
            'فاصله‌ی ارسالِ مجدد نشانه‌ی وجودِ حساب نیست' => [
                'AccountEnumerationTest', 'test_the_resend_cooldown_is_the_same_for_every_number', '۶',
            ],
            'گامِ تأیید «حساب پیدا نشد» نمی‌گوید' => [
                'AccountEnumerationTest', 'test_the_verify_step_does_not_reveal_a_missing_account', '۶',
            ],
            'پیامکِ بازیابی پس از پاسخ می‌رود' => [
                'AccountEnumerationTest', 'test_the_sms_leaves_only_after_the_response', '۶',
            ],
            'زمانِ پاسخ به وجودِ حساب بسته نیست' => [
                'AccountEnumerationTest', 'test_response_time_does_not_depend_on_the_account', '۶',
            ],
            'ثبت‌نام پیش از کد نمی‌گوید «قبلاً ثبت شده»' => [
                'AccountEnumerationTest', 'test_registration_does_not_reveal_an_existing_number_before_the_code', '۶',
            ],

            /* ── مرحلهٔ ۷ · فاز ۱: رفتارهای سراسری (تم، تاریخچه، ۴۰۴) ─────────── */
            '🔴 تم از سیستم‌عامل نمی‌آید و همه‌ی سندها یک اسکریپت دارند' => [
                'GlobalBehaviourTest', 'test_every_document_reads_the_theme_from_the_one_script', '۷',
            ],
            'رنگِ نوارِ مرورگر با تمِ انتخابی، نه سیستم‌عامل' => [
                'GlobalBehaviourTest', 'test_the_browser_bar_colour_is_one_tag_the_script_can_follow', '۷',
            ],
            'اسکریپت‌های صفحه‌ی آفلاین زیرِ CSP اجرا می‌شوند' => [
                'GlobalBehaviourTest', 'test_every_inline_script_on_the_offline_page_carries_the_nonce', '۷',
            ],
            '🔴 کاربرِ واردشده صفحه‌ی ورود را نمی‌بیند (سمتِ سرور)' => [
                'GlobalBehaviourTest', 'test_a_signed_in_user_never_sees_an_auth_page', '۷',
            ],
            'صفحه‌ی ورود برای «بازگشت» نگه داشته نمی‌شود' => [
                'GlobalBehaviourTest', 'test_auth_pages_are_never_kept_for_back_navigation', '۷',
            ],
            '۴۰۴ِ سرورساید فارسی و با راهِ خروج' => [
                'GlobalBehaviourTest', 'test_a_missing_page_answers_in_persian_with_a_way_out', '۷',
            ],

            /* ── مرحلهٔ ۷ · فاز ۲: معرفیِ محصول («مسکونی و تجاری») ─────────── */
            '🔴 هیچ متنِ معرفی محصول را «فقط مسکونی» نمی‌نامد' => [
                'ProductScopeCopyTest', 'test_no_introduction_calls_the_product_residential_only', '۷',
            ],
            'عنوان، توضیح، OG، JSON-LD و llms.txt «مسکونی و تجاری»اند' => [
                'ProductScopeCopyTest', 'test_the_rendered_pages_say_residential_and_commercial', '۷',
            ],
            'آمارِ صفحه‌ی اصلی همان عددهای کارفرما' => [
                'ProductScopeCopyTest', 'test_the_home_page_numbers_are_the_ones_given', '۷',
            ],
            'کارتِ پیام‌رسان همان پیام‌رسانِ ساخته‌شده' => [
                'ProductScopeCopyTest', 'test_the_messenger_card_describes_the_messenger_that_exists', '۷',
            ],

            /* ── مرحلهٔ ۷ · فاز ۳: تجربه‌ی بارگذاری ───────────────────────────── */
            '🔴 صفحه‌ی تمام-React به‌جای سفید حالتِ بارگذاری دارد' => [
                'LoadExperienceTest', 'test_a_page_that_is_all_react_shows_a_loading_state_not_white', '۷',
            ],
            'لایه‌ی بارگذاری روی محتوای سرورساید نمی‌نشیند' => [
                'LoadExperienceTest', 'test_a_server_rendered_page_gets_no_loading_layer', '۷',
            ],
            'لایه‌ی بارگذاری روی دستگاهِ سریع چشمک نمی‌زند' => [
                'LoadExperienceTest', 'test_the_loading_layer_waits_before_it_shows', '۷',
            ],
            'لوگو به اندازه‌ی نمایشش' => [
                'LoadExperienceTest', 'test_the_logo_is_sized_for_where_it_is_shown', '۷',
            ],
            'تصویرِ پنلِ ورود فقط در دسکتاپ پیش‌بار می‌شود' => [
                'LoadExperienceTest', 'test_the_auth_panel_image_is_preloaded_only_where_it_is_seen', '۷',
            ],

            /* ── مرحلهٔ ۷ · فاز ۴: صفحه‌ی پشتیبانی و فرمِ بازخورد ──────────────── */
            '🔴 بازخورد واقعاً در سرور ذخیره می‌شود' => [
                'FeedbackTest', 'test_a_guest_can_send_feedback_and_it_is_stored', '۷',
            ],
            'هیچ داده‌ای به اعتمادِ فرمِ مرورگر معتبر نیست' => [
                'FeedbackTest', 'test_the_server_rejects_what_the_form_would_have_stopped', '۷',
            ],
            'تله‌ی ربات: پاسخِ موفق، بی‌ذخیره' => [
                'FeedbackTest', 'test_a_filled_honeypot_looks_accepted_but_stores_nothing', '۷',
            ],
            'ارسالِ دوباره‌ی همان متن ردیفِ دوم نمی‌سازد' => [
                'FeedbackTest', 'test_the_same_text_twice_is_stored_once', '۷',
            ],
            'سقفِ نرخِ فرمِ بازخورد' => [
                'FeedbackTest', 'test_the_rate_limit_stops_a_flood', '۷',
            ],
            'پاک‌سازیِ نویسه‌های مخرب بی‌شکستنِ نیم‌فاصله' => [
                'FeedbackTest', 'test_invisible_control_and_direction_characters_are_removed_but_half_spaces_stay', '۷',
            ],
            'صفِ بازخورد فقط برای ادمینِ کل' => [
                'FeedbackTest', 'test_only_the_platform_admin_can_read_the_queue', '۷',
            ],
            '🔴 کاربرِ بی‌مجتمع از مهمان کمتر نمی‌تواند (بازخورد و دستیار)' => [
                'FeedbackTest', 'test_a_signed_in_user_without_a_complex_can_still_use_the_support_page', '۷',
            ],
            'راهِ تماس فقط تلفن و شبکه‌ها' => [
                'ContactChannelsTest', 'test_the_public_footer_offers_only_the_phone_and_the_social_networks', '۷',
            ],
            'تنظیمِ ذخیره‌شده‌ی پنل هم فقط تلفن می‌شود' => [
                'ContactChannelsTest', 'test_the_migration_switches_off_what_the_panel_had_saved', '۷',
            ],

            /* ── مرحلهٔ ۷ · فاز ۷: بلاگ ──────────────────────────────────────── */
            '🔴 صفحه‌بندیِ واقعی از پایگاه‌داده، ده تا در هر صفحه' => [
                'BlogApiTest', 'test_a_page_holds_ten_articles_and_the_rest_come_from_the_database', '۷',
            ],
            'جست‌وجوی واقعی و صفحه‌بندی‌شده با عبارت در آدرس' => [
                'BlogApiTest', 'test_search_is_real_paginated_and_keeps_the_query_in_the_url', '۷',
            ],
            'ورودیِ نامعتبرِ جست‌وجو را سرور رد می‌کند' => [
                'BlogApiTest', 'test_invalid_search_input_is_rejected_by_the_server', '۷',
            ],
            'سقفِ نرخِ API ِ بلاگ' => [
                'BlogApiTest', 'test_the_api_is_rate_limited', '۷',
            ],
            'پیش‌نویس از API بیرون نمی‌آید' => [
                'BlogApiTest', 'test_drafts_and_scheduled_posts_never_leave_through_the_api', '۷',
            ],
            'نامِ نویسنده هیچ‌جا نیست' => [
                'BlogApiTest', 'test_an_article_comes_with_its_html_meta_and_no_author', '۷',
            ],
            'مقاله‌های مرتبط بر اساسِ موضوع' => [
                'BlogApiTest', 'test_related_articles_follow_the_topic_not_the_date', '۷',
            ],
            '🔴 واژه‌ی فقط-در-عنوان با «آ» و «ي» پیدا می‌شود' => [
                'BlogApiTest', 'test_a_word_only_in_the_title_is_found_even_with_arabic_or_alef_madda', '۷',
            ],
            '🔴 مسیرِ راهنما پس از جابه‌جاییِ بی‌بارگذاری کهنه نمی‌ماند' => [
                'BlogApiTest', 'test_the_breadcrumb_comes_along_and_matches_the_server_rendered_page', '۷',
            ],

            /* ── مرحلهٔ ۸ · فاز ۱: دادهٔ نمایشی، فوتر، تصویرها ─────────────────── */
            'چهار حسابِ داشبورد با «password» و نقشِ درست وارد می‌شوند' => [
                'DemoSeederTest', 'test_each_of_the_four_accounts_signs_in_with_its_role', '۸',
            ],
            '🔴 دادهٔ نمایشی (رمزِ password) روی محصول اجرا نمی‌شود' => [
                'DemoSeederTest', 'test_it_refuses_to_run_in_production', '۸',
            ],
            'بلاگ فوترِ صفحه‌ی اصلی را ندارد' => [
                'BlogTest', 'test_the_blog_has_no_home_footer', '۸',
            ],

            /* ── مرحلهٔ ۸ · فاز ۳: درستیِ بلاگ ─────────────────────────────────── */
            'حذفِ کامل: ردیف، تصویرِ بی‌استفاده، برچسبِ بی‌مقاله' => [
                'BlogCorrectnessTest', 'test_deleting_removes_the_row_its_unused_images_and_tags_but_keeps_shared_ones', '۸',
            ],
            '🔴 مقاله‌ی حذف‌شده با استقرارِ بعدی برنمی‌گردد' => [
                'BlogCorrectnessTest', 'test_an_imported_article_that_was_deleted_is_not_brought_back_by_the_next_deploy', '۸',
            ],
            '🔴 تصویرِ گم‌شده با blog:import ترمیم می‌شود' => [
                'BlogCorrectnessTest', 'test_the_next_import_restores_an_image_that_went_missing', '۸',
            ],
            '🔴 نوشتنِ ناموفقِ تصویر بی‌صدا نمی‌گذرد' => [
                'BlogCorrectnessTest', 'test_a_failed_image_write_is_loud_not_a_post_with_a_missing_cover', '۸',
            ],
            'سلامت: جلدی که فرایندِ وب نمی‌بیند' => [
                'BlogCorrectnessTest', 'test_health_sees_covers_the_web_process_cannot_read', '۸',
            ],
            '🔴 دسترسیِ storage از کلِ درخت، نه پوشه‌ی بالا' => [
                'DeployPermissionsTest', 'test_the_decision_comes_from_the_whole_tree_not_the_top_folder', '۸',
            ],
            'مرتبط فقط واقعاً مرتبط' => [
                'BlogCorrectnessTest', 'test_related_are_only_truly_related_and_title_keywords_count', '۸',
            ],

            /* ── مرحلهٔ ۸ · فاز ۴: جست‌وجو و SEO ِ بلاگ ───────────────────────── */
            '🔴 واژه‌های جدا از هم پیدا می‌شوند' => [
                'BlogSearchTest', 'test_words_need_not_be_next_to_each_other', '۸',
            ],
            'رتبه: عنوان، برچسب، متن؛ کلِ عبارت اول' => [
                'BlogSearchTest', 'test_title_beats_tags_which_beat_the_body_and_the_whole_phrase_wins', '۸',
            ],
            'بی‌تطابقِ کامل: «نزدیک»، نه «پیدا نشد»' => [
                'BlogSearchTest', 'test_no_full_match_shows_close_results_marked_as_such', '۸',
            ],
            'عنوان و توضیحِ یکتا برای هر صفحه‌ی فهرست' => [
                'BlogSearchTest', 'test_every_listing_page_has_its_own_title_and_description', '۸',
            ],
            '🔴 API ِ همه‌ی مقاله‌ها با توضیحِ UTF-8 ِ سالم' => [
                'BlogSearchTest', 'test_every_shipped_article_opens_through_the_api_with_a_clean_description', '۸',
            ],

            /* ── مرحلهٔ ۸ · فاز ۵: فرمِ بازخورد ─────────────────────────────────── */
            'موبایل اجباری و فقط شماره‌ی ایرانی؛ نام فقط فارسی' => [
                'FeedbackTest', 'test_the_server_rejects_what_the_form_would_have_stopped', '۸',
            ],
            'نامِ فارسی تمیز و اختیاری' => [
                'FeedbackTest', 'test_a_persian_name_is_kept_clean_and_optional', '۸',
            ],
            'فیلدِ بیرون از فرم ذخیره نمی‌شود' => [
                'FeedbackTest', 'test_extra_fields_cannot_be_mass_assigned', '۸',
            ],

            /* ── مرحلهٔ ۸ · فاز ۶: بازخورد کاربران و پاسخ به عضو ─────────────────── */
            'عضو بودن فقط از سرور؛ تاریخ و ساعتِ جدا' => [
                'FeedbackReplyTest', 'test_the_list_says_who_is_a_member_with_date_and_time_apart', '۸',
            ],
            'صفِ بازخورد صفحه‌بندی و بی N+1' => [
                'FeedbackReplyTest', 'test_the_list_is_paginated_and_free_of_n_plus_one', '۸',
            ],
            '🔴 پاسخ فقط اعلانِ همان عضو؛ وضعیت عوض نمی‌شود' => [
                'FeedbackReplyTest', 'test_a_member_gets_the_reply_as_their_own_notification', '۸',
            ],
            'غیرعضو پاسخِ داخلِ سایت نمی‌گیرد' => [
                'FeedbackReplyTest', 'test_a_non_member_cannot_be_replied_to_in_the_site', '۸',
            ],
            'فرستنده‌ی واردشده بر شماره‌ی تایپ‌شده مقدم است' => [
                'FeedbackReplyTest', 'test_the_signed_in_sender_wins_over_the_typed_phone', '۸',
            ],
            '🔴 گیرنده از درخواست انتخاب نمی‌شود' => [
                'FeedbackReplyTest', 'test_the_recipient_cannot_be_chosen_by_the_request', '۸',
            ],
            'صف و پاسخ فقط برای ادمینِ کل' => [
                'FeedbackReplyTest', 'test_only_the_super_admin_sees_the_queue_or_replies', '۸',
            ],
            '🔴 IDOR: پاسخ را کسِ دیگری نمی‌بیند و نمی‌خواند' => [
                'FeedbackReplyTest', 'test_nobody_else_can_see_or_touch_the_reply', '۸',
            ],
            'پاسخ اعتبارسنجی و متنِ ساده' => [
                'FeedbackReplyTest', 'test_the_reply_is_validated_and_kept_as_plain_text', '۸',
            ],
            'سقفِ نرخِ پاسخ' => [
                'FeedbackReplyTest', 'test_replies_are_rate_limited', '۸',
            ],
            'حسابِ اولیه اعلانِ خودش را خوانده می‌کند' => [
                'InitialAccountTest', 'test_an_initial_account_can_mark_its_own_notifications_read', '۸',
            ],

            /* ── مرحلهٔ ۹ · فاز ۳: نام و نام خانوادگیِ جدا، داشبوردِ اولیه ─────────── */
            'ثبت‌نام دو بخش را ذخیره و نامِ نمایشی را از آن می‌سازد' => [
                'PersonNameTest', 'test_registration_stores_both_parts_and_derives_the_display_name', '۹',
            ],
            'نامِ غیرفارسی در ثبت‌نام رد می‌شود' => [
                'PersonNameTest', 'test_registration_rejects_what_is_not_a_persian_name', '۹',
            ],
            '🔴 نامِ حسابِ قبلی با حدس شکسته نمی‌شود' => [
                'PersonNameTest', 'test_a_legacy_name_is_kept_and_only_asked_to_be_completed', '۹',
            ],
            '🔴 نامِ نمایشی از دو بخش جدا نمی‌افتد' => [
                'PersonNameTest', 'test_the_display_name_cannot_drift_from_its_parts', '۹',
            ],
            'مدیر نامِ حسابِ خودنام را بی‌صدا عوض نمی‌کند' => [
                'PersonNameTest', 'test_a_manager_cannot_silently_rename_a_self_named_account', '۹',
            ],

            /* ── مرحلهٔ ۹ · فاز ۴: CheckoutIntent و پرداختِ آنلاینِ اشتراک ───────────── */
            'پیش از پرداخت فقط قصد؛ مبلغ از سرور' => [
                'CheckoutIntentTest', 'test_starting_creates_only_an_intent_with_the_server_amount', '۹',
            ],
            '🔴 پرداختِ تأییدشده: مجتمع + اشتراک + مدیر با هم' => [
                'CheckoutIntentTest', 'test_a_verified_payment_creates_complex_subscription_and_manager_atomically', '۹',
            ],
            '🔴 بازگشتِ تکراری هیچ چیزِ دوم نمی‌سازد' => [
                'CheckoutIntentTest', 'test_repeated_callbacks_create_nothing_twice_and_never_charge_again', '۹',
            ],
            'تکمیلِ دوباره بی‌اثر' => [
                'CheckoutIntentTest', 'test_completing_twice_is_idempotent', '۹',
            ],
            'لغو: کاربر اولیه می‌ماند و دوباره می‌خرد' => [
                'CheckoutIntentTest', 'test_a_cancelled_payment_leaves_the_user_initial_and_free_to_retry', '۹',
            ],
            '🔴 پارامترِ «موفق» ِ کلاینت خوانده نمی‌شود' => [
                'CheckoutIntentTest', 'test_client_supplied_success_parameters_are_ignored', '۹',
            ],
            'مرجعِ بیگانه پیش از تأیید رد' => [
                'CheckoutIntentTest', 'test_a_foreign_authority_is_rejected_before_verification', '۹',
            ],
            '🔴 مغایرتِ مبلغ و timeout نامعلوم‌اند، نه ناموفق' => [
                'CheckoutIntentTest', 'test_amount_mismatch_and_timeouts_are_indeterminate_not_failed', '۹',
            ],
            'یک کدِ رهگیری، یک پرداخت — قصدِ خرید هم' => [
                'CheckoutIntentTest', 'test_a_tracking_code_already_used_elsewhere_creates_nothing', '۹',
            ],
            // ۱۰/۵ (تصمیمِ کارفرما): عضویت دیگر مانعِ خرید نیست؛ همان محافظ برای خریدارِ غیرفعال‌شده
            'پرداختی که به مجتمع تبدیل نمی‌شود نگه داشته و بررسی می‌شود' => [
                'CheckoutIntentTest', 'test_a_payment_for_a_buyer_deactivated_meanwhile_is_kept_for_review', '۹',
            ],
            'بازگشت فقط برای خریدار یا بانک' => [
                'CheckoutIntentTest', 'test_only_the_buyer_or_the_bank_can_return_to_an_intent', '۹',
            ],
            'پلن و نوع در سرور سنجیده می‌شوند' => [
                'CheckoutIntentTest', 'test_plans_are_checked_on_the_server', '۹',
            ],
            'خریدِ نامعلوم یا ساخته‌نشده در payments:stale' => [
                'CheckoutIntentTest', 'test_indeterminate_and_unfulfilled_purchases_surface_for_manual_review', '۹',
            ],
            '🔴 حذفِ خریدار یا مجتمع سابقه‌ی مالی را پاک نمی‌کند' => [
                'CheckoutIntentTest', 'test_deleting_the_buyer_or_the_complex_never_erases_payment_history', '۹',
            ],

            /* ── مرحلهٔ ۹ · فاز ۵: بستنِ رسید، صفحه‌ی خرید، همزمانیِ واقعی ──────────── */
            '🔴 رسید هرگز حسابِ اولیه را مدیر نمی‌کند (F1، F2)' => [
                'InitialAccountTest', 'test_a_receipt_never_makes_an_initial_account_a_manager', '۹',
            ],
            'صفحه‌ی خرید همان پلن‌هایی را عرضه می‌کند که سرور می‌پذیرد' => [
                'CheckoutIntentTest', 'test_the_purchase_page_offers_exactly_what_the_server_accepts', '۹',
            ],
            '🔴 callback ِ هم‌زمان در چند پردازه: دقیقاً یکی از همه‌چیز' => [
                'CheckoutConcurrencyTest', 'test_concurrent_duplicate_callbacks_create_exactly_one_of_everything', '۹',
            ],
            'بازپخشِ هم‌زمان با رهگیری‌های متفاوت: یک پرداختِ موفق' => [
                'CheckoutConcurrencyTest', 'test_concurrent_replays_with_different_tracking_codes_still_complete_once', '۹',
            ],
            'زرین‌پال: مبلغِ تأیید از سفارش، نه از بازگشت' => [
                'ZarinpalGatewayTest', 'test_verification_never_takes_the_amount_from_the_callback', '۹',
            ],

            /* ── مرحلهٔ ۹ · فاز ۶: دعوتِ امن، پایانِ عضویت، غیرفعال‌سازی، F6 ─────────── */
            '🔴 F5: شماره‌ی بی‌حساب دعوت می‌گیرد، نه حسابی با رمزِ مدیر' => [
                'InitialAccountTest', 'test_a_brand_new_phone_gets_an_invitation_not_an_account', '۹',
            ],
            '🔴 F7: توکنِ ۲۵۶ بیتی و فقط هشش در پایگاه‌داده' => [
                'SecureInvitationTest', 'test_the_token_is_long_random_and_only_its_hash_is_stored', '۹',
            ],
            '🔴 پذیرش کدِ OTP ِ تازه می‌خواهد؛ دستگاهِ مورداعتماد دورش نمی‌زند' => [
                'SecureInvitationTest', 'test_a_trusted_device_does_not_skip_the_code', '۹',
            ],
            'دعوت فقط برای صاحبِ همان شماره' => [
                'SecureInvitationTest', 'test_only_the_owner_of_the_target_phone_sees_or_answers_it', '۹',
            ],
            'دعوتِ منقضی، باطل و مصرف‌شده پذیرفته نمی‌شود' => [
                'SecureInvitationTest', 'test_a_revoked_invitation_cannot_be_accepted', '۹',
            ],
            'پایگاه‌داده دعوتِ زنده‌ی دوم را رد می‌کند' => [
                'SecureInvitationTest', 'test_the_database_refuses_a_second_live_invitation', '۹',
            ],
            '🔴 F6: تأییدِ درخواستِ پیوستن بی واحد رد می‌شود' => [
                'JoinRequestTest', 'test_approving_without_a_unit_is_refused', '۹',
            ],
            '🔴 F4: حذفِ ساکن حساب و سابقه را نگه می‌دارد' => [
                'MembershipLifecycleTest', 'test_removing_a_resident_keeps_the_account_and_every_record', '۹',
            ],
            '🔴 ساکنِ تازه قبض و پرداختِ ساکنِ قبلی را نمی‌بیند' => [
                'MembershipLifecycleTest', 'test_the_next_tenant_does_not_see_the_previous_tenants_bills', '۹',
            ],
            '🔴 کیف مالِ حساب است: ساکنِ تازه کیفِ ساکنِ قبلی را نه می‌بیند نه خرج می‌کند' => [
                'WalletOwnershipTest', 'test_the_next_tenant_can_neither_see_nor_spend_the_previous_tenants_wallet', '۹',
            ],
            '🔴 پایانِ عضویت مانده و دسترسیِ کیفِ شخص را دست نمی‌زند' => [
                'WalletOwnershipTest', 'test_ending_membership_keeps_the_balance_and_the_access', '۹',
            ],
            '🔴 برداشتِ هم‌زمانِ کیف در چند پردازه: نه منفی، نه ناسازگار با دفتر' => [
                'WalletConcurrencyTest', 'test_concurrent_debits_never_overdraw_or_desync_the_ledger', '۹',
            ],
            '🔴 پذیرشِ هم‌زمانِ یک دعوت در چند پردازه: یک عضویت' => [
                'InvitationConcurrencyTest', 'test_one_invitation_accepted_concurrently_makes_one_membership', '۹',
            ],
            // از ۱۰/۴: هر دعوت یک بار، یک عضویت در هر مجتمع (پیش از آن: فقط یکی)
            '🔴 پذیرشِ هم‌زمانِ دو دعوتِ دو مجتمع: یک عضویت در هر مجتمع، نه تکراری' => [
                'InvitationConcurrencyTest', 'test_two_invitations_accepted_concurrently_make_one_membership_each', '۹',
            ],
            'کدِ OTP ِ منقضی برای پذیرشِ دعوت رد می‌شود' => [
                'SecureInvitationTest', 'test_an_expired_code_is_refused', '۹',
            ],
            // از ۱۰/۴ زنده می‌ماند و پذیرفتنش عضویتِ دوم است
            'دعوتِ مجتمعِ دیگر پس از عضویت عضویتِ اول را تهدید نمی‌کند' => [
                'SecureInvitationTest', 'test_another_complexes_invitation_stays_alive_and_adds_a_second_membership', '۹',
            ],
            'عضویتِ تکراری در خودِ سرویس رد می‌شود' => [
                'SecureInvitationTest', 'test_a_duplicate_membership_is_refused_by_the_service', '۹',
            ],
            '🔴 ساکنِ تازه کلِ تاریخچه‌ی گروهِ عمومی را می‌بیند' => [
                'MessengerHistoryPolicyTest', 'test_the_new_resident_sees_the_whole_public_group_history', '۹',
            ],
            '🔴 هیچ چیزِ خصوصیِ ساکنِ قبلی به ساکنِ تازه نمی‌رسد' => [
                'MessengerHistoryPolicyTest', 'test_nothing_private_of_the_previous_resident_reaches_the_new_one', '۹',
            ],
            'گروهِ چندواحده فقط از شروعِ دوره‌ی ساکن' => [
                'MessengerHistoryPolicyTest', 'test_a_unit_group_shows_only_messages_from_the_new_residents_tenure', '۹',
            ],
            '🔴 بازگشتِ بخشیِ قبض از تازه‌ترین پرداخت‌کننده و تا سقفِ سهمش' => [
                'WalletOwnershipTest', 'test_a_partial_refund_goes_to_the_latest_payer_first_and_never_beyond_their_share', '۹',
            ],
            '🔴 توکنِ دعوت نه در لاگ، نه در حسابرسی، نه در گزارشِ خطا' => [
                'SecureInvitationTest', 'test_the_token_never_reaches_logs_audit_or_error_reports', '۹',
            ],
            '🔴 مدیرِ مجتمعِ A کاربرِ مجتمعِ B را عوض نمی‌کند' => [
                'MembershipLifecycleTest', 'test_a_manager_cannot_modify_another_complexes_users', '۹',
            ],
            'صاحبِ حساب رمزش را خودش عوض می‌کند' => [
                'MembershipLifecycleTest', 'test_the_account_owner_still_changes_their_own_password', '۹',
            ],
            'ادمینِ کل برای مجتمعِ موجود دوباره دعوتِ مدیر می‌فرستد' => [
                'MembershipLifecycleTest', 'test_the_super_admin_reissues_a_manager_invitation_for_an_existing_complex', '۹',
            ],
            '🔴 عملیاتِ حساسِ ادمینِ کل ردِ حسابرسی دارند' => [
                'MembershipLifecycleTest', 'test_sensitive_super_admin_operations_are_audited', '۹',
            ],
            '🔴 تعلیقِ مجتمع هیچ سابقه‌ای را دست نمی‌زند؛ حذفِ سخت نیست' => [
                'MembershipLifecycleTest', 'test_suspending_a_complex_keeps_every_record_and_no_hard_delete_exists', '۹',
            ],
            '🔴 مدیر مسیرهای ادمینِ کل را نمی‌زند' => [
                'MembershipLifecycleTest', 'test_a_manager_cannot_use_super_admin_routes', '۹',
            ],
            '🔴 یک کیف برای هر حساب: یک مانده در هر زمینه‌ی مجتمع' => [
                'WalletOwnershipTest', 'test_one_account_sees_one_balance_in_every_complex_context', '۹',
            ],
            '🔴 پس از پیوستن به مجتمعِ دیگر همان کیف قبضِ مجاز را می‌پردازد' => [
                'WalletOwnershipTest', 'test_after_joining_another_complex_the_same_wallet_pays_its_bills', '۹',
            ],
            '🔴 هیچ شناسه‌ای از کلاینت کیفِ کسِ دیگری را باز نمی‌کند؛ مدیر مانده‌ی شخصی نمی‌بیند' => [
                'WalletOwnershipTest', 'test_no_identifier_from_the_client_opens_someone_elses_wallet', '۹',
            ],
            'مدیر فقط گردشِ عضو در همین مجتمع را می‌بیند' => [
                'WalletOwnershipTest', 'test_a_manager_sees_only_this_complexes_rows_of_a_member', '۹',
            ],
            '🔴 پرداختِ تکراری با یک کلید یک برداشت است' => [
                'WalletOwnershipTest', 'test_a_repeated_payment_with_the_same_key_is_recorded_once', '۹',
            ],
            '🔴 تکرارِ هم‌زمانِ یک پرداخت در چند پردازه یک برداشت است' => [
                'WalletConcurrencyTest', 'test_concurrent_repeats_of_one_payment_debit_once', '۹',
            ],
            'مهاجرتِ یک‌کیفی هیچ ردیفی را عوض نمی‌کند' => [
                'WalletOwnershipTest', 'test_the_single_wallet_migration_keeps_every_row_and_the_total', '۹',
            ],
            '🔴 تغییرِ شماره با کدِ تازه؛ دستگاه‌ها باطل' => [
                'PhoneChangeTest', 'test_the_owner_changes_their_phone_with_a_fresh_code', '۹',
            ],
            '🔴 شماره‌ی حسابِ دیگر نه گرفته می‌شود نه ادغام' => [
                'PhoneChangeTest', 'test_another_accounts_phone_is_never_taken_or_merged', '۹',
            ],
            '🔴 سقفِ تلاشِ کد در تأییدِ ناموفق گم نمی‌شود' => [
                'PhoneChangeTest', 'test_the_attempt_cap_survives_failed_confirmations', '۹',
            ],
            '🔴 دو تأییدِ هم‌زمانِ یک شماره: یک صاحب' => [
                'PhoneChangeConcurrencyTest', 'test_two_accounts_confirming_one_number_at_once_never_share_it', '۹',
            ],
            'ورود و کدِ ورود پس از تغییر با شماره‌ی تازه' => [
                'PhoneChangeTest', 'test_login_and_recovery_follow_the_new_phone', '۹',
            ],
            '🔴 مدیر شماره و رمزِ ساکن را عوض نمی‌کند (هیچ مسیری)' => [
                'PhoneChangeTest', 'test_a_manager_cannot_change_a_residents_phone_or_password', '۹',
            ],
            '🔴 بازیابیِ حساب بی تأییدِ ادمینِ کل هیچ‌چیز را عوض نمی‌کند' => [
                'AccountRecoveryTest', 'test_a_verified_request_changes_nothing_until_approved', '۹',
            ],
            '🔴 تأییدِ بازیابی همه‌ی نشست‌ها و دستگاه‌ها را می‌بندد' => [
                'AccountRecoveryTest', 'test_approval_moves_the_phone_and_ends_every_session', '۹',
            ],
            '🔴 ادمینِ کل بازیابیِ حسابِ خودش را تأیید نمی‌کند' => [
                'AccountRecoveryTest', 'test_a_super_admin_cannot_approve_their_own_recovery', '۹',
            ],
            'گروهِ عمومی و گروهِ واحدهای مدیر دو نوعِ جدا' => [
                'MessengerHistoryPolicyTest', 'test_the_public_group_and_a_managers_unit_group_are_different_kinds', '۹',
            ],
            '🔴 نخوانده‌ی ساکنِ تازه پیامِ خصوصیِ پیش از او را نمی‌شمارد' => [
                'MessengerHistoryPolicyTest', 'test_unread_counts_follow_the_same_boundary', '۹',
            ],
            'ساکنِ رفته گروهِ عمومی را دیگر نمی‌خواند' => [
                'MessengerHistoryPolicyTest', 'test_a_former_member_no_longer_reads_the_public_group', '۹',
            ],
            '🔴 ۹/۷: جاروی کاملِ BOLA/IDOR روی همه‌ی مسیرها با چهار مهاجم' => [
                'BolaSweepTest', 'test_no_attacker_reaches_another_tenants_or_accounts_resources', '۹',
            ],
            '🔴 ۹/۷: زمینه‌ی نامعتبر به مجتمعِ دیگر برنمی‌گردد' => [
                'BolaSweepTest', 'test_an_invalid_context_never_falls_back_to_another_complex', '۹',
            ],
            '۹/۷: سیاهه‌ی مجوزِ مسیرها با روتر می‌خواند' => [
                'BolaSweepTest', 'test_the_inventory_document_lists_every_route', '۹',
            ],
            '۹/۷: مسیرهای قدیمیِ /api همان محافظ‌های v1' => [
                'BolaSweepTest', 'test_legacy_aliases_carry_the_same_guards', '۹',
            ],
            '🔴 ۹/۸: Preview روی پایگاه‌داده‌ی بی‌برچسب سرویس نمی‌دهد' => [
                'PreviewEnvironmentTest', 'test_a_preview_on_an_unmarked_database_serves_nothing', '۹',
            ],
            '🔴 ۹/۸: محصول روی پایگاه‌داده‌ی Preview سرویس نمی‌دهد' => [
                'PreviewEnvironmentTest', 'test_production_on_a_preview_database_serves_nothing', '۹',
            ],
            '🔴 ۹/۸: Preview به پایگاه‌داده‌ی محصول وصل نمی‌شود' => [
                'PreviewEnvironmentTest', 'test_preview_refuses_to_run_on_the_production_database_name', '۹',
            ],
            '۹/۸: نگهبانِ Preview در محصول یک کوئری، در آزمون هیچ' => [
                'PreviewEnvironmentTest', 'test_the_boundary_check_costs_one_query_in_production_and_none_elsewhere', '۹',
            ],
            '۹/۸: Preview بی کلیدِ دسترسی، کش و کوکیِ جدا سرویس نمی‌دهد' => [
                'PreviewEnvironmentTest', 'test_preview_needs_an_access_key_and_its_own_cache_and_cookie', '۹',
            ],
            '۹/۸: کلیدِ دسترسی جلوی همه‌ی درخواست‌های Preview' => [
                'PreviewEnvironmentTest', 'test_the_access_key_gates_every_request', '۹',
            ],
            '🔴 ۹/۸: Preview پیامکِ واقعی نمی‌فرستد' => [
                'PreviewEnvironmentTest', 'test_preview_never_sends_real_sms_and_shows_the_code_instead', '۹',
            ],
            '🔴 ۹/۸: Preview درگاهِ واقعی به کار نمی‌برد' => [
                'PreviewEnvironmentTest', 'test_preview_never_uses_a_real_payment_gateway', '۹',
            ],
            '۹/۸: Preview بی ابزارِ تحلیل و بی ایندکس' => [
                'PreviewEnvironmentTest', 'test_preview_has_no_analytics_and_no_indexing', '۹',
            ],
            '۹/۸: نوارِ Preview فقط روی Preview' => [
                'PreviewEnvironmentTest', 'test_the_banner_shows_on_preview_and_never_in_production', '۹',
            ],
            '🔴 ۹/۸: preview:reset پایگاه‌داده‌ی بی‌برچسبِ دارای کاربر را پاک نمی‌کند' => [
                'PreviewResetTest', 'test_reset_never_wipes_an_unmarked_database_with_users', '۹',
            ],
            '🔴 ۹/۸: preview:reset روی محصول اجرا نمی‌شود' => [
                'PreviewResetTest', 'test_reset_refuses_on_production', '۹',
            ],
            '۹/۸: preview:reset چهار حسابِ آزمایشی را می‌سازد' => [
                'PreviewResetTest', 'test_reset_builds_a_marked_database_with_the_four_test_accounts', '۹',
            ],
            '🔴 ۹/۸: استقرارِ محصول پیش از مهاجرت بکاپ می‌گیرد و می‌سنجد' => [
                'DeployScriptSafetyTest', 'test_production_deploys_back_up_and_verify_before_any_migration', '۹',
            ],
            '۹/۸: reset-preview فقط روی Preview' => [
                'DeployScriptSafetyTest', 'test_the_preview_reset_is_refused_anywhere_but_preview', '۹',
            ],
            '🔴 ۹/۹: گذارِ دعوت همه‌ی سابقه‌ی حساب را نگه می‌دارد' => [
                'TransitionDataIntegrityTest', 'test_the_invitation_path_keeps_every_record_of_an_account_with_history', '۹',
            ],
            '🔴 ۹/۹: گذارِ درخواستِ پیوستن همه‌ی سابقه‌ی حساب را نگه می‌دارد' => [
                'TransitionDataIntegrityTest', 'test_the_join_request_path_keeps_every_record_of_an_account_with_history', '۹',
            ],
            '🔴 ۹/۹: گذارِ پرداخت ← مدیر همه‌ی سابقه‌ی حساب را نگه می‌دارد' => [
                'TransitionDataIntegrityTest', 'test_the_payment_path_keeps_every_record_of_an_account_with_history', '۹',
            ],
            '🔴 ۹/۹: شکست در میانه‌ی گذار به مدیر وضعیتِ نیمه نمی‌سازد' => [
                'TransitionDataIntegrityTest', 'test_a_failure_halfway_to_manager_leaves_no_half_state_and_the_retry_completes_once', '۹',
            ],
            '🔴 ۹/۹: شکست در میانه‌ی گذار به واحد وضعیتِ نیمه نمی‌سازد' => [
                'TransitionDataIntegrityTest', 'test_a_failure_halfway_to_a_unit_leaves_no_half_state_and_the_invitation_stays_usable', '۹',
            ],
            '۹/۹: هیچ VERIFIED ِ بی‌مدرک در گزارشِ مرحله' => [
                'Stage9VerificationTest', 'test_nothing_is_verified_without_evidence', '۹',
            ],
            '۹/۹: هر آزمونِ نام‌برده در گزارشِ مرحله وجود دارد' => [
                'Stage9VerificationTest', 'test_every_test_named_in_the_document_exists', '۹',
            ],
            '۱۰/۱: هر ۱۴۴ بندِ فایلِ معماری یک بار در نقشه' => [
                'ArchitectureEvidenceMapTest', 'test_every_one_of_the_144_items_is_mapped_exactly_once', '۱۰',
            ],
            '۱۰/۱: هیچ VERIFIED ِ بی‌آزمون در نقشه‌ی معماری' => [
                'ArchitectureEvidenceMapTest', 'test_nothing_is_verified_without_a_test', '۱۰',
            ],
            '۱۰/۱: هر بندِ باز فازِ بستنش را دارد' => [
                'ArchitectureEvidenceMapTest', 'test_every_open_item_names_the_phase_that_closes_it', '۱۰',
            ],
            '🔴 ۱۰/۲: مجتمع و نقشِ حساب فقط از دفترِ عضویت عوض می‌شوند' => [
                'MembershipLedgerTest', 'test_the_account_context_cannot_be_changed_outside_the_ledger', '۱۰',
            ],
            '🔴 ۱۰/۲: پایانِ عضویت ردیف را با زمان، پایان‌دهنده و دلیل نگه می‌دارد' => [
                'MembershipLedgerTest', 'test_ending_closes_the_row_with_who_when_and_why_and_keeps_it', '۱۰',
            ],
            '۱۰/۲: یک حساب، کلِ تاریخچه‌ی عضویتش' => [
                'MembershipLedgerTest', 'test_one_account_holds_its_whole_membership_history', '۱۰',
            ],
            '🔴 ۱۰/۲: تاریخچه‌ی عضویت با حذفِ حساب یا مجتمع پاک نمی‌شود' => [
                'MembershipLedgerTest', 'test_deleting_the_account_or_the_complex_cannot_erase_membership_history', '۱۰',
            ],
            '۱۰/۲: مهاجرت هر عضوِ موجود را و فقط او را عضو می‌کند' => [
                'MembershipLedgerTest', 'test_the_migration_backfills_every_existing_member_and_nobody_else', '۱۰',
            ],
            '۱۰/۲: ارتقا به ادمینِ کل عضویت را می‌بندد' => [
                'MembershipLedgerTest', 'test_promotion_to_super_admin_ends_the_membership', '۱۰',
            ],
            '۱۰/۲: ناهم‌خوانیِ حساب و عضویت گزارش می‌شود' => [
                'MembershipLedgerTest', 'test_the_check_command_reports_any_drift_between_account_and_membership', '۱۰',
            ],
            '🔴 ۱۰/۳: عضویتِ پایان‌یافته پشتِ سرِ حساب هیچ درخواستی را راه نمی‌دهد' => [
                'AuthorizationChainTest', 'test_a_membership_ended_behind_the_accounts_back_denies_every_request', '۱۰',
            ],
            '🔴 ۱۰/۳: نقشِ دست‌کاری‌شده روی حساب مجوز نیست' => [
                'AuthorizationChainTest', 'test_a_forged_role_on_the_account_is_not_authority', '۱۰',
            ],
            '🔴 ۱۰/۳: دادهٔ مجتمعِ دیگر در زمینه‌ی فعال دیده نمی‌شود' => [
                'AuthorizationChainTest', 'test_the_active_context_decides_whose_data_is_visible', '۱۰',
            ],
            '🔴 ۱۰/۳: تعویضِ زمینه نقش را هم جابه‌جا می‌کند' => [
                'AuthorizationChainTest', 'test_switching_context_switches_the_role_with_it', '۱۰',
            ],
            '۱۰/۳: تعویض فقط به مجتمعِ دارای عضویتِ فعال' => [
                'AuthorizationChainTest', 'test_switching_to_a_complex_without_an_active_membership_is_refused', '۱۰',
            ],
            '۱۰/۳: پایانِ یک عضویت بقیه را دست نمی‌زند' => [
                'AuthorizationChainTest', 'test_ending_one_membership_leaves_the_others_untouched', '۱۰',
            ],
            '۱۰/۳: عضوِ جای دیگر در مجتمعِ خودش دیده می‌شود' => [
                'AuthorizationChainTest', 'test_a_member_working_elsewhere_still_appears_in_the_other_complex', '۱۰',
            ],
            '۱۰/۳: سنجشِ عضویت کوئریِ تازه نمی‌سازد' => [
                'AuthorizationChainTest', 'test_the_chain_reads_the_complex_once_per_request', '۱۰',
            ],
            '🔴 ۱۰/۴: پذیرشِ دعوتِ مجتمعِ دیگر عضویتِ اول را دست نمی‌زند' => [
                'SecureInvitationTest', 'test_a_member_elsewhere_accepts_and_keeps_the_first_membership', '۱۰',
            ],
            '🔴 ۱۰/۴: تأییدِ درخواست برای عضوِ جای دیگر، عضویتِ دیگرش را نگه می‌دارد' => [
                'JoinRequestTest', 'test_approving_someone_who_joined_elsewhere_adds_a_membership_and_keeps_the_other', '۱۰',
            ],
            '۱۰/۴: دعوتِ مجتمعِ دیگر با پیوستن نمی‌میرد' => [
                'SecureInvitationTest', 'test_another_complexes_invitation_stays_alive_and_adds_a_second_membership', '۱۰',
            ],
            '۱۰/۴: مسیرهای پیوستن عضوِ مجتمع‌های دیگر را راه می‌دهند' => [
                'AuthorizationChainTest', 'test_the_joining_flows_admit_members_of_other_complexes', '۱۰',
            ],
            '۱۰/۴: /me فقط عضویت‌های خودِ حساب را می‌دهد' => [
                'AuthorizationChainTest', 'test_me_lists_the_accounts_own_memberships_for_the_switcher', '۱۰',
            ],
            '🔴 ۱۰/۵: اسکن و درخواست تا تأییدِ مدیر عضویت نیست' => [
                'PublicJoinTest', 'test_scanning_and_requesting_is_not_membership_until_the_manager_approves', '۱۰',
            ],
            '🔴 ۱۰/۵: QR ِ تازه QR ِ قبلی را همان لحظه باطل می‌کند' => [
                'PublicJoinTest', 'test_rotating_retires_the_old_qr_at_once', '۱۰',
            ],
            '🔴 ۱۰/۵: کدِ QR فقط برای مدیرِ همان مجتمع' => [
                'PublicJoinTest', 'test_only_this_complexes_manager_sees_or_changes_the_code', '۱۰',
            ],
            '🔴 ۱۰/۵: پیش‌نمایشِ QR برای حسابِ اولیه هم (قفلِ حالتِ اولیه)' => [
                'PublicJoinTest', 'test_the_preview_tells_a_guest_only_the_complex_name', '۱۰',
            ],
            '🔴 ۱۰/۵: ثبت‌نام از پیوندِ دعوت عضو می‌کند، بی داشبوردِ اولیه' => [
                'DirectInvitationRoutingTest', 'test_registering_from_the_link_makes_a_member_without_the_initial_dashboard', '۱۰',
            ],
            '🔴 ۱۰/۵: هیچ چیزِ کلاینت عضویت نمی‌سازد' => [
                'DirectInvitationRoutingTest', 'test_nothing_the_client_sends_creates_membership', '۱۰',
            ],
            '۱۰/۵: توکنِ شماره‌ی دیگر نادیده و دعوت منتظرِ صاحبش' => [
                'DirectInvitationRoutingTest', 'test_a_token_for_another_phone_is_ignored_and_the_invitation_waits_for_its_owner', '۱۰',
            ],
            '۱۰/۵: کلیدِ همگانیِ OTP کدِ ثبت‌نام را برنمی‌دارد' => [
                'DirectInvitationRoutingTest', 'test_the_platform_otp_switch_does_not_let_registration_skip_its_code', '۱۰',
            ],
            '🔴 ۱۰/۵: عضوِ دو مجتمع سومی را می‌خرد و هیچ چیزِ آن دو جابه‌جا نمی‌شود' => [
                'MemberComplexPurchaseTest', 'test_a_member_of_two_complexes_buys_a_third_and_nothing_else_moves', '۱۰',
            ],
            '🔴 ۱۰/۵: مدیر بودن در مجتمعِ خریده‌شده دسترسی‌ای در مجتمع‌های دیگر نمی‌دهد' => [
                'MemberComplexPurchaseTest', 'test_the_buyer_switches_to_the_new_complex_and_its_manager_role_stays_there', '۱۰',
            ],
            '🔴 ۱۰/۵: بی تأییدِ درگاه هیچ مجتمعی ساخته نمی‌شود' => [
                'MemberComplexPurchaseTest', 'test_nothing_is_built_without_the_gateways_own_confirmation', '۱۰',
            ],
            '🔴 ۱۰/۵: بازگشتِ هم‌زمانِ خریدارِ عضو یک مجتمع می‌سازد' => [
                'CheckoutConcurrencyTest', 'test_a_member_buyers_concurrent_callbacks_build_one_complex_and_leave_the_home_alone', '۱۰',
            ],
            '🔴 ۱۰/۵: تأییدِ مدیر زمینه‌ی متقاضیِ عضوِ جای دیگر را جابه‌جا نمی‌کند' => [
                'MemberComplexPurchaseTest', 'test_a_managers_approval_does_not_move_a_member_working_elsewhere', '۱۰',
            ],
            '۱۰/۵: تمدید هنوز فقط کارِ مدیرِ همان مجتمع' => [
                'MemberComplexPurchaseTest', 'test_renewal_is_still_the_current_complexes_managers_alone', '۱۰',
            ],
            '🔴 ۱۰/۶: تعلیق در یک مجتمع حساب و مجتمعِ دیگر را نمی‌بندد' => [
                'MembershipSuspensionTest', 'test_suspending_in_one_complex_leaves_the_account_and_the_other_complex_untouched', '۱۰',
            ],
            '🔴 ۱۰/۶: نشستِ باز مجتمعِ معلق را در همان درخواستِ بعدی از دست می‌دهد' => [
                'MembershipSuspensionTest', 'test_an_open_session_loses_the_suspended_complex_on_its_very_next_request', '۱۰',
            ],
            '🔴 ۱۰/۶: عضوِ معلق با دعوت، درخواست یا QR برنمی‌گردد' => [
                'MembershipSuspensionTest', 'test_a_suspended_member_cannot_slip_back_in_by_invitation_request_or_qr', '۱۰',
            ],
            '🔴 ۱۰/۶: بستنِ حساب در کلِ سکو فقط کارِ ادمینِ کل' => [
                'MembershipSuspensionTest', 'test_blocking_the_whole_account_is_still_the_super_admins_alone', '۱۰',
            ],
            '۱۰/۶: تعلیق حساب، واحد، قبض و سابقه را نگه می‌دارد' => [
                'MembershipSuspensionTest', 'test_suspension_keeps_the_account_unit_bills_and_history', '۱۰',
            ],
            '۱۰/۶: مهاجرت فقط غیرفعالیِ مدیر را به تعلیق تبدیل می‌کند' => [
                'MembershipSuspensionTest', 'test_the_migration_turns_a_managers_old_deactivation_into_a_suspension_only', '۱۰',
            ],
            '🔴 ۱۰/۷: تصویرِ پروفایل بی metadata ِ فایلِ اصلی' => [
                'ProfilePhotoTest', 'test_the_photo_is_re_encoded_square_and_without_the_originals_metadata', '۱۰',
            ],
            '🔴 ۱۰/۷: تصویر فقط برای هم‌مجتمعی‌ها' => [
                'ProfilePhotoTest', 'test_only_people_who_share_a_complex_see_the_photo', '۱۰',
            ],
            '🔴 ۱۰/۷: مدیر ایمیل و کدِ ملیِ حساب را بازنویسی نمی‌کند' => [
                'ProfilePhotoTest', 'test_a_manager_cannot_rewrite_the_accounts_email_or_national_id', '۱۰',
            ],
            '🔴 ۱۰/۷: ورودی‌های شارژ از «اطلاعاتِ واحد» نوشته نمی‌شوند' => [
                'CommunityAndUnitInformationTest', 'test_billing_inputs_are_never_written_here_not_even_by_the_manager', '۱۰',
            ],
            '۱۰/۷: پیام‌رسان تصویرِ فعلیِ نویسنده را نشان می‌دهد' => [
                'ProfilePhotoTest', 'test_the_messenger_shows_the_authors_current_photo_not_a_copy', '۱۰',
            ],
            '۱۰/۷: شمارش‌های مجتمع از داده‌اند و با آن عوض می‌شوند' => [
                'CommunityAndUnitInformationTest', 'test_the_counts_come_from_the_data_and_follow_it', '۱۰',
            ],
            '۱۰/۷: اطلاعاتِ واحد فقط برای مدیر و ساکنانِ فعلیِ همان واحد' => [
                'CommunityAndUnitInformationTest', 'test_another_units_resident_a_former_tenant_or_another_complex_get_nothing', '۱۰',
            ],
            '🔴 ۱۰/۸: جست‌وجوی مدیر نتیجه‌ی مجتمعِ دیگر را برنمی‌گرداند' => [
                'ManagerSearchTest', 'test_the_manager_of_another_community_never_receives_this_communitys_results', '۱۰',
            ],
            '🔴 ۱۰/۸: مرزِ مجتمع در خودِ کمکِ جست‌وجو' => [
                'ManagerSearchTest', 'test_the_lookup_itself_never_crosses_the_community', '۱۰',
            ],
            '۱۰/۸: «مالک/مستأجر واحد N» و ارقامِ فارسی' => [
                'ManagerSearchTest', 'test_the_four_examples_of_the_spec_find_the_right_people_in_the_managers_own_community', '۱۰',
            ],
            '۱۰/۸: گروهِ جست‌وجو صفحه‌بندی دارد، بی هم‌پوشانی' => [
                'ManagerSearchTest', 'test_a_group_is_paginated_without_gaps_or_overlap', '۱۰',
            ],
            '🔴 ۱۰/۸: جست‌وجوی پیام‌رسان فقط از میانِ دیده‌ها' => [
                'ConversationSearchTest', 'test_a_resident_searches_only_among_what_they_already_see', '۱۰',
            ],
            '۱۰/۸: جست‌وجوی گفت‌وگوها مستأجرِ رفته و مجتمعِ دیگر را نمی‌یابد' => [
                'ConversationSearchTest', 'test_the_former_tenant_and_the_other_community_are_never_found', '۱۰',
            ],
            '🔴 ۱۰/۹: مدیر «مالک ↔ مستأجر» و «ذخیره‌شده»ِ دیگران را از هیچ راهی نمی‌بیند' => [
                'MessengerStructureTest', 'test_the_manager_never_reaches_a_pair_or_someone_elses_saved_messages', '۱۰',
            ],
            '🔴 ۱۰/۹: هر عضو گفت‌وگوی خودش با مدیریت؛ هم‌واحد آن را نمی‌خواند' => [
                'MessengerStructureTest', 'test_owner_and_tenant_each_get_their_own_manager_chat_and_one_together', '۱۰',
            ],
            '🔴 ۱۰/۹: بستنِ پیام‌رسان در یک مجتمع مجتمعِ دیگر را نمی‌بندد' => [
                'MembershipMessagingTest', 'test_muting_in_one_community_leaves_the_account_and_the_other_community_untouched', '۱۰',
            ],
            '🔴 ۱۰/۹: برگشتِ مهاجرت گفت‌وگوی خصوصی را جلوی مدیر نمی‌گذارد' => [
                'MembershipMessagingTest', 'test_rolling_back_removes_private_conversations_instead_of_exposing_them', '۱۰',
            ],
            '۱۰/۹: چرخه‌ی کاملِ مالک، مستأجر، رفتن و مستأجرِ تازه' => [
                'MessengerStructureTest', 'test_the_lifecycle_of_the_spec_from_owner_to_a_new_tenant', '۱۰',
            ],
            '۱۰/۹: بایگانیِ رشته‌ی مشترکِ واحد فقط‌خواندنی است' => [
                'MessengerStructureTest', 'test_the_old_shared_unit_thread_is_kept_read_only_for_whoever_saw_it', '۱۰',
            ],
            '۱۰/۹: شمارِ گروه از عضویتِ فعال' => [
                'GroupInfoTest', 'test_the_counts_come_from_active_memberships_and_follow_them', '۱۰',
            ],
            'مدیر از درخواستِ عضویت با اعلانِ داشبورد باخبر می‌شود' => [
                'JoinRequestTest', 'test_the_complexes_managers_get_a_dashboard_notification_once', '۹',
            ],
            '🔴 ساکنِ تازه رشته‌ی واحدِ ساکنِ قبلی را نمی‌خواند' => [
                'MembershipLifecycleTest', 'test_the_next_tenant_does_not_read_the_previous_tenants_thread', '۹',
            ],
            '🔴 «حذفِ عضو» ِ ادمینِ کل غیرفعال‌سازی است و هیچ داده‌ای نمی‌رود' => [
                'MembershipLifecycleTest', 'test_the_super_admin_delete_deactivates_and_keeps_everything', '۹',
            ],
            'ادمینِ کل مجتمع را با دعوتِ مدیر می‌سازد' => [
                'SecureInvitationTest', 'test_the_super_admin_creates_a_complex_with_a_manager_invitation', '۹',
            ],
            '۱۵ مقاله‌ی واقعی و اجرای دوباره‌ی بی‌اثر' => [
                'BlogImporterTest', 'test_fifteen_real_articles_are_imported_and_a_second_run_changes_nothing', '۷',
            ],
            'تاریخِ مقاله‌ها پخش در امسال و پارسال' => [
                'BlogImporterTest', 'test_dates_are_spread_over_this_year_and_last_and_none_is_in_the_future', '۷',
            ],
            'زمانِ مطالعه از حجمِ واقعی' => [
                'BlogImporterTest', 'test_reading_time_follows_the_real_length', '۷',
            ],
            'پیوندِ داخلیِ مقاله‌ها سالم' => [
                'BlogImporterTest', 'test_internal_links_point_to_real_articles_and_real_pages', '۷',
            ],
            '🔴 هرسِ فایل‌های یتیم تصویرِ بلاگ را پاک نمی‌کند' => [
                'BlogImporterTest', 'test_pruning_orphan_uploads_keeps_every_blog_image', '۷',
            ],
            '🔴 منبعِ کیفِ پول برچسبِ فارسی دارد' => [
                'WalletLedgerTest', 'test_every_wallet_source_has_a_persian_label', '۷',
            ],
        ];
    }

    #[DataProvider('fixes')]
    public function test_every_phase_fix_has_a_living_guard(string $class, string $method, string $phase): void
    {
        $path = base_path("tests/Feature/{$class}.php");

        $this->assertFileExists(
            $path,
            "محافظِ فازِ {$phase} («{$class}») وجود ندارد.",
        );

        /*
         * ⚠️ متن خوانده می‌شود و **کامنت‌ها پاک می‌شوند**.
         *
         * 🔴 همان تلهٔ تکرارشوندهٔ این پروژه: نامِ متد در توضیحِ بالای خودش
         * هم می‌آید. بدونِ پاک‌سازی، حذفِ خودِ متد این تست را قرمز نمی‌کرد.
         */
        $source = (string) file_get_contents($path);
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $source);

        $this->assertStringContainsString(
            "function {$method}(",
            $code,
            "محافظِ فازِ {$phase} حذف شده: {$class}::{$method}",
        );
    }

    /**
     * ⚠️ هر فاز باید دستِ‌کم یک محافظ داشته باشد.
     *
     * 🔴 بدونِ این، حذفِ کاملِ ردیف‌های یک فاز از فهرستِ بالا تستی را قرمز
     * نمی‌کرد — و آن‌وقت این نقشه با خالی‌شدن، سبزتر می‌شد.
     */
    public function test_no_phase_is_missing_from_the_map(): void
    {
        $phases = array_values(array_unique(array_column(self::fixes(), 2)));
        // عددی، نه رشته‌ای — «۱۰» میانِ «۱» و «۲» نیفتد
        $latin = fn (string $p) => (int) strtr($p, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        usort($phases, fn (string $a, string $b) => $latin($a) <=> $latin($b));

        $this->assertSame(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '۱۰'],
            $phases,
            '⚠️ فازی از نقشهٔ رگرسیون افتاده است.',
        );
    }

    /**
     * 🔴 فاز ۵ عمداً اینجا نیست — و این را باید صریح گفت.
     *
     * محافظ‌هایش در `tests/js` هستند (نرمال‌سازیِ کلیدِ کش و پاک‌سازیِ کشِ
     * خروج)، و این کلاس فقط تست‌های PHP را می‌بیند.
     *
     * ⚠️ بدونِ این ادعا، نبودنِ فاز ۵ به‌نظر یک فراموشی می‌آمد.
     */
    public function test_the_javascript_side_guards_exist_too(): void
    {
        foreach ([
            'tests/js/serviceWorkerPolicy.test.ts' => 'نرمال‌سازیِ کلیدِ کش',
            'tests/js/logoutCacheCleanup.test.ts' => 'پاک‌سازیِ کشِ خروج',
        ] as $path => $what) {
            $this->assertFileExists(base_path($path), "محافظِ «{$what}» (فاز ۵) وجود ندارد.");
        }
    }

    /**
     * محافظ‌های JSِ مرحلهٔ پنجم (فازهای ۲ تا ۹).
     *
     * 🔴 تا فاز ۷ این‌ها هیچ‌جای نقشه نبودند: پنج فاز محافظِ تازه ساخته بود
     * و حذفِ هرکدام هیچ تستی را قرمز نمی‌کرد. مثلِ ردیف‌های PHP، هم فایل و
     * هم نامِ `describe`ی که ادعا شده باید باشد — فایلِ خالی کافی نیست.
     */
    public function test_the_stage_five_javascript_guards_exist(): void
    {
        $guards = [
            'importBoundaries' => ['۲', 'مرزهای وابستگی'],
            'stateBoundaries' => ['۲', 'مرزهای حالت'],
            'componentResponsibility' => ['۲', 'تفکیکِ مسئولیت'],
            'typeSafety' => ['۳', 'ایمنیِ نوع'],
            'externalInput' => ['۳', 'localStorage'],
            'formsAndRoutes' => ['۴', 'پارامترِ آدرس'],
            'keyboardReach' => ['۵', 'کشوی منوی موبایل'],
            'privacySurface' => ['۷', 'لینکِ پنجره‌ی تازه'],
            'maintenanceMode' => ['۹', 'چه کسی واکنش را ثبت می‌کند'],
            'dataSurfaces' => ['۶', 'صفحه‌ی واحدها با بیش از بیست واحد'],
            'browserCompat' => ['۸', 'وقتی هیچ راهی نیست'],
            'sessionLifecycle' => ['۱۰', 'پیامِ کانالِ بینِ تب‌ها'],
            'blog' => ['۱۱', 'شکستِ کپی را هم می‌گوید'],
            'messengerThread' => ['۱۲', 'دوباره همان client_id'],
            'messengerEnrichment' => ['۱۴', 'نشانه‌گذاریِ HTML به‌صورتِ متن'],
            // مرحلهٔ ۷ · فاز ۱: تم، حالتِ فیلد، تاریخچه پس از ورود، ۴۰۴ِ داشبورد
            'globalBehaviour' => ['۷/۱', 'خطا و «معتبر» هرگز با هم نمی‌نشینند'],
            // مرحلهٔ ۷ · فاز ۳: جابه‌جاییِ صفحه و تصویرِ بالای صفحه‌ی ورود
            'loadExperience' => ['۷/۳', 'سایدبار در اولین رندر عرضش را انیمیت نمی‌کند'],
            // مرحلهٔ ۷ · فاز ۴: فرمِ بازخورد، چرخ، محتوای سنجیده، صفِ ادمین
            'supportPage' => ['۷/۴', 'Enter و Space ربع را انتخاب می‌کنند'],
            // پیگیریِ فاز ۳: زیرمجموعه‌ی فونت هیچ نویسه‌ی به‌کاررفته‌ای را گم نمی‌کند
            'fontSubset' => ['۷/۳', 'خواننده‌ی cmap خودش درست کار می‌کند'],
            // مرحلهٔ ۷ · فاز ۵: لنگرِ بخشِ تنبل، فوتر، «تور پنل»، پیشنهادِ نصب، ادعای ساخته‌نشده
            'homePage' => ['۷/۵', 'هیچ متنی قابلیتِ ساخته‌نشده را وعده نمی‌دهد'],
            // مرحلهٔ ۷ · فاز ۵: باگِ ‎۶۰۰–۷۶۸ (آستانه‌ی برگر = آستانه‌ی پیوندها)
            'HomeNavbar' => ['۷/۵', 'برگر و پنلش دقیقاً همان‌جا پنهان می‌شوند که پیوندها ظاهر می‌شوند'],
            // مرحلهٔ ۷ · فاز ۶: قوانین در پنجره‌ی همان صفحه، نه ناوبریِ تازه‌ای که اپِ نصب‌شده برباید
            'termsDialog' => ['۷/۶', 'هیچ پنجره یا تبِ تازه‌ای نمی‌خواهد'],
            // مرحلهٔ ۷ · فاز ۷: ناوبریِ بی‌بارگذاریِ بلاگ — رقابت، خطا، جست‌وجوی نامعتبر
            'blogNavigation' => ['۷/۷', 'رقابت: پاسخِ دیررسیده‌ی کلیکِ اول روی کلیکِ دوم نمی‌نشیند'],
            // مرحلهٔ ۸ · فاز ۱: هیچ تصویرِ محتوایی از سایتِ دیگر کشیده نمی‌شود
            'selfHostedImages' => ['۸/۱', 'هیچ تصویری از سایتِ دیگری کشیده نمی‌شود'],
            // مرحلهٔ ۸ · فاز ۱: ثبت‌نام‌کننده‌ی اولیه تجربه‌ی جدای خودش را دارد، نه پوسته‌ی ساکن
            'registrantExperience' => ['۸/۱', 'مالِ این حالت نیست و به داشبوردِ خودش برمی‌گردد'],
            // مرحلهٔ ۸ · فاز ۲: «نصب است؟» فقط از سیگنالِ واقعی؛ پیشنهاد با هر بارگذاری برنمی‌گردد
            'installState' => ['۸/۲', 'fullscreen نه: کروم در F11'],
            // مرحلهٔ ۸ · فاز ۳: تصویرِ بار‌نشده متنِ خودش را می‌گیرد و گزارش می‌شود
            'blogImages' => ['۸/۳', 'گزارش می‌شود — یک بار برای هر نشانی'],
            // مرحلهٔ ۸ · فاز ۵: فهرستِ انتخابیِ سفارشی با کیبوردِ select ِ بومی
            'selectMenu' => ['۸/۵', 'Home/End، Esc بدونِ تغییر، و Tab انتخاب می‌کند و می‌رود'],
            // مرحلهٔ ۹ · فاز ۳: فرمِ پروفایلِ مشترک دو بخش می‌فرستد و `name` را نه
            'profileName' => ['۹/۳', 'دو بخش را می‌فرستد و `name` را نه'],
            // نسخه‌ی جدای فرمِ بازخورد (بودجه‌ی باندل) از قاعده‌ی مشترک جدا نمی‌افتد
            'personName' => ['۹/۳', 'الگوی هر دو نسخه یکی است'],
            // مرحلهٔ ۹ · فاز ۵: خریدِ حسابِ اولیه فقط آنلاین؛ مبلغ از سرور
            'registrantPurchase' => ['۹/۵', 'فقط slug و نام و نوع را به درگاه می‌فرستد — مبلغ نه'],
            // مرحلهٔ ۹ · فاز ۶: دعوت نه نام می‌فرستد نه رمز؛ توکن در بدنه، نه در نشانی
            'secureInvitation' => ['۹/۶', 'فقط شماره، نقش و واحد می‌رود — نه نام، نه رمز'],
            // اصلاحاتِ پس از ۹/۶: تلاشِ دومِ E2E شکستِ اول را پنهان نمی‌کند
            'e2eEvidence' => ['۹/۶', 'تستی که فقط در تلاشِ دوم سبز شد کارِ CI را قرمز می‌کند'],
            // مرحلهٔ ۹: یک قصد = یک کلیدِ تکرار؛ تغییرِ شماره فقط شماره و کد می‌فرستد
            'walletPayment' => ['۹', 'پس از قطعیِ شبکه، تلاشِ دوباره همان کلید را می‌فرستد'],
            'phoneChange' => ['۹', 'شماره‌ی تازه و سپس کد می‌رود'],
            // مرحلهٔ ۱۰ · فاز ۴: تعویضِ مجتمع — سرور اول، بعد کش، تب‌ها و ناوبریِ کامل؛ کشِ وابسته به زمینه
            'accountContext' => ['۱۰/۴', 'همان کلید در دو مجتمع دو ورودیِ جدا است'],
            'memberInvitations' => ['۱۰/۴', 'پذیرفتن به زمینه‌ی تازه می‌برد'],
            // مرحلهٔ ۱۰ · فاز ۵: QR ِ عمومی و ثبت‌نام از پیوندِ دعوت
            'qrCode' => ['۱۰/۵', 'ماژول‌به‌ماژول همان OpenCV است'],
            'publicJoin' => ['۱۰/۵', 'درخواست فقط با کلیکِ خودش می‌رود'],
            'memberPurchase' => ['۱۰/۵', 'بی عضویتِ فعال هیچ تعویضی نمی‌سازد'],
            // مرحلهٔ ۱۰ · فاز ۶: عضویتِ معلق دیده می‌شود، تعویض به آن بسته است
            'membershipSuspension' => ['۱۰/۶', 'مجتمعِ معلق دیده می‌شود'],
            // مرحلهٔ ۱۰ · فاز ۷: تصویرِ canonical و دو صفحه‌ی «اطلاعات»
            'profileAndInformation' => ['۱۰/۷', 'هرگز فرستاده نمی‌شوند'],
            // مرحلهٔ ۱۰ · فاز ۸: گروهِ بریده راه به باقی دارد؛ تاریخچه‌ی جست‌وجو مالِ حساب و مجتمع
            'managerSearch' => ['۱۰/۸', 'مالِ همین حساب در همین مجتمع است'],
            // مرحلهٔ ۱۰ · فاز ۹: اطلاعاتِ گروه، دلیلِ فقط‌خواندنی و گفت‌وگو با یک عضو
            'messengerStructure' => ['۱۰/۹', 'خصوصی با یک عضو است، نه با واحد'],
        ];

        // 🔴 مثلِ ردیف‌های PHP، این فهرست هم فقط بزرگ می‌شود (خرابکاریِ فاز ۸)
        $this->assertGreaterThanOrEqual(28, count($guards), 'محافظی از فهرستِ JS حذف شده.');

        foreach ($guards as $file => [$phase, $marker]) {
            $path = base_path("tests/js/{$file}.test.".(file_exists(base_path("tests/js/{$file}.test.tsx")) ? 'tsx' : 'ts'));

            $this->assertFileExists($path, "محافظِ JSِ فازِ {$phase} («{$file}») وجود ندارد.");
            $this->assertStringContainsString($marker, (string) file_get_contents($path), "محافظِ فازِ {$phase} تهی شده: {$file}");
        }
    }

    /**
     * 🔴 نقشه فقط بزرگ می‌شود، کوچک نه.
     *
     * ─── این را خرابکاری خواست ─────────────────────────────────────────────
     * ⚠️ حذفِ **یک ردیف** از نقشه هیچ تستی را قرمز نمی‌کرد: تستِ «هیچ فازی
     * جا نیفتاده» فقط می‌سنجد که فاز **به‌کلی** غایب نباشد، و هر فاز چند
     * ردیف دارد.
     *
     * یعنی می‌شد محافظِ یک اصلاح را بی‌سروصدا از نقشه برداشت و نقشه
     * همچنان سبز می‌ماند — دقیقاً همان کهنه‌شدنِ خاموشی که این فایل برای
     * بستنش ساخته شده.
     *
     * ⚠️ عدد **کف** است نه دقیق: افزودنِ اصلاحِ تازه باید آزاد باشد.
     */
    public function test_the_map_never_shrinks(): void
    {
        $this->assertGreaterThanOrEqual(
            148,
            count(self::fixes()),
            'ردیفی از نقشهٔ رگرسیون حذف شده؛ یعنی اصلاحی محافظش را از دست داده.',
        );
    }
}
