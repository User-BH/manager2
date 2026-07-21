export interface ProfileFields {
  id: number
  name: string
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
