import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
  type ReactNode,
} from 'react'
import { api } from '@/lib/api'
import type { NotificationItem, NotificationsResponse } from '@/types'

interface NotificationContextValue {
  unreadCount: number
  items: NotificationItem[]
  isLoading: boolean
  refresh: () => Promise<void>
  markRead: (id: number) => Promise<void>
  markAllRead: () => Promise<void>
}

const NotificationContext = createContext<NotificationContextValue | undefined>(undefined)

/** هر چند وقت یک‌بار شمارنده دوباره خوانده شود. */
const POLL_MS = 60_000

/**
 * وضعیت زنگوله‌ی هدر.
 *
 * دو مصرف‌کننده دارد که باید یک عدد ببینند: خود زنگوله و صفحه‌ی اطلاعیه‌ها
 * (که با خوانده‌شدن یک اطلاعیه باید شمارنده را کم کند). برای همین state
 * بالای هر دو نگه داشته شده.
 */
export function NotificationProvider({ children }: { children: ReactNode }) {
  const [unreadCount, setUnreadCount] = useState(0)
  const [items, setItems] = useState<NotificationItem[]>([])
  const [isLoading, setIsLoading] = useState(true)

  const refresh = useCallback(async () => {
    try {
      const response = await api<NotificationsResponse>('/notifications?limit=3')
      setUnreadCount(response.unreadCount)
      setItems(response.items)
    } catch {
      // خطای شبکه نباید هدر را بشکند؛ شمارنده همان مقدار قبلی می‌ماند
    } finally {
      setIsLoading(false)
    }
  }, [])

  useEffect(() => {
    void refresh()

    /*
     * وقتی تب پنهان است نظرسنجی متوقف می‌شود. بدون این شرط، تبِ باز در
     * پس‌زمینه ساعت‌ها بی‌دلیل به سرور درخواست می‌زد.
     */
    const timer = window.setInterval(() => {
      if (document.visibilityState === 'visible') void refresh()
    }, POLL_MS)

    return () => window.clearInterval(timer)
  }, [refresh])

  const markRead = useCallback(async (id: number) => {
    // خوش‌بینانه: عدد بلافاصله کم می‌شود و اگر درخواست شکست بخورد،
    // refresh بعدی مقدار درست سرور را برمی‌گرداند.
    setItems((prev) => prev.map((item) => (item.id === id ? { ...item, isRead: true } : item)))
    setUnreadCount((prev) => Math.max(0, prev - 1))

    try {
      const response = await api<{ unreadCount: number }>(`/notifications/${id}/read`, {
        method: 'POST',
      })
      setUnreadCount(response.unreadCount)
    } catch {
      void refreshSilently(setUnreadCount, setItems)
    }
  }, [])

  const markAllRead = useCallback(async () => {
    setItems((prev) => prev.map((item) => ({ ...item, isRead: true })))
    setUnreadCount(0)

    try {
      const response = await api<{ unreadCount: number }>('/notifications/read-all', {
        method: 'POST',
      })
      setUnreadCount(response.unreadCount)
    } catch {
      void refreshSilently(setUnreadCount, setItems)
    }
  }, [])

  return (
    <NotificationContext.Provider
      value={{ unreadCount, items, isLoading, refresh, markRead, markAllRead }}
    >
      {children}
    </NotificationContext.Provider>
  )
}

/** بازخوانی بعد از شکست یک به‌روزرسانی خوش‌بینانه. */
async function refreshSilently(
  setUnreadCount: (value: number) => void,
  setItems: (value: NotificationItem[]) => void,
): Promise<void> {
  try {
    const response = await api<NotificationsResponse>('/notifications?limit=3')
    setUnreadCount(response.unreadCount)
    setItems(response.items)
  } catch {
    // بی‌صدا؛ نظرسنجی بعدی دوباره تلاش می‌کند
  }
}

export function useNotifications() {
  const ctx = useContext(NotificationContext)
  if (!ctx) throw new Error('useNotifications باید داخل NotificationProvider استفاده شود')
  return ctx
}
