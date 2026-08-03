/**
 * کارخانه‌ی کلیدهای TanStack Query.
 *
 * ─── چرا فایلِ جدا و نه رشته‌ی درجا؟ ───────────────────────────────────────
 * کلید در TanStack Query هم شناسه‌ی کش است و هم دستگیره‌ی ابطال. اگر هر صفحه
 * کلیدِ خودش را درجا بسازد، دیر یا زود یکی `['units']` می‌نویسد و دیگری
 * `['unit-list']`، و آن‌وقت `invalidateQueries` بی‌صدا هیچ کاری نمی‌کند —
 * خطایی رخ نمی‌دهد، فقط UI تازه نمی‌شود. یافتنِ چنین باگی سخت است.
 *
 * ─── قاعده‌ی سلسله‌مراتب ────────────────────────────────────────────────────
 * کلیدها از کل به جزء چیده می‌شوند تا ابطالِ گروهی ممکن باشد:
 *
 *   queryKeys.units.all()        →  ['units']
 *   queryKeys.units.list(params) →  ['units', 'list', {...}]
 *
 * ابطالِ `['units']` هر دو را می‌گیرد، چون TanStack کلیدها را **پیشوندی**
 * تطبیق می‌دهد. پس پس از ساختنِ یک واحد کافی است `units.all()` را باطل کنید.
 *
 * ─── افزودنِ کلیدِ تازه ─────────────────────────────────────────────────────
 * همیشه `all()` را داشته باشید و بقیه را زیرِ آن ببرید. پارامترها را در یک شیء
 * بگذارید (نه چند عضوِ جدا) تا اضافه‌شدنِ فیلترِ بعدی کلیدهای قبلی را نشکند.
 */
export const queryKeys = {
  dashboard: {
    all: () => ['dashboard'] as const,
  },

  // ── واحدها و ساکنان ──────────────────────────────────────────────────────
  units: {
    all: () => ['units'] as const,
    list: (params: { search?: string } = {}) => ['units', 'list', params] as const,
    // پرونده‌ی یک واحد با تاریخچه‌اش (R26)
    dossier: (id: number) => ['units', 'dossier', id] as const,
  },

  residents: {
    all: () => ['residents'] as const,
    list: (params: { search?: string } = {}) => ['residents', 'list', params] as const,
  },

  managers: {
    all: () => ['managers'] as const,
  },

  /*
   * درخواست‌های ساکنین (R25). فیلترها در یک شیء‌اند تا افزودنِ فیلترِ بعدی
   * کلیدهای قبلی را نشکند.
   */
  serviceRequests: {
    all: () => ['service-requests'] as const,
    list: (params: { status?: string; category?: string; mine?: boolean } = {}) =>
      ['service-requests', 'list', params] as const,
    detail: (id: number) => ['service-requests', 'detail', id] as const,
  },

  // اعلان‌ها و سهمیه‌ی پیامک (R27)
  notifications: {
    all: () => ['notifications'] as const,
    history: (page: number) => ['notifications', 'history', page] as const,
    settings: () => ['notifications', 'settings'] as const,
  },

  smsCampaign: {
    all: () => ['sms-campaign'] as const,
  },

  // ── مالی ─────────────────────────────────────────────────────────────────
  bills: {
    all: () => ['bills'] as const,
    list: (params: { period?: string } = {}) => ['bills', 'list', params] as const,
    mine: () => ['bills', 'mine'] as const,
  },

  chargeRules: {
    all: () => ['charge-rules'] as const,
  },

  discounts: {
    all: () => ['discounts'] as const,
    list: (params: { period?: string } = {}) => ['discounts', 'list', params] as const,
  },

  finance: {
    all: () => ['finance'] as const,
    summary: (params: { period?: string } = {}) => ['finance', 'summary', params] as const,
    goodPayers: () => ['finance', 'good-payers'] as const,
  },

  payments: {
    all: () => ['payments'] as const,
    /** صفحه‌ی پرداختِ یک قبضِ مشخص. */
    forBill: (billId: string | number) => ['payments', 'bill', String(billId)] as const,
  },

  // ── ارتباطات ─────────────────────────────────────────────────────────────
  announcements: {
    all: () => ['announcements'] as const,
  },

  // ── حسابِ کاربر ──────────────────────────────────────────────────────────
  profile: {
    all: () => ['profile'] as const,
  },

  subscription: {
    all: () => ['subscription'] as const,
  },

  // ── تنظیماتِ مجتمع ───────────────────────────────────────────────────────
  settings: {
    all: () => ['settings'] as const,
  },

  backups: {
    all: () => ['backups'] as const,
  },

  // ── پنلِ ادمینِ کل ────────────────────────────────────────────────────────
  members: {
    all: () => ['members'] as const,
    list: (params: { q?: string } = {}) => ['members', 'list', params] as const,
  },

  // دعوت‌های دریافتیِ کاربر در «حالتِ اولیه» (R21)
  invitations: {
    all: () => ['invitations'] as const,
  },

  // درخواست‌های پیوستن که مدیر باید پاسخ بدهد (R21b)
  joinRequests: {
    all: () => ['join-requests'] as const,
  },

  // کیفِ پولِ واحد (R22)
  wallet: {
    all: () => ['wallet'] as const,
    statement: (unitId: number) => ['wallet', 'statement', unitId] as const,
  },

  system: {
    all: () => ['system'] as const,
    ads: () => ['system', 'ads'] as const,
    auditLogs: (params: { query?: string } = {}) => ['system', 'audit-logs', params] as const,
    backups: () => ['system', 'backups'] as const,
    complexes: (params: { page?: number } = {}) => ['system', 'complexes', params] as const,
    plans: () => ['system', 'plans'] as const,
    siteSettings: () => ['system', 'site-settings'] as const,
    sms: () => ['system', 'sms'] as const,
    subscriptions: () => ['system', 'subscriptions'] as const,
    observability: () => ['system', 'observability'] as const,
    observabilityErrors: (params: { includeResolved?: boolean } = {}) =>
      ['system', 'observability', 'errors', params] as const,
  },
} as const
