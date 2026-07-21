export interface Resident {
  id: number
  name: string
  phone: string
  email: string | null
  nationalId: string | null
  role: 'owner' | 'tenant'
  roleLabel: string
  isActive: boolean
  canMessage: boolean
  units: { id: number; label: string }[]
}

export interface ResidentFilters {
  units: { id: number; unit_number: string }[]
  roleOptions: { value: string; label: string }[]
}

export interface ResidentsResponse {
  data: Resident[]
  meta: { currentPage: number; lastPage: number; perPage: number; total: number }
  filters: ResidentFilters
}
