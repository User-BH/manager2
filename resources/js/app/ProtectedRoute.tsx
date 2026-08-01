import { Navigate, Outlet } from 'react-router-dom'
import { Loader2 } from 'lucide-react'
import { useAuth } from '@/shared/stores/authStore'
import type { UserRole } from '@/shared/types'

interface ProtectedRouteProps {
  /** اگر داده شود، فقط این نقش‌ها اجازه‌ی ورود دارند */
  roles?: UserRole[]
}

/**
 * فرزندان این روت فقط برای کاربر احراز هویت‌شده در دسترس‌اند.
 * در غیر این صورت، حتی با وارد کردن مستقیم URL، به صفحه‌ی ورود هدایت می‌شود.
 *
 * ⚠️ مسیرِ اولیه **ذخیره نمی‌شود** (این توضیح قبلاً خلافش را می‌گفت). صفحه‌ی
 * ورود یک سندِ MPAِ جداست و رفتن به آن ناوبریِ کاملِ مرورگر است، که
 * `location.state` را پاک می‌کند. اگر روزی «برگشت به همان صفحه» لازم شد، باید
 * از پارامترِ آدرس استفاده شود و حتماً از `safeInternalPath` رد شود — وگرنه
 * می‌شود هدایتِ باز.
 */
export function ProtectedRoute({ roles }: ProtectedRouteProps) {
  const { user, isAuthenticated, isLoading } = useAuth()

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
    // صفحه‌ی ورود یک سندِ MPAِ جداست؛ با ناوبریِ واقعیِ مرورگر می‌رویم، نه
    // مسیریابیِ داخلِ داشبورد.
    window.location.replace('/auth')
    return null
  }

  if (roles && user && !roles.includes(user.role)) {
    return <Navigate to="/forbidden" replace />
  }

  return <Outlet />
}
