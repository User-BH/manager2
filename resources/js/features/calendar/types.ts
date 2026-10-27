import type { PageMeta } from '@/shared/ui/Pager'

/** یک نمونه از یادآور در یک روز (`ReminderController::occurrence`) */
export interface ReminderOccurrence {
  id: string
  reminderId: number
  /** تاریخِ رویدادِ همین نمونه، میلادیِ «YYYY-MM-DD» */
  date: string
  dateLabel: string
  remindDateLabel: string
  remindTime: string
  title: string
  description: string | null
  recurring: boolean
  repeatLabel: string
  /** لحظه‌ی یادآوری گذشته */
  past: boolean
}

/** یادآور (سری یا یک‌باره) — `ReminderController::present` */
export interface ReminderDetail {
  id: number
  title: string
  description: string | null
  targetDate: string
  targetDateLabel: string
  leadDays: number
  remindTime: string
  remindDateLabel: string
  repeatEveryDays: number | null
  repeatEnd: 'none' | 'until' | 'count' | null
  repeatUntil: string | null
  repeatCount: number | null
  repeatLabel: string
  nextOccurrence: string | null
  status: 'scheduled' | 'done' | 'ended'
  statusLabel: string
  notifiedCount: number
  createdAt: string
}

export interface ReminderHistoryResponse {
  data: ReminderDetail[]
  meta: PageMeta
}

/** دامنه‌ی ویرایش و حذفِ نمونه‌ای از سری (بندِ ۸۵) */
export type ReminderScope = 'this' | 'following' | 'all'
