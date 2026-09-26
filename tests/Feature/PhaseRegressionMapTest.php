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
        $phases = array_unique(array_column(self::fixes(), 2));
        sort($phases);

        $this->assertSame(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
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
        ];

        // 🔴 مثلِ ردیف‌های PHP، این فهرست هم فقط بزرگ می‌شود (خرابکاریِ فاز ۸)
        $this->assertGreaterThanOrEqual(13, count($guards), 'محافظی از فهرستِ JS حذف شده.');

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
            88,
            count(self::fixes()),
            'ردیفی از نقشهٔ رگرسیون حذف شده؛ یعنی اصلاحی محافظش را از دست داده.',
        );
    }
}
