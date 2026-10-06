export interface ProfileFields {
  id: number
  name: string
  firstName: string | null
  lastName: string | null
  needsName: boolean
  phone: string
  email: string | null
  nationalId: string | null
  birthDate: string | null
  birthDateLabel: string | null
  emergencyPhone: string | null
  address: string | null
  bio: string | null
  role: string
  roleLabel: string
  isAdmin: boolean
  isActive: boolean
  canMessage: boolean
  joinedAt: string
  complex: { id: number; name: string } | null
}

export interface ProfileUnit {
  id: number
  label: string
  buildingName: string | null
  floor: number
  area: number
  relation: string
  relationLabel: string
  sharePercent: number
  isCurrent: boolean
  balance: number
  startDate: string | null
  endDate: string | null
}

export interface ProfilePerson {
  id: number
  name: string
  phone: string
  roleLabel: string
  relationLabel: string
  isActive: boolean
}

export interface ProfileComplex {
  id: number
  name: string
  address: string | null
  phone: string | null
  unitsCount: number
  usersCount: number
  isActive: boolean
  isCurrent: boolean
}

export interface ProfileResponse {
  profile: ProfileFields
  units: ProfileUnit[]
  people: ProfilePerson[]
  complexes: ProfileComplex[]
  stats: {
    unitsCount: number
    billsCount: number
    paidCount: number
    totalDebt: number
  }
}

/** نشست‌ها و دستگاه‌های مورداعتماد (مرحلهٔ ۵ · فاز ۱۰) — شکلِ `/profile/sessions`. */
export interface SecurityOverview {
  /** درایورِ نشست database است و فهرست واقعی است. */
  supported: boolean
  sessions: {
    /** HMACِ شناسه‌ی نشست؛ خودِ شناسه راز است و هرگز به مرورگر نمی‌آید. */
    id: string
    device: string
    ip: string | null
    lastActiveAt: string
    current: boolean
  }[]
  devices: {
    id: number
    device: string
    lastUsedAt: string | null
    expiresAt: string
    current: boolean
  }[]
  absoluteLifetimeMinutes: number
}

export interface LoginEventRow {
  id: number
  event: string
  failed: boolean
  device: string
  ip: string | null
  at: string
}
