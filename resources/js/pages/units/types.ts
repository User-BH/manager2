export interface Unit {
  id: number
  unitNumber: string
  buildingId: number | null
  buildingName: string | null
  floor: number
  area: number
  residentsCount: number
  parkingCount: number
  occupancyStatus: string
  occupancyLabel: string
  coefficient: number
  usesElevator: boolean
  balance: number
  notes: string | null
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
