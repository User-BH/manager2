# گزارشِ نهاییِ فایلِ معماری — مرحلهٔ ۱۰ · فاز ۱۵

> بندهای ۱۳۵ (معیارهای پذیرش)، ۱۳۶ (Definition of Done)، ۱۴۱ (اصلِ نهایی) و ۱۴۲ (خروجیِ مورد انتظار) ِ فایلِ معماری.
> منبعِ وضعیتِ هر بند [`ARCHITECTURE_EVIDENCE_MAP.md`](ARCHITECTURE_EVIDENCE_MAP.md) است؛ این سند وضعیتِ تازه‌ای
> نمی‌سازد، فقط آن‌ها را به تفکیکِ حوزه و معیار جمع می‌کند. **وضعیتِ هر حوزه دستی نوشته نمی‌شود:** ضعیف‌ترین وضعیتِ
> بندهای همان حوزه است، و `ArchitectureFinalReportTest` همین را با نقشه می‌سنجد — همان‌طور که هر معیارِ پذیرش و هر
> اصل را به بندهای VERIFIED بسته می‌داند و هر آزمونِ نام‌برده را موجود.

## خلاصه

| وضعیت                                                    | حوزه |
| -------------------------------------------------------- | ---- |
| VERIFIED                                                 | ۱۶   |
| IMPLEMENTATION READY — ENVIRONMENT VERIFICATION REQUIRED | ۲    |

دو حوزه‌ی محیطی همان دو بندِ محیطیِ نقشه‌اند: آپلودِ ۵۰۰ مگابایتی از nginx و PHP ِ سرور (بندِ ۴۸) و سنجشِ بار روی
سرورِ واقعی (بندِ ۱۲۵). هیچ‌کدام بی سرورِ واقعی بسته نمی‌شود و این‌جا هم بسته اعلام نمی‌شود. **Production Ready اعلام نمی‌شود:** دروازه‌ی بندِ ۱۴۴ (۱۰/۱۶) حکم را `CODE READY — ENVIRONMENT VERIFICATION REQUIRED` داد —
[`FINAL_PRE_PRODUCTION_AUDIT.md`](FINAL_PRE_PRODUCTION_AUDIT.md)، مرجعِ نهایی.

## سنجش‌های پایانِ مرحله (تا ۱۰/۱۴)

| سنجه                                 | نتیجه                                                                                                                    |
| ------------------------------------ | ------------------------------------------------------------------------------------------------------------------------ |
| PHPUnit ِ کامل، این ماشین            | ۲٬۵۲۷ آزمون · ۲٬۵۲۴ موفق · ۳ رد · بی شکست (پس از `de20dc6`)                                                              |
| CI ِ `de20dc6` (اجرای `38747445050`) | بک‌اند ۲٬۵۰۶ موفق · ۲۱ رد؛ فرانت ۸۵۶/۸۵۶؛ قیدهای MySQL ۶۸ موفق؛ سرتاسری (سه موتور) ۳۳۹ موفق · ۱۵ رد؛ امنیت بی آسیب‌پذیری |
| خرابکاریِ ۱۰/۱۰ تا ۱۰/۱۴             | جدولِ «خرابکاری» در پایین                                                                                                |
| اجراهای CI ِ ۱۰/۸ و ۱۰/۹             | پس از رفعِ مسدودیِ صورت‌حسابِ GitHub دوباره اجرا شدند: `4d164f2`، `decfaf4`، `6b56f43`، `c2373b5` سبز                    |
| اجراهای CI ِ ۱۰/۱۰ تا ۱۰/۱۴          | قرمز می‌مانند (تصمیمِ کارفرما): سه نقص و CVE-2026-108082 در همان کامیت‌ها؛ رفع در `aa76e04` و `de20dc6`                  |

## حوزه‌ها (بندِ ۱۴۲)

### Account Architecture

- **بندها:** ۴، ۵، ۷۴، ۸۴
- **Final Status:** VERIFIED
- **Evidence:** هر شخص یک حساب با تاریخچه‌ی کاملِ عضویت‌هایش؛ پروفایل و تصویرِ canonical که پیام‌رسان هم از همان می‌خواند؛ پایانِ عضویت حساب را نگه می‌دارد؛ خروج نشست و کشِ کلاینت را پاک می‌کند.
- **Tests:** `MembershipLedgerTest::test_one_account_holds_its_whole_membership_history`، `ProfilePhotoTest::test_a_manager_cannot_rewrite_the_accounts_email_or_national_id`، `MembershipLifecycleTest::test_removing_a_resident_keeps_the_account_and_every_record`، `AuthorizationAttackTest::test_logout_actually_ends_the_session`
- **Known Limitations:** حذفِ کاملِ حساب (حقِ فراموشی) مسیرِ کاربری ندارد؛ پایانِ عضویت جایش است.
- **Environment Dependency:** —
- **Definition of Done:** Code: `MembershipLedger`، `ProfilePhoto` · Database: `complex_memberships` با `restrict` · Authorization: داده‌ی حساب فقط از خودِ کاربر · API: `/profile`، `/me` · Tests: بالا · Adversarial: `MassAssignmentTest` · Regression: ردیف‌های ۱۰/۲ و ۱۰/۷ ِ `PhaseRegressionMapTest` · Documentation: `PROFILE_AND_INFORMATION.md`

### Community Architecture

- **بندها:** ۶، ۷، ۱۷
- **Final Status:** VERIFIED
- **Evidence:** مجتمع موجودیتِ مستقل؛ نوع فقط از سرور و فقط ادمینِ کل عوضش می‌کند؛ «اطلاعاتِ مجتمع» canonical با شمارش‌های بیرون‌آمده از داده.
- **Tests:** `ComplexTypeTest::test_a_complex_admin_cannot_change_the_type`، `CommunityAndUnitInformationTest::test_the_manager_completes_the_community_information_and_the_prompt_goes_away`، `AuthorizationAttackTest::test_a_complex_admin_cannot_reach_platform_routes`
- **Known Limitations:** —
- **Environment Dependency:** —
- **Definition of Done:** Code: `Complex`، `CommunityInformation` · Database: نوع با enum، مجتمع حذف نمی‌شود (۱۰/۱۴) · Authorization: مسیرهای `system/*` فقط ادمینِ کل · API: `complex-information` · Tests: بالا · Adversarial: `AuthorizationAttackTest` · Regression: ردیف‌های ۱۰/۷ · Documentation: `PROFILE_AND_INFORMATION.md`

### Unit Architecture

- **بندها:** ۸، ۹، ۱۰، ۹۴
- **Final Status:** VERIFIED
- **Evidence:** واحدِ canonical با دوره‌های مالکیت و سکونت؛ «اطلاعاتِ واحد» یکی میانِ داشبوردها و بی دسترسی به ورودی‌های شارژ؛ رابطه‌ی ساکن با واحد از دوره‌ی جاری.
- **Tests:** `UnitTenureTest::test_a_unit_can_have_several_owners_with_shares`، `CommunityAndUnitInformationTest::test_a_member_completes_the_unit_information_and_the_prompt_goes_away`، `FinancialInvariantTest::test_one_area_everywhere_and_no_second_fund_balance`، `AuthorizationAttackTest::test_a_resident_cannot_reach_another_residents_record`
- **Known Limitations:** —
- **Environment Dependency:** —
- **Definition of Done:** Code: `TenureService`، `UnitInformationController` · Database: `unit_user` با `restrict` (۱۰/۱۴) · Authorization: مجوز پیش از اعتبارسنجی · API: `units/{unit}/information` · Tests: بالا · Adversarial: `BolaSweepTest` · Regression: ردیف‌های ۱۰/۷ و ۱۰/۱۳ · Documentation: `PROFILE_AND_INFORMATION.md`

### Membership Lifecycle

- **بندها:** ۱۱، ۱۲، ۲۷، ۲۸، ۱۱۶
- **Final Status:** VERIFIED
- **Evidence:** عضویت موجودیتِ جدا با منبع، شروع، پایان و دلیل؛ فقط عضویتِ فعال مجوز می‌دهد؛ پایانِ عضویت حساب و هر رکورد را نگه می‌دارد؛ ساکنِ تازه رشته و قبضِ ساکنِ قبلی را نمی‌بیند.
- **Tests:** `MembershipLedgerTest::test_ending_closes_the_row_with_who_when_and_why_and_keeps_it`، `AuthorizationChainTest::test_a_membership_ended_behind_the_accounts_back_denies_every_request`، `MembershipLifecycleTest::test_the_next_tenant_does_not_read_the_previous_tenants_thread`، `MembershipLifecycleTest::test_the_next_tenant_does_not_see_the_previous_tenants_bills`
- **Known Limitations:** —
- **Environment Dependency:** `memberships:check` روی دادهٔ واقعی پس از هر مهاجرت (فرمانِ عملیاتی)
- **Definition of Done:** Code: `MembershipLedger` (تنها نویسنده) · Database: `complex_memberships` با کلیدِ یکتای فعال و `restrict` · Authorization: زنجیره‌ی ۱۰/۳ · API: `residents`، `memberships` · Tests: بالا · Adversarial: `TenantIsolationTest` · Regression: ردیف‌های ۱۰/۲ و ۱۰/۶ · Documentation: `BACKEND_STRUCTURE.md`

### Invitation System

- **بندها:** ۲۱، ۲۲، ۲۳، ۲۴، ۲۵، ۲۶، ۹۶، ۹۷، ۹۸، ۹۹، ۱۰۰
- **Final Status:** VERIFIED
- **Evidence:** QR ِ عمومی فقط درخواست می‌سازد و تا تأییدِ مدیر عضویت نیست؛ دعوتِ مستقیم با کدِ همان شماره، بی تأییدِ دوباره؛ توکنِ بلند و تصادفی که فقط هشش ذخیره می‌شود؛ ابطال، انقضا و تک‌مصرفی، حتی هم‌زمان.
- **Tests:** `JoinRequestTest::test_the_applicant_is_not_attached_before_approval`، `SecureInvitationTest::test_the_token_is_long_random_and_only_its_hash_is_stored`، `SecureInvitationTest::test_an_invitation_is_single_use`، `InvitationConcurrencyTest::test_one_invitation_accepted_concurrently_makes_one_membership`، `PublicJoinTest::test_rotating_retires_the_old_qr_at_once`
- **Known Limitations:** —
- **Environment Dependency:** پیامکِ واقعیِ کدِ دعوت (درگاهِ پیامک روی سرور)
- **Definition of Done:** Code: `Membership`، `JoinRequestController` · Database: یکتاییِ دعوتِ زنده · Authorization: فقط مدیرِ همان مجتمع · API: `invitations`، `join-requests` · Tests: بالا · Adversarial: `InvitationConcurrencyTest` روی MySQL · Regression: ردیف‌های ۱۰/۵ · Documentation: `BACKEND_STRUCTURE.md`

### Dashboard Context

- **بندها:** ۱۴، ۱۵، ۱۸، ۱۹، ۸۹، ۹۰، ۹۱، ۹۲، ۹۳
- **Final Status:** VERIFIED
- **Evidence:** مسیریابی از وضعیتِ واقعیِ سرور و زمینه‌ی فعال؛ دعوتِ مستقیم پس از ثبت‌نام به داشبوردِ واحد؛ نقش با زمینه عوض می‌شود و مدیرِ یک مجتمع در دیگری هیچ ندارد.
- **Tests:** `AuthorizationChainTest::test_switching_context_switches_the_role_with_it`، `DirectInvitationRoutingTest::test_registering_from_the_link_makes_a_member_without_the_initial_dashboard`، `AuthorizationChainTest::test_being_a_manager_in_one_complex_grants_nothing_in_another`، `tests/js/accountContext.test.tsx`، `tests/e2e/context-switch.spec.ts`، `tests/js/dashboardRouters.test.tsx`
- **Known Limitations:** —
- **Environment Dependency:** —
- **Definition of Done:** Code: `SetCurrentComplex`، سه روترِ جدا (`dashboardRoutes.tsx`، ۱۰/۱۶) · Database: `users.complex_id` فقط اشاره به زمینه، عضویت منبع · Authorization: زنجیره‌ی ۱۰/۳ · API: `/me`، `account/context` · Tests: بالا · Adversarial: `BolaSweepTest::test_an_invalid_context_never_falls_back_to_another_complex` · Regression: ردیف‌های ۱۰/۳ تا ۱۰/۵ · Documentation: `BACKEND_STRUCTURE.md`

### Multi-Community Isolation

- **بندها:** ۱۳، ۸۳، ۸۵، ۱۱۵، ۱۲۲
- **Final Status:** VERIFIED
- **Evidence:** یک حساب عضوِ فعالِ چند مجتمع؛ تعویضِ مجتمع در سرور سنجیده می‌شود و همه‌ی کشِ زمینه‌ی قبلی باطل؛ ماتریسِ چندمستأجری.
- **Tests:** `SecureInvitationTest::test_a_member_elsewhere_accepts_and_keeps_the_first_membership`، `TenantIsolationTest::test_an_admin_cannot_touch_another_complexes_records`، `TenantIsolationTest::test_the_other_complexes_records_survive_intact`، `tests/js/accountContext.test.tsx`
- **Known Limitations:** —
- **Environment Dependency:** —
- **Definition of Done:** Code: `AccountContextController`، `accountContext.ts` · Database: کلیدِ `complex_id` روی هر جدولِ مستأجر · Authorization: `ComplexScope` با سیاهه‌ی `SCOPE_BYPASS` · API: `account/context` · Tests: بالا · Adversarial: `TenantIsolationTest` · Regression: ردیف‌های ۱۰/۴ · Documentation: `ENDPOINT_AUTHZ_INVENTORY.md`

### Messenger

- **بندها:** ۳، ۲۹، ۳۰، ۳۱، ۳۲، ۳۳، ۳۴، ۳۵، ۳۶، ۳۷، ۳۸، ۳۹، ۴۰، ۴۱، ۴۲، ۵۰، ۵۱، ۵۳، ۵۴، ۶۰، ۷۰، ۷۱، ۷۲، ۷۳، ۸۰، ۸۱، ۱۰۱، ۱۱۷، ۱۲۱
- **Final Status:** VERIFIED
- **Evidence:** گروهِ فعالِ مجتمع با نامِ مجتمع؛ گفت‌وگوی هر عضو با مدیریت؛ مالک ↔ مستأجر و «ذخیره‌شده» که مدیر از هیچ راهی نمی‌بیند؛ اطلاعات و شمارِ گروه از عضویتِ فعال؛ نویسنده‌ی تاریخی می‌ماند؛ ساکنِ تازه رشته‌ی خصوصیِ قبلی را نمی‌خواند ولی تاریخچه‌ی گروه را می‌بیند؛ جست‌وجو فقط در آنچه خواندنی است.
- **Tests:** `MessengerStructureTest::test_the_lifecycle_of_the_spec_from_owner_to_a_new_tenant`، `MessengerStructureTest::test_the_manager_never_reaches_a_pair_or_someone_elses_saved_messages`، `GroupInfoTest::test_the_counts_come_from_active_memberships_and_follow_them`، `MessengerHistoryPolicyTest::test_the_new_resident_sees_the_whole_public_group_history`، `MessageSearchPrivacyTest::test_a_private_unit_thread_is_not_searchable_by_other_residents`
- **Known Limitations:** ویدیو و صدای کاربر زیرنویس ندارند (`CANONICAL_INVENTORY.md`)؛ رشته‌ی «واحد» ِ پیشین فقط‌خواندنی و بایگانی است (تصمیمِ کارفرما ۱۰/۹).
- **Environment Dependency:** —
- **Definition of Done:** Code: `ConversationDirectory`، `MessageSender` · Database: `conversations` با نوع و کلیدِ یکتا، `messages.author_name` snapshot ِ صریح · Authorization: `Conversation::visibleTo` و `Message::visibleTo` · API: `messenger/conversations/*` · Tests: بالا · Adversarial: `BolaSweepTest`، خرابکاریِ ۱۰/۹ تا ۱۰/۱۲ · Regression: ردیف‌های ۱۰/۹ · Documentation: `MESSENGER_STRUCTURE.md`

### Group Moderation

- **بندها:** ۱۶، ۵۲، ۵۵، ۵۶، ۵۷، ۵۸، ۵۹، ۱۱۸
- **Final Status:** VERIFIED
- **Evidence:** ویرایش و حذفِ پیامِ خود؛ در گروه مدیر و ناظر پیامِ دیگران را حذف می‌کنند، در خصوصی هیچ‌کس؛ ناظر نقش نیست و فقط در همان گروه، با پایانِ عضویت تمام؛ چند سنجاق با سقف؛ هدایت مبدأ و مقصد را جدا می‌سنجد.
- **Tests:** `GroupModerationTest::test_in_the_group_only_the_manager_and_the_moderator_delete_others`، `GroupModerationTest::test_the_moderator_is_not_a_manager_anywhere_else`، `GroupModerationTest::test_pins_are_many_and_follow_the_matrix`، `GroupModerationTest::test_forwarding_checks_the_source_and_the_target_separately`، `tests/js/messengerModeration.test.tsx`
- **Known Limitations:** —
- **Environment Dependency:** —
- **Definition of Done:** Code: `ConversationRights`، `MessageActions`، `GroupModeration` · Database: `conversation_moderators` بسته به عضویت، `conversation_pins` · Authorization: حقوق از سرور (`canPin`، `canDeleteOthers`) · API: `messages/{id}`، `pins`، `moderators` · Tests: بالا · Adversarial: خرابکاریِ ۱۰/۱۰ · Regression: ردیف‌های ۱۰/۱۰ · Documentation: `MESSENGER_STRUCTURE.md`

### Poll System

- **بندها:** ۶۱، ۶۲، ۶۳، ۶۴، ۶۵، ۶۶، ۶۷، ۶۸، ۶۹، ۱۱۹
- **Final Status:** VERIFIED
- **Evidence:** فقط در گروه و فقط مدیر می‌سازد؛ تک‌ و چندگزینه‌ای با سقف؛ همه، فقط مالکان، فقط مستأجران؛ دیدن جدا از رأی دادن و رأیِ غیرمجاز در سرور رد؛ گزینه‌ی تکراری و گزینه‌ی نظرسنجیِ دیگر رد؛ پس از پایانِ عضویت رأیِ تازه نه.
- **Tests:** `PollRulesTest::test_polls_live_only_in_groups`، `PollRulesTest::test_eligibility_is_separate_from_visibility_and_enforced_by_the_server`، `PollRulesTest::test_multiple_choice_respects_its_cap`، `PollRulesTest::test_no_new_vote_after_membership_ends_but_history_stays`، `MessengerFeaturesTest::test_an_option_from_another_poll_is_rejected`
- **Known Limitations:** —
- **Environment Dependency:** —
- **Definition of Done:** Code: `PollService`، `MessageSender::createPoll` · Database: `poll_votes` با `restrict` روی رأی‌دهنده (۱۰/۱۴) · Authorization: `blockReason` در تراکنش · API: `polls/{poll}/vote` · Tests: بالا · Adversarial: خرابکاریِ ۱۰/۱۱ · Regression: ردیف‌های ۱۰/۱۱ · Documentation: `MESSENGER_STRUCTURE.md`

### File Security

- **بندها:** ۴۳، ۴۴، ۴۵، ۴۶، ۴۷، ۴۸، ۴۹، ۱۲۰
- **Final Status:** IMPLEMENTATION READY — ENVIRONMENT VERIFICATION REQUIRED
- **Evidence:** نوع از محتوا؛ سقفِ صریح، در سرور و قابلِ تنظیم؛ نامِ تصادفی در دیسکِ خصوصی؛ سرو با نوعِ ثابت و `nosniff`؛ دانلود با قاعده‌ی دیدنِ پیام؛ جریانی با Range؛ نگه دار و رها کن برای صدا و ویدیو.
- **Tests:** `MessengerFilesTest::test_extension_and_content_must_agree`، `MessengerFilesTest::test_a_polyglot_is_never_served_as_something_it_can_run_as`، `MessengerFilesTest::test_downloads_follow_who_may_see_the_message`، `MessengerFilesTest::test_large_files_stream_instead_of_loading_into_memory`، `UploadSecurityTest::test_a_disguised_file_is_rejected`
- **Known Limitations:** آپلودِ تکه‌ای و ازسرگیری ساخته نشد (بندِ ۴۸ «در صورتِ نیاز»)؛ زیرنویسِ رسانه‌ی کاربر نیست.
- **Environment Dependency:** `upload_max_filesize`، `post_max_size` و `client_max_body_size` ِ سرور هم‌اندازه‌ی سقف‌ها؛ آپلودِ ۵۰۰ مگابایتی روی همان سرور سنجیده نشده (بندِ ۴۸).
- **Definition of Done:** Code: `AttachmentPolicy`، `Uploads::serveAttachment` · Database: `attachment_kind` · Authorization: `Message::visibleTo` پیش از سرو · API: `messenger/{message}/attachment` · Tests: بالا · Adversarial: خرابکاریِ ۱۰/۱۲ · Regression: ردیف‌های ۱۰/۱۲ · Documentation: `MESSENGER_STRUCTURE.md`

### Financial Integration

- **بندها:** ۲، ۱۰۲، ۱۰۳، ۱۰۴، ۱۰۵، ۱۰۶، ۱۰۷، ۱۰۸، ۱۰۹
- **Final Status:** VERIFIED
- **Evidence:** قبض بدهی را و دفتر پرداخت را تعریف می‌کند؛ مانده‌ی واحد کشِ ناورداست؛ کیفِ شخصی با دفترِ تغییرناپذیر؛ ماشینِ وضعیت، idempotency و مبلغ از قبض نه از درخواست؛ هم‌زمانی روی MySQL.
- **Tests:** `FinancialInvariantTest::test_every_money_path_keeps_the_cache_and_the_ledger_in_step`، `FinancialInvariantTest::test_every_debt_figure_reads_the_same_bills`، `WalletConcurrencyTest::test_concurrent_debits_never_overdraw_or_desync_the_ledger`، `PaymentStateMachineTest::test_a_forbidden_transition_is_refused`، `MassAssignmentTest::test_the_online_payment_amount_comes_from_the_bill_not_the_request`
- **Known Limitations:** داده‌ی پرداختِ پیش از دفترِ تخصیص (R15) شاید ردِ دفتر نداشته باشد؛ `finance:check` گزارشش می‌کند و خودکار درستش نمی‌کند.
- **Environment Dependency:** درگاهِ پرداختِ واقعی و `finance:check` روی دادهٔ سرور.
- **Definition of Done:** Code: `FinancialInvariants`، `ResidentDebt`، `PaymentService`، `WalletService` · Database: قیدهای CHECK ِ R52، کلیدهای `restrict` (۱۰/۱۴) · Authorization: مبلغ و شناسه از سرور · API: `pay`، `wallet` · Tests: بالا · Adversarial: `WalletConcurrencyTest` روی MySQL، خرابکاریِ ۱۰/۱۳ · Regression: ردیف‌های ۱۰/۱۳ · Documentation: `BACKEND_STRUCTURE.md`

### Authorization

- **بندها:** ۲۰، ۷۵، ۷۶، ۸۲، ۹۵، ۱۳۴
- **Final Status:** VERIFIED
- **Evidence:** هر درخواست مجوز را از عضویتِ فعالِ همان لحظه می‌خواند، نه از نقشِ ذخیره در حساب؛ هیچ امنیتی فقط در رابط نیست؛ جست‌وجو از مرزِ مجتمع نمی‌گذرد.
- **Tests:** `AuthorizationChainTest::test_a_forged_role_on_the_account_is_not_authority`، `AuthorizationAttackTest::test_a_resident_cannot_reach_manager_routes`، `ManagerSearchTest::test_the_lookup_itself_never_crosses_the_community`، `BolaSweepTest::test_no_attacker_reaches_another_tenants_or_accounts_resources`
- **Known Limitations:** —
- **Environment Dependency:** —
- **Definition of Done:** Code: `SetCurrentComplex`، policyها · Database: `complex_id` روی هر جدولِ مستأجر · Authorization: همین · API: `ENDPOINT_AUTHZ_INVENTORY.md` برای هر مسیر · Tests: بالا · Adversarial: `BolaSweepTest` روی همه‌ی مسیرها · Regression: ردیف‌های ۱۰/۳ · Documentation: `ENDPOINT_AUTHZ_INVENTORY.md`

### Database Integrity

- **بندها:** ۷۸، ۷۹
- **Final Status:** VERIFIED
- **Evidence:** کلیدهای خارجی، یکتایی، قیدهای CHECK روی MySQL، مهاجرت‌های دقیقاً برگشت‌پذیر، و معنای حذفِ صریح برای هر کلیدِ تاریخی که در پایگاه‌داده اعمال می‌شود.
- **Tests:** `MigrationSafetyTest::test_every_migration_is_exactly_reversible`، `DeleteSemanticsTest::test_every_history_key_follows_the_register`، `DeleteSemanticsTest::test_a_raw_parent_delete_never_erases_history`، `SchemaIntegrityTest::test_deleting_a_unit_keeps_its_financial_history`
- **Known Limitations:** ستون‌های مرده‌ی `conversations.pinned_message_id` و `complexes.fund_balance` نگه داشته شدند (حذفشان برگشتِ دقیقِ مهاجرت را روی SQLite می‌شکست).
- **Environment Dependency:** —
- **Definition of Done:** Code: `KeepsHistory`، `NeverDeleted`، `Immutable` · Database: همین · Authorization: — · API: — · Tests: بالا · Adversarial: خرابکاریِ ۱۰/۱۴ · Regression: ردیف‌های ۱۰/۱۴ · Documentation: `BACKEND_STRUCTURE.md`

### Testing

- **بندها:** ۱۱۴، ۱۲۴، ۱۲۶، ۱۲۷، ۱۲۸، ۱۲۹، ۱۳۰، ۱۳۱، ۱۳۳
- **Final Status:** VERIFIED
- **Evidence:** PHPUnit، Vitest و Playwright ِ کامل در CI روی آخرین کامیت سبز (بالا)؛ بودجه‌ی کوئری، صف، PWA بی کشِ دادهٔ شخصی، بی صفِ نوشتنِ آفلاین، قالبِ واحدِ خطا، ردیابیِ درخواست، و راز فقط از محیط.
- **Tests:** `ScalabilityTest::test_every_endpoint_stays_within_its_query_budget`، `OfflineBehaviourTest::test_no_offline_write_queue_exists_in_the_frontend`، `CachePolicyTest::test_personal_api_responses_are_never_stored`، `ApiContractTest::test_validation_errors_use_the_shared_shape`، `SecretHandlingTest::test_a_legacy_plaintext_row_is_refused_with_a_usable_message`
- **Known Limitations:** ۲۱ آزمونِ بک‌اند در CI عمداً رد می‌شوند (SQLite ِ قدیمیِ رانر قیدِ CHECK ندارد؛ همان‌ها در job ِ MySQL اجرا می‌شوند).
- **Environment Dependency:** —
- **Definition of Done:** Code: — · Database: SQLite و MySQL · Authorization: — · API: `openapi.json` · Tests: بالا · Adversarial: — · Regression: `PhaseRegressionMapTest` · Documentation: `FINAL_TEST_REPORT.md`

### Adversarial Testing

- **بندها:** ۷۶، ۷۷
- **Final Status:** VERIFIED
- **Evidence:** جاروی BOLA همه‌ی مسیرها را با مهاجم‌های مستأجرِ دیگر، حسابِ دیگر و زمینه‌ی نامعتبر می‌زند؛ خرابکاریِ هر فاز (برای ۱۰/۱۰ تا ۱۰/۱۴ جدولِ پایین) نشان می‌دهد آزمون‌ها شکستنِ هر محافظ را می‌گیرند.
- **Tests:** `BolaSweepTest::test_no_attacker_reaches_another_tenants_or_accounts_resources`، `BolaSweepTest::test_an_invalid_context_never_falls_back_to_another_complex`، `AuthorizationAttackTest::test_a_resident_cannot_reach_manager_routes`
- **Known Limitations:** خرابکاری فقط محافظ‌های موجود را می‌سنجد، نه بخشِ بی‌محافظ را (`FINAL_TEST_REPORT.md`).
- **Environment Dependency:** —
- **Definition of Done:** Code: — · Database: — · Authorization: همه‌ی مسیرها · API: `ENDPOINT_AUTHZ_INVENTORY.md` · Tests: بالا · Adversarial: همین · Regression: `PhaseRegressionMapTest` · Documentation: `FINAL_TEST_REPORT.md`

### Regression

- **بندها:** ۱۱۰
- **Final Status:** VERIFIED
- **Evidence:** هر فاز ردیف‌هایی در `PhaseRegressionMapTest` دارد که فقط بزرگ می‌شود؛ تحویل‌های مرحلهٔ ۴ با نقشه‌ی خودشان سنجیده می‌شوند.
- **Tests:** `PhaseRegressionMapTest::test_no_phase_is_missing_from_the_map`، `PhaseRegressionMapTest::test_the_map_never_shrinks`، `FinalPreProductionAuditTest::test_no_row_of_the_map_is_still_open`
- **Known Limitations:** —
- **Environment Dependency:** —
- **Definition of Done:** Code: — · Database: — · Authorization: — · API: — · Tests: بالا · Adversarial: ردیف‌های 🔴 ِ نقشه · Regression: همین · Documentation: `FINAL_PRE_PRODUCTION_AUDIT.md`

### Environment Verification

- **بندها:** ۴۸، ۸۶، ۸۷، ۸۸، ۱۱۳، ۱۲۳، ۱۲۵
- **Final Status:** IMPLEMENTATION READY — ENVIRONMENT VERIFICATION REQUIRED
- **Evidence:** حالتِ نمایشی جدا و بی درگاهِ واقعی؛ جدولِ سه‌سطحیِ CODE / ENVIRONMENT / PRODUCTION READY؛ ابزارِ سنجشِ بار و آپلودِ جریانی آماده.
- **Tests:** `PreviewEnvironmentTest::test_preview_never_uses_a_real_payment_gateway`، `DemoSeederTest::test_it_refuses_to_run_in_production`، `FinalPreProductionAuditTest::test_the_verdict_matches_the_three_level_table`، `LoadReadinessTest::test_the_probe_actually_runs_and_reports`
- **Known Limitations:** هیچ سنجشی روی سرورِ واقعی انجام نشده.
- **Environment Dependency:** سرورِ staging و production: بار (بندِ ۱۲۵)، آپلودِ بزرگ (بندِ ۴۸)، درگاهِ پرداخت و پیامکِ واقعی، و workflow ِ استقرار که هرگز اجرا نشده و رازهایش تنظیم نشده.
- **Definition of Done:** Code: `LoadProbe`، `PreviewEnvironment` · Database: پایگاه‌داده‌ی علامت‌خورده‌ی نمایشی · Authorization: کلیدِ دسترسیِ preview · API: — · Tests: بالا · Adversarial: — · Regression: — · Documentation: `LOAD_TESTING.md`، `DEPLOYMENT_SECURITY.md`

## معیارهای پذیرش (بندِ ۱۳۵)

| حوزه       | معیار                                           | بندها       |
| ---------- | ----------------------------------------------- | ----------- |
| Account    | یک حسابِ canonical برای هر شخص                  | ۴           |
| Account    | پروفایلِ canonical                              | ۵           |
| Account    | تصویرِ پروفایل در پیام‌رسان                     | ۵، ۳۵       |
| Community  | مجتمعِ مستقل                                    | ۶           |
| Community  | نوعِ درست و اعمال‌شده در سرور                   | ۶           |
| Community  | «اطلاعاتِ مجتمع» canonical                      | ۷، ۲        |
| Unit       | واحدِ canonical                                 | ۸، ۲        |
| Unit       | «اطلاعاتِ واحد» مشترک میانِ داشبوردها           | ۹           |
| Membership | چرخه‌ی عمرِ مشخص                                | ۱۱، ۱۲      |
| Membership | پشتیبانیِ چندمجتمعی                             | ۱۳          |
| Membership | جداییِ زمینه                                    | ۱۴، ۱۵      |
| Membership | پایانِ عضویت تاریخچه را نابود نمی‌کند           | ۲۷، ۷۴، ۷۹  |
| Invitation | QR ِ عمومی با تأیید                             | ۲۲، ۲۳      |
| Invitation | دعوتِ مستقیم بی تأییدِ اضافه                    | ۲۴، ۹۰      |
| Invitation | توکنِ امن                                       | ۲۶          |
| Dashboard  | مسیریابیِ وابسته به زمینه                       | ۸۹، ۱۴      |
| Dashboard  | دعوتِ مستقیم مستقیم به داشبوردِ واحد            | ۹۰          |
| Dashboard  | نقش‌ها به هم امتیاز نمی‌دهند                    | ۱۶، ۹۲، ۹۳  |
| Messenger  | گروهِ فعالِ مجتمع                               | ۳۲          |
| Messenger  | گفت‌وگوی خصوصی با مدیریت                        | ۳۷          |
| Messenger  | مالک ↔ مستأجر وقتی هر دو هستند                  | ۳۸          |
| Messenger  | «ذخیره‌شده» خصوصی                               | ۳۹          |
| Messenger  | جست‌وجوی امن                                    | ۴۱، ۴۲، ۸۲  |
| Messenger  | نامِ خودکارِ گروه                               | ۳۳          |
| Messenger  | اطلاعاتِ کاملِ گروه                             | ۳۵، ۳۶، ۷۰  |
| Messenger  | حفظِ تاریخچه‌ی پیام                             | ۲۹، ۷۳      |
| Messenger  | جداییِ ساکنِ قبلی و تازه                        | ۷۲، ۲۸      |
| Moderation | ویرایش و حذفِ پیامِ خود                         | ۵۴، ۵۵      |
| Moderation | مدیر و ناظر پیامِ دیگران را در گروه حذف می‌کنند | ۵۵          |
| Moderation | امتیازِ محدودِ ناظر                             | ۵۷، ۱۶      |
| Moderation | سنجاق مطابقِ سیاست                              | ۵۸، ۵۹      |
| Poll       | فقط گروه                                        | ۶۱          |
| Poll       | فقط مدیر می‌سازد                                | ۶۲          |
| Poll       | واجدِ شرایط بودن بر اساسِ نقش                   | ۶۵          |
| Poll       | اعمال در سرور                                   | ۶۵، ۶۷      |
| Poll       | جلوگیری از تکرار                                | ۶۴، ۶۷      |
| Files      | سقف                                             | ۴۶          |
| Files      | اعتبارسنجی                                      | ۴۳، ۴۷      |
| Files      | مجوز                                            | ۴۹          |
| Files      | ذخیره‌ی خصوصی                                   | ۴۷، ۴۹      |
| Files      | دانلودِ امن                                     | ۴۹، ۱۲۰     |
| Security   | محافظت از IDOR/BOLA                             | ۷۷          |
| Security   | جداییِ مستأجر                                   | ۱۱۵، ۹۵     |
| Security   | جداییِ زمینه                                    | ۱۵          |
| Security   | هیچ اختیاری از کلاینت پذیرفته نمی‌شود           | ۷۵، ۷۶، ۱۰۹ |
| Security   | مجوز در بک‌اند                                  | ۷۶، ۱۳۴     |

## اصلِ نهایی (بندِ ۱۴۱)

| اصل                                                              | بندها          |
| ---------------------------------------------------------------- | -------------- |
| هیچ شناسه‌ی ارسالی از کلاینت به‌تنهایی اختیار نیست               | ۷۵، ۷۷، ۱۰۹    |
| هیچ نقشی بیرون از دامنه‌اش امتیاز ندارد                          | ۱۶، ۵۷، ۹۲، ۹۳ |
| هیچ عضویتِ پایان‌یافته‌ای دسترسیِ فعال نمی‌گذارد                 | ۱۲، ۲۷، ۶۹، ۷۱ |
| هیچ عضوِ تازه‌ای تاریخچه‌ی خصوصیِ عضوِ قبلی را به ارث نمی‌برد    | ۲۸، ۷۲         |
| هیچ دادهٔ canonical در چند منبعِ مستقل و متناقض نگه‌داری نمی‌شود | ۲، ۱۰۵         |
| هیچ قابلیتی فقط با تغییرِ سند کامل اعلام نمی‌شود                 | ۱۱۱، ۱۳۸       |
| بی کدِ واقعی، آزمونِ واقعی و مدرکِ واقعی، requirement تمام نیست  | ۱۱۲، ۱۱۳، ۱۳۸  |

## خرابکاری ِ ۱۰/۱۰ تا ۱۰/۱۴

هر جهش محافظِ اصلیِ همان فاز را در کدِ برنامه می‌شکند، فقط آزمونِ هدف اجرا می‌شود، و فایل از رونوشت با سنجشِ هش
برمی‌گردد (هرگز `git checkout`).

<!-- sabotage:start -->

| فاز   | جهش | گرفته شد در اجرای اول | پس از اصلاح |
| ----- | --- | --------------------- | ----------- |
| ۱۰/۱۰ | ۶   | ۵                     | ۶/۶         |
| ۱۰/۱۱ | ۷   | ۵ (از ۶)              | ۷/۷         |
| ۱۰/۱۲ | ۸   | ۸                     | ۸/۸         |
| ۱۰/۱۳ | ۶   | ۶                     | ۶/۶         |
| ۱۰/۱۴ | ۴   | ۴                     | ۴/۴         |
| ۱۰/۱۵ | ۳   | ۳                     | ۳/۳         |

<!-- sabotage:end -->

**دو جهشی که اجرای اول نگرفت — هر دو شکافِ واقعیِ آزمون، نه محافظِ زائد:**

- **سنجاق در گفت‌وگوی فقط‌خواندنی (۱۰/۱۰):** `canPin` برای بایگانیِ واحد و گفت‌وگوی مالک ↔ مستأجرِ بسته‌شده `true` شد و
  هیچ آزمونی قرمز نشد. حالا `GroupModerationTest::test_a_read_only_conversation_takes_no_pin`.
- **گزینه‌ی تکراری در خودِ سرویس (۱۰/۱۱):** بررسیِ `PollService::castVote` برداشته شد و مسیرِ HTTP (لایه‌ی اول) همچنان
  رد می‌کرد، پس لایه‌ی دوم آزمون نداشت. حالا `PollRulesTest::test_the_service_itself_refuses_a_repeated_option`؛ و جهشِ
  آینه‌ای (برداشتنِ فقط لایه‌ی HTTP) را `PollRulesTest::test_multiple_choice_respects_its_cap` می‌گیرد — جهشِ هفتمِ ۱۰/۱۱.

**یادداشت‌ها:** یک جهشِ ۱۰/۱۲ (دانلود بی قاعده‌ی دیدن) در اجرای اول اصلاً اعمال نشد — الگو در `vote` هم بود — و با الگوی
یکتا جدا اجرا و گرفته شد. `forceDelete` ِ آزادِ واحد (۱۰/۱۴) و گزینه‌ی تکراری در سرویس پس از اصلاح، هر دو با «خطا» گرفته
شدند نه «شکستِ ادعا»: لایه‌ی پایگاه‌داده (`RESTRICT` و یکتاییِ رأی) خودش جلو را گرفت. ردیف‌های ۱۰/۱۵ خرابکاریِ خودِ این
گزارش است: حوزه‌ی محیطی «VERIFIED» اعلام شود، معیاری به بندِ محیطی بسته شود، یا بندی از حوزه‌ها بیفتد —
`ArchitectureFinalReportTest` هر سه را گرفت.
