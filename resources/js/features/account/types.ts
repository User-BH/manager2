export interface SubscriptionPlanOption {
  value: string
  label: string
  price: number
  priceLabel: string
  months: number
  features: string[]
  savingPercent: number
}

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

export interface BankInfo {
  holder: string
  bank_name: string
  card: string
  iban: string
}

export interface SubscriptionResponse {
  complexName: string | null
  currentPlan: string
  currentPlanLabel: string
  current: SubscriptionRow | null
  /** مصرف فعلی در برابر سقف پلن؛ unitLimit برابر null یعنی نامحدود. */
  usage: { units: number; unitLimit: number | null } | null
  freeFeatures: string[]
  plans: SubscriptionPlanOption[]
  checkoutEnabled: boolean
  checkoutAction: string
  supportPhone: string
  bankInfo: BankInfo
  history: SubscriptionRow[]
}
