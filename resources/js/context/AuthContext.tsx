import { create } from 'zustand'
import { api } from '@/lib/api'
import type { CurrentUser } from '@/types'

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
    } catch {
      set({ user: null, isLoading: false })
    }
  },
  logout: async () => {
    try {
      await api('/logout', { method: 'POST' })
    } finally {
      set({ user: null })
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
