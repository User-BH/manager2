import { api } from '@/shared/lib/api'
import type { ChatMessage, ReportItem, SearchHit } from './types'

/**
 * کار روی پیامِ موجود (مرحلهٔ ۶ · فاز ۱۴) — فقط تماس با API.
 *
 * هر کار پیامِ به‌روزشده را برمی‌گرداند تا فهرست همان را جایگزین کند؛ قاعده‌ها
 * (پنجره‌ی ویرایش، «فقط پیامِ خود»، پنهان‌بودن) همه سمتِ سرورند.
 */

const base = (conversationId: number) => `/messenger/conversations/${conversationId}`

type MessageReply = Promise<{ message: ChatMessage }>

export const messageActions = {
  edit: (conversationId: number, id: number, body: string): MessageReply =>
    api(`${base(conversationId)}/messages/${id}`, { method: 'PATCH', body: { body } }),

  remove: (conversationId: number, id: number): MessageReply =>
    api(`${base(conversationId)}/messages/${id}`, { method: 'DELETE' }),

  react: (conversationId: number, id: number, emoji: string): MessageReply =>
    api(`${base(conversationId)}/messages/${id}/reactions`, { method: 'POST', body: { emoji } }),

  report: (
    conversationId: number,
    id: number,
    reason: string | null,
  ): Promise<{ message: string }> =>
    api(`${base(conversationId)}/messages/${id}/report`, { method: 'POST', body: { reason } }),

  pin: (conversationId: number, id: number | null): Promise<{ pinned: ChatMessage | null }> =>
    api(`${base(conversationId)}/pin`, { method: 'POST', body: { message_id: id } }),

  search: (
    conversationId: number,
    query: string,
    signal?: AbortSignal,
  ): Promise<{ results: SearchHit[]; message?: string }> =>
    api(`${base(conversationId)}/search?q=${encodeURIComponent(query)}`, { signal }),

  reports: (signal?: AbortSignal): Promise<{ data: ReportItem[]; openCount: number }> =>
    api('/messenger/reports', { signal }),

  resolve: (reportId: number, resolution: 'hidden' | 'dismissed'): Promise<{ message: string }> =>
    api(`/messenger/reports/${reportId}/resolve`, { method: 'POST', body: { resolution } }),
}
