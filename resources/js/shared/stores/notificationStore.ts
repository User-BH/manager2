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

/** هر چند وقت یک‌بار شمارنده دوباره خوانده شود. */
const POLL_MS = 60_000

interface NotificationState {
  unreadCount: number
  items: NotificationItem[]
  isLoading: boolean
  refresh: () => Promise<void>
  markRead: (id: number) => Promise<void>
  markAllRead: () => Promise<void>
}

export const useNotificationStore = create<NotificationState>((set, get) => ({
  unreadCount: 0,
  items: [],
  isLoading: true,

  refresh: async () => {
    try {
      const response = await api<NotificationsResponse>('/notifications?limit=3')
      set({ unreadCount: response.unreadCount, items: response.items, isLoading: false })
    } catch {
      // خطای شبکه نباید هدر را بشکند؛ شمارنده همان مقدار قبلی می‌ماند
      set({ isLoading: false })
    }
  },

  markRead: async (id) => {
    set((state) => ({
      items: state.items.map((item) => (item.id === id ? { ...item, isRead: true } : item)),
      unreadCount: Math.max(0, state.unreadCount - 1),
    }))

    try {
      const response = await api<{ unreadCount: number }>(`/notifications/${id}/read`, {
        method: 'POST',
      })
      set({ unreadCount: response.unreadCount })
    } catch {
      void get().refresh()
    }
  },

  markAllRead: async () => {
    set((state) => ({
      items: state.items.map((item) => ({ ...item, isRead: true })),
      unreadCount: 0,
    }))

    try {
      const response = await api<{ unreadCount: number }>('/notifications/read-all', {
        method: 'POST',
      })
      set({ unreadCount: response.unreadCount })
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
}

/** رابطِ سازگار با نسخه‌ی قبلی؛ راه‌اندازی را یک‌بار انجام می‌دهد. */
export function useNotifications() {
  useEffect(() => bootstrapNotifications(), [])
  return useNotificationStore()
}
