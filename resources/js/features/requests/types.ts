/** انواعِ مشترکِ درخواست‌های ساکنین (R25). */

export type RequestStatus = 'new' | 'in_progress' | 'resolved' | 'closed' | 'rejected'

export interface RequestComment {
  id: number
  body: string
  authorName: string
  isMine: boolean
  /** یادداشتِ مدیریتی؛ سرور اصلاً برای ساکن نمی‌فرستدش. */
  isInternal: boolean
  sentAt: string
}

export interface ServiceRequest {
  id: number
  title: string
  description: string

  category: string
  categoryLabel: string
  priority: string
  priorityLabel: string
  priorityColor: string
  status: RequestStatus
  statusLabel: string
  statusColor: string
  isOpen: boolean

  unitLabel: string | null
  requesterName: string | null
  assignee: { id: number; name: string } | null
  attachment: { name: string; url: string } | null

  createdAt: string
  resolvedAt: string | null
  closedAt: string | null

  /** فقط در نمای جزئیات می‌آید؛ در فهرست نیست تا N+1 نشود. */
  comments?: RequestComment[]

  /** سرور تصمیم می‌گیرد چه کاری از دستِ این بیننده برمی‌آید، نه کلاینت. */
  can: {
    assign: boolean
    noteInternally: boolean
    isRequester: boolean
    isAssignee: boolean
  }
}

export interface Option {
  value: string
  label: string
  color?: string
}

export interface RequestListResponse {
  requests: ServiceRequest[]
  total: number
  currentPage: number
  lastPage: number
  counts: Record<string, number>
  isAdmin: boolean
  categories: Option[]
  statuses: Option[]
  priorities: Option[]
  assignables: { id: number; name: string; role: string }[]
}

/**
 * گذارهایی که برای این بیننده روی این درخواست معنا دارد.
 *
 * ⚠️ این **فقط برای رابط** است. قاعده‌ی واقعی سمتِ سرور در
 * `ServiceRequestService` اعمال می‌شود؛ اینجا صرفاً دکمه‌های بی‌فایده نشان
 * داده نمی‌شوند. هرگز به این تابع به‌عنوان مجوز تکیه نکنید.
 */
export function availableMoves(
  request: ServiceRequest,
  isAdmin: boolean,
  isAssignee: boolean,
  isRequester: boolean,
): Array<{ status: RequestStatus; label: string }> {
  const moves: Array<{ status: RequestStatus; label: string }> = []

  if (isAdmin || isAssignee) {
    if (request.status === 'new') moves.push({ status: 'in_progress', label: 'شروع پیگیری' })
    if (request.status === 'new' || request.status === 'in_progress') {
      moves.push({ status: 'resolved', label: 'انجام شد' })
    }
  }

  if (isAdmin && (request.status === 'new' || request.status === 'in_progress')) {
    moves.push({ status: 'rejected', label: 'رد کردن' })
  }

  if (request.status === 'resolved') {
    if (isRequester) {
      moves.push({ status: 'closed', label: 'تایید و بستن' })
      moves.push({ status: 'in_progress', label: 'هنوز درست نشده' })
    } else if (isAdmin) {
      moves.push({ status: 'in_progress', label: 'بازگشت به پیگیری' })
    }
  }

  if (isAdmin && (request.status === 'closed' || request.status === 'rejected')) {
    moves.push({ status: 'in_progress', label: 'بازکردن دوباره' })
  }

  return moves
}
