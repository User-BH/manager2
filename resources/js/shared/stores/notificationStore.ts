import { useEffect } from 'react'
import { create } from 'zustand'
import { api } from '@/shared/lib/api'
import type { NotificationItem, NotificationsResponse } from '@/shared/types'

/**
 * وضعیت زنگوله‌ی هدر با zustand.
 *
 * دو مصرف‌کننده باید یک عدد ببینند: خود زنگوله و صفحه‌ی اطلاعیه‌ها. با store‌ی
 * مشترک، خوانده‌شدنِ یک اطلاعیه بلافاصله شمارنده را همه‌جا کم می‌کند.
 *
 * markRead/markAllRead خوش‌بینانه‌اند: عدد بلافاصله کم می‌شود و اگر درخواست
 * شکست بخورد، بازخوانیِ بی‌صدا مقدارِ درستِ سرور را برمی‌گرداند.
 */

interface Counts {
  unreadCount: number
  unreadInvitations?: number
}

/** شمارنده‌های پاسخِ «خوانده شد» — نشانِ آدمک فقط وقتی سرور گفت عوض می‌شود */
function counts(response: Counts): Partial<NotificationState> {
  return response.unreadInvitations === undefined
    ? { unreadCount: response.unreadCount }
    : { unreadCount: response.unreadCount, unreadInvitations: response.unreadInvitations }
}

/** هر چند وقت یک‌بار شمارنده دوباره خوانده شود. */
const POLL_MS = 60_000

interface NotificationState {
  unreadCount: number
  /**
   * نشانِ آدمک (۱۳/۵): اعلان‌های دعوتِ خوانده‌نشده با دعوتِ زنده، از همان پاسخ‌های زنگوله — تا خواندنِ اعلان و نظرسنجی
   * نشان را هم به‌روز کنند. null یعنی هنوز پاسخی نیامده (آدمک تا آن وقت عددِ /me را نشان می‌دهد).
   */
  unreadInvitations: number | null
  items: NotificationItem[]
  isLoading: boolean
  refresh: () => Promise<void>
  /** اطلاعیه‌ی همگانی — صفحه‌ی اطلاعیه‌ها هم از همین استفاده می‌کند. */
  markAnnouncementRead: (id: number) => Promise<void>
  /** اعلانِ شخصی (نتیجه‌ی بررسیِ رسید و مانندش). */
  markPersonalRead: (id: string) => Promise<void>
  /** دیسپچرِ زنگوله: بر اساس گونه‌ی آیتم یکی از دو تای بالا را صدا می‌زند. */
  markRead: (item: NotificationItem) => Promise<void>
  markAllRead: () => Promise<void>
}

export const useNotificationStore = create<NotificationState>((set, get) => ({
  unreadCount: 0,
  unreadInvitations: null,
  items: [],
  isLoading: true,

  refresh: async () => {
    try {
      const response = await api<NotificationsResponse>('/notifications?limit=3')
      set({
        unreadCount: response.unreadCount,
        unreadInvitations: response.unreadInvitations ?? null,
        items: response.items,
        isLoading: false,
      })
    } catch {
      // خطای شبکه نباید هدر را بشکند؛ شمارنده همان مقدار قبلی می‌ماند
      set({ isLoading: false })
    }
  },

  /*
   * دو گونه‌ی اعلان دو مسیرِ متفاوت دارند، چون وضعیتِ خواندنشان جای متفاوتی
   * ذخیره می‌شود: اطلاعیه‌ی همگانی در `announcement_reads` و اعلانِ شخصی در
   * ستونِ `read_at` خودش. عمداً دو تابعِ جدا و نه یکی با شرطِ درونی، تا
   * فراخوانی‌کننده مجبور باشد بداند با کدام سروکار دارد.
   *
   * هر دو خوش‌بینانه‌اند: شمارنده فوری کم می‌شود و اگر درخواست شکست بخورد،
   * بازخوانیِ بی‌صدا مقدارِ درستِ سرور را برمی‌گرداند.
   */
  markAnnouncementRead: async (id) => {
    set((state) => ({
      items: state.items.map((row) =>
        row.kind === 'announcement' && row.announcementId === id ? { ...row, isRead: true } : row,
      ),
      unreadCount: Math.max(0, state.unreadCount - 1),
    }))

    try {
      const response = await api<Counts>(`/notifications/${id}/read`, {
        method: 'POST',
      })
      set(counts(response))
    } catch {
      void get().refresh()
    }
  },

  markPersonalRead: async (id) => {
    set((state) => ({
      items: state.items.map((row) => (row.id === `n:${id}` ? { ...row, isRead: true } : row)),
      unreadCount: Math.max(0, state.unreadCount - 1),
    }))

    try {
      const response = await api<Counts>(`/notifications/personal/${id}/read`, {
        method: 'POST',
      })
      set(counts(response))
    } catch {
      void get().refresh()
    }
  },

  markRead: async (item) => {
    if (item.kind === 'announcement') {
      // `announcementId` همیشه برای اطلاعیه‌ها می‌آید؛ `id` پیشونددار است
      await get().markAnnouncementRead(item.announcementId!)
      return
    }

    await get().markPersonalRead(item.id.replace(/^n:/, ''))
  },

  markAllRead: async () => {
    set((state) => ({
      items: state.items.map((item) => ({ ...item, isRead: true })),
      unreadCount: 0,
    }))

    try {
      const response = await api<Counts>('/notifications/read-all', {
        method: 'POST',
      })
      set(counts(response))
    } catch {
      void get().refresh()
    }
  },
}))

/*
 * راه‌اندازی: اولین خواندن + نظرسنجیِ دوره‌ای. این ماژول فقط داخل داشبورد
 * import می‌شود، پس polling هم فقط همان‌جا زنده است. وقتی تب پنهان است
 * درخواست زده نمی‌شود تا تبِ پس‌زمینه بی‌دلیل به سرور فشار نیاورد.
 */
let bootstrapped = false
function bootstrapNotifications(): void {
  if (bootstrapped || typeof window === 'undefined') return
  bootstrapped = true

  void useNotificationStore.getState().refresh()
  window.setInterval(() => {
    if (document.visibilityState === 'visible') void useNotificationStore.getState().refresh()
  }, POLL_MS)

  /*
   * ⚠️ بازگشت به تب و وصل‌شدنِ دوباره (مرحلهٔ ۵ · فاز ۶ · بندِ ۵۳).
   *
   * polling در تبِ پنهان عمداً می‌خوابد؛ ولی پس از بازگشت تا شصت ثانیه عددِ
   * کهنه نشان داده می‌شد — همان لحظه‌ای که کاربر آمده ببیند چه خبر است.
   * همین‌طور پس از قطعیِ اینترنت.
   */
  const refreshIfVisible = () => {
    if (document.visibilityState === 'visible') void useNotificationStore.getState().refresh()
  }
  document.addEventListener('visibilitychange', refreshIfVisible)
  window.addEventListener('online', refreshIfVisible)
}

/** رابطِ سازگار با نسخه‌ی قبلی؛ راه‌اندازی را یک‌بار انجام می‌دهد. */
export function useNotifications() {
  useEffect(() => bootstrapNotifications(), [])
  return useNotificationStore()
}
