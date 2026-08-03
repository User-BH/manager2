export interface Unit {
  id: number
  unitNumber: string
  buildingId: number | null
  buildingName: string | null
  floor: number
  area: number
  residentsCount: number
  parkingCount: number
  /** انباری — هم‌وزنِ پارکینگ در پرونده‌ی واحد (R26). */
  storageCount: number
  occupancyStatus: string
  occupancyLabel: string
  coefficient: number
  usesElevator: boolean
  balance: number
  notes: string | null
}

/** یک دوره‌ی مالکیت یا سکونت (R26). */
export interface UnitTenure {
  id: number
  userId: number
  name: string
  phone: string | null
  relation: 'owner' | 'tenant'
  relationLabel: string
  sharePercent: number
  startDate: string | null
  endDate: string | null
  isCurrent: boolean
  /** جاری و بدونِ تاریخ پایان — یعنی «تا کنون»، نه «تاریخش را نمی‌دانیم». */
  isOpen: boolean
}

export interface UnitDossier {
  unit: Unit
  tenures: UnitTenure[]
  /** جمعِ سهمِ مالکانِ جاری؛ اگر ۱۰۰ نباشد پرونده ناقص است. */
  ownershipShare: number
  history: { bills: number; payments: number }
}

export interface UnitFilters {
  buildings: { id: number; name: string }[]
  occupancyOptions: { value: string; label: string }[]
}

export interface UnitsResponse {
  data: Unit[]
  meta: { currentPage: number; lastPage: number; perPage: number; total: number }
  filters: UnitFilters
}
