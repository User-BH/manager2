import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { api } from '@/lib/api'
import type { CurrentUser } from '@/types'

interface AuthContextValue {
  user: CurrentUser | null
  isAuthenticated: boolean
  /** تا وقتی وضعیت نشست از سرور نیامده true است */
  isLoading: boolean
  setUser: (user: CurrentUser | null) => void
  logout: () => Promise<void>
  refresh: () => Promise<void>
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<CurrentUser | null>(null)
  const [isLoading, setIsLoading] = useState(true)

  // منبع درستیِ وضعیت ورود، نشستِ سمت سرور است نه localStorage. اگر کاربر را
  // در مرورگر ذخیره کنیم، بعد از منقضی‌شدن نشست همچنان «وارد شده» به‌نظر
  // می‌رسد و کاربر با صفحه‌های خالی و خطای ۴۰۱ روبه‌رو می‌شود.
  const refresh = useCallback(async () => {
    try {
      const { user: current } = await api<{ user: CurrentUser | null }>('/me')
      setUser(current)
    } catch {
      setUser(null)
    } finally {
      setIsLoading(false)
    }
  }, [])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const logout = useCallback(async () => {
    try {
      await api('/logout', { method: 'POST' })
    } finally {
      setUser(null)
    }
  }, [])

  return (
    <AuthContext.Provider
      value={{
        user,
        isAuthenticated: Boolean(user),
        isLoading,
        setUser,
        logout,
        refresh,
      }}
    >
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth باید داخل AuthProvider استفاده شود')
  return ctx
}
