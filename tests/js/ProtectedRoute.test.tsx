import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ProtectedRoute } from '@/app/ProtectedRoute'
import { useAuthStore } from '@/shared/stores/authStore'
import type { CurrentUser } from '@/shared/types'

/**
 * `ProtectedRoute` تنها نگهبانِ سمتِ کلاینتِ مسیرهای داشبورد است. سرور هم
 * مستقل محافظت می‌کند، ولی اگر این بشکند کاربرِ بی‌اجازه صفحه‌ی خالی و
 * خطاهای ۴۰۱ می‌بیند. این تست‌ها چهار حالتِ ممکن را می‌سنجند.
 */

// جلوگیری از فراخوانیِ واقعیِ /me هنگام بارگذاری ماژول store
vi.mock('@/shared/lib/api', () => ({
  api: vi.fn().mockResolvedValue({ user: null }),
  ApiError: class extends Error {},
}))

function makeUser(role: CurrentUser['role']): CurrentUser {
  return {
    id: 1,
    name: 'کاربر',
    phone: '09123456789',
    role,
    roleLabel: 'نقش',
    isAdmin: role === 'super_admin' || role === 'complex_admin',
    isSuperAdmin: role === 'super_admin',
    complex: null,
  }
}

function renderAt(initialPath = '/dashboard', roles?: CurrentUser['role'][]) {
  return render(
    <MemoryRouter initialEntries={[initialPath]}>
      <Routes>
        <Route element={<ProtectedRoute roles={roles} />}>
          <Route path="/dashboard" element={<div>محتوای داشبورد</div>} />
        </Route>
        <Route path="/forbidden" element={<div>صفحه ۴۰۳</div>} />
      </Routes>
    </MemoryRouter>,
  )
}

describe('ProtectedRoute', () => {
  beforeEach(() => {
    useAuthStore.setState({ user: null, isLoading: false })
  })

  it('تا وقتی وضعیت نشست نامعلوم است، محتوا را نشان نمی‌دهد', () => {
    useAuthStore.setState({ user: null, isLoading: true })
    renderAt()

    // نه محتوا و نه ریدایرکت؛ فقط حالت انتظار
    expect(screen.queryByText('محتوای داشبورد')).not.toBeInTheDocument()
    expect(screen.queryByText('صفحه ۴۰۳')).not.toBeInTheDocument()
  })

  it('کاربرِ واردشده محتوا را می‌بیند', () => {
    useAuthStore.setState({ user: makeUser('complex_admin'), isLoading: false })
    renderAt()

    expect(screen.getByText('محتوای داشبورد')).toBeInTheDocument()
  })

  it('کاربرِ با نقشِ مجاز محتوا را می‌بیند', () => {
    useAuthStore.setState({ user: makeUser('super_admin'), isLoading: false })
    renderAt('/dashboard', ['super_admin'])

    expect(screen.getByText('محتوای داشبورد')).toBeInTheDocument()
  })

  it('کاربرِ با نقشِ غیرمجاز به صفحه‌ی ۴۰۳ می‌رود', () => {
    useAuthStore.setState({ user: makeUser('tenant'), isLoading: false })
    renderAt('/dashboard', ['super_admin'])

    expect(screen.getByText('صفحه ۴۰۳')).toBeInTheDocument()
    expect(screen.queryByText('محتوای داشبورد')).not.toBeInTheDocument()
  })

  it('مهمان محتوای محافظت‌شده را نمی‌بیند', () => {
    useAuthStore.setState({ user: null, isLoading: false })
    renderAt()

    expect(screen.queryByText('محتوای داشبورد')).not.toBeInTheDocument()
  })
})
