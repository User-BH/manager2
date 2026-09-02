import { create } from 'zustand'
import { api } from '@/shared/lib/api'
import { ApiError } from '@/shared/lib/apiError'
import { clearApiCache } from '@/shared/pwa/registerServiceWorker'
import type { CurrentUser } from '@/shared/types'

/**
 * وضعیت احراز هویت با zustand.
 *
 * منبعِ درستیِ وضعیتِ ورود، نشستِ سمت سرور است نه localStorage؛ پس با بالا
 * آمدنِ اپ یک‌بار `/me` خوانده می‌شود. این ماژول فقط در صفحه‌هایی import
 * می‌شود که واقعاً به احراز نیاز دارند (ورود و داشبورد)، پس صفحه‌ی فرود
 * بی‌دلیل به سرور درخواست نمی‌زند.
 */

interface AuthState {
  user: CurrentUser | null
  /** تا وقتی وضعیت نشست از سرور نیامده true است. */
  isLoading: boolean
  setUser: (user: CurrentUser | null) => void
  refresh: () => Promise<void>
  logout: () => Promise<void>
}

export const useAuthStore = create<AuthState>((set) => ({
  user: null,
  isLoading: true,
  setUser: (user) => set({ user }),
  refresh: async () => {
    try {
      const { user } = await api<{ user: CurrentUser | null }>('/me')
      set({ user, isLoading: false })
    } catch (error) {
      /*
       * ⚠️ «نرسیدم» با «بیرونت کردند» یکی نیست (R60).
       *
       * نسخه‌ی قبلی هر خطایی را «خارج شده» می‌خواند. تا R60 این کمتر دیده
       * می‌شد چون پاسخِ `/me` کش می‌شد و آفلاین از کش می‌آمد — ولی آن کش
       * خودش نشت بود: می‌گفت کاربر وارد است، حتی وقتی نشستِ سرور بسته
       * شده بود.
       *
       * حالا که `/me` هرگز کش نمی‌شود، این تفکیک لازم است: قطعیِ شبکه
       * (`status === 0`) نباید کاربرِ واردشده را از برنامه بیندازد بیرون.
       * پاسخِ ۴۰۱ی سرور باید.
       */
      const offline = error instanceof ApiError && error.status === 0

      set((state) => ({
        user: offline ? state.user : null,
        isLoading: false,
      }))
    }
  },
  logout: async () => {
    try {
      await api('/logout', { method: 'POST' })
    } finally {
      set({ user: null })

      /*
       * ⚠️ پاک‌کردنِ کشِ service worker بخشی از خروج است، نه یک بهینه‌سازی.
       *
       * از R35 پاسخ‌های `/api` روی دستگاه کش می‌شوند تا حالتِ آفلاین کار
       * کند. اگر اینجا پاک نشوند، قبض و موجودی و پیام‌های همین کاربر روی
       * دستگاه می‌مانند و نفرِ بعدی — یا خودش پس از خروج — می‌تواند در
       * حالتِ آفلاین ببیندشان. نشستِ سرور بسته شده ولی نسخه‌ی کش‌شده
       * سرورش را نمی‌پرسد.
       */
      /*
       * ⚠️ `await` و نه `void` (R60).
       *
       * نسخه‌ی قبلی پاک‌سازی را رها می‌کرد و بی‌درنگ برمی‌گشت. اگر کاربر
       * بلافاصله تب را می‌بست یا صفحه عوض می‌شد، حذف نیمه‌کاره می‌ماند —
       * یعنی همان چیزی که این خط برای رفعش هست، در بدترین حالت (خروج و
       * بستنِ فوری روی دستگاهِ مشترک) کار نمی‌کرد.
       */
      await clearApiCache()
    }
  },
}))

// راه‌اندازیِ اولیه: وضعیت نشست را همان اول از سرور می‌گیریم.
void useAuthStore.getState().refresh()

/** رابطِ سازگار با نسخه‌ی قبلی. */
export function useAuth() {
  const user = useAuthStore((state) => state.user)
  const isLoading = useAuthStore((state) => state.isLoading)
  const setUser = useAuthStore((state) => state.setUser)
  const refresh = useAuthStore((state) => state.refresh)
  const logout = useAuthStore((state) => state.logout)

  return { user, isAuthenticated: Boolean(user), isLoading, setUser, refresh, logout }
}
