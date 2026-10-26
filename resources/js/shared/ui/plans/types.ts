import type { PageMeta } from '@/shared/ui/Pager'

/** یک اشتراکِ مجتمع (`SubscriptionResource`) */
export interface SubscriptionRow {
  id: number
  plan: string
  planLabel: string
  status: string
  statusLabel: string
  method: string
  methodLabel: string
  amount: number
  amountLabel: string
  buyerName: string | null
  startsAt: string | null
  endsAt: string | null
  daysLeft: number
  trackingCode: string | null
  reviewNote: string | null
  hasReceipt: boolean
  createdAt: string
}

/** نوعِ مجتمع در خرید — همان دو نوعِ دامنه (`ComplexType`؛ Mixed-Use: INTENTIONALLY NOT BUILT) */
export type PlanComplexType = 'residential' | 'commercial'

export interface PlanDurationPrice {
  months: number
  price: number
  priceLabel: string
}

/** کارتِ یک پلن — از `PlanCatalog` ِ سرور؛ رابط هیچ قیمت یا قابلیتی نمی‌سازد */
export interface PlanCard {
  slug: string
  name: string
  description: string | null
  tier: 'basic' | 'professional' | 'advanced'
  tierLabel: string
  complexType: PlanComplexType | null
  /** قابلیت‌هایی که کد واقعاً اعمال می‌کند (سقفِ واحد، درگاه، Excel) */
  capabilities: string[]
  /** متنِ کارت که سوپر ادمین نوشته */
  features: string[]
  /** «تمامِ امکانات بدون محدودیت» — فقط وقتی سرور می‌گوید واقعاً بی‌سقف است */
  includesEverything: boolean
  /** فقط مدت‌هایی که قیمت دارند */
  prices: PlanDurationPrice[]
  isCurrent: boolean
}

export interface CurrentSubscription extends SubscriptionRow {
  tierLabel: string | null
  months: number
  paidUntil: string
  daysLeftTotal: number
}

/** پاسخِ `GET /subscription` (۱۳/۶) — فقط مدیرِ مجتمعِ فعال و حسابِ اولیه */
export interface AccountSettingsResponse {
  account: 'manager' | 'initial'
  complexName: string | null
  complexType: PlanComplexType | null
  complexTypeLabel: string | null
  currentPlan: string
  currentPlanLabel: string
  current: CurrentSubscription | null
  /** «تمدیدِ پلنِ فعلی»: فقط مدیر و فقط اگر همان پلن هنوز عرضه می‌شود */
  renewal: { plan: string } | null
  usage: { units: number; unitLimit: number | null } | null
  catalog: Partial<Record<PlanComplexType, PlanCard[]>>
  durations: number[]
  baseFeatures: string[]
  checkoutEnabled: boolean
  checkoutAction: string
  supportPhone: string
  history: SubscriptionRow[]
}

export interface PaymentHistoryRow {
  id: string
  planLabel: string
  tierLabel: string | null
  complexTypeLabel: string
  complexName: string | null
  months: number
  amountLabel: string
  date: string
  time: string
  status: string
  statusLabel: string
  trackingCode: string | null
}

export interface PaymentHistoryResponse {
  data: PaymentHistoryRow[]
  meta: PageMeta
}
