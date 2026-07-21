import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { Loader2 } from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import type { UserRole } from '@/types'

interface ProtectedRouteProps {
  /** اگر داده شود، فقط این نقش‌ها اجازه‌ی ورود دارند */
  roles?: UserRole[]
}

/**
 * فرزندان این روت فقط برای کاربر احراز هویت‌شده در دسترس‌اند.
 * در غیر این صورت، حتی با وارد کردن مستقیم URL، به صفحه‌ی ورود هدایت می‌شود
 * و مسیر اولیه (state.from) ذخیره می‌شود تا بعد از ورود به همان‌جا برگردد.
 */
export function ProtectedRoute({ roles }: ProtectedRouteProps) {
  const { user, isAuthenticated, isLoading } = useAuth()
  const location = useLocation()

  // تا وقتی /api/me جواب نداده نمی‌دانیم نشست فعالی هست یا نه. بدون این حالت،
  // هر بار رفرش صفحه کاربرِ واردشده برای یک لحظه به /auth پرت می‌شود.
  if (isLoading) {
    return (
      <div
        className="flex min-h-screen items-center justify-center"
        style={{ backgroundColor: 'var(--surface-canvas)' }}
      >
        <Loader2 size={28} className="animate-spin" style={{ color: 'var(--color-brand-500)' }} />
      </div>
    )
  }

  if (!isAuthenticated) {
    return <Navigate to="/auth" replace state={{ from: location }} />
  }

  if (roles && user && !roles.includes(user.role)) {
    return <Navigate to="/forbidden" replace />
  }

  return <Outlet />
}
