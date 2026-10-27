/** پاسخِ دستیار (۱۳/۸) — هم‌شکلِ `App\Services\Assistant\Answer::toArray`. */
export type AssistantStatus =
  'found' | 'not_found' | 'forbidden' | 'clarify' | 'unsupported' | 'error'

export interface AssistantAnswer {
  status: AssistantStatus
  text: string
  headline: string
  facts: { label: string; value: string }[]
  columns: string[]
  rows: string[][]
  more: boolean
  basis: string | null
  period: string | null
  note: string | null
  options: string[]
  tool: string | null
  source: string | null
  engine: string
}

export interface AssistantAskResponse {
  id: number | null
  question: string
  createdAt: string
  answer: AssistantAnswer
}

export interface AssistantHistoryMessage {
  id: number
  question: string
  status: AssistantStatus
  text: string
  redacted: boolean
  createdAt: string
}

export interface AssistantHistory {
  context: string
  suggestions: string[]
  messages: AssistantHistoryMessage[]
}

/** یک نوبتِ گفت‌وگو در پنل: پاسخِ تازه با جزئیات، یا پاسخِ ذخیره‌شده‌ی تاریخچه (فقط متن) */
export interface AssistantTurn {
  key: string
  question: string
  createdAt: string
  status: AssistantStatus
  text: string
  answer?: AssistantAnswer
}
