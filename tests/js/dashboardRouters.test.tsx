import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Outlet, useLocation } from 'react-router-dom'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'
import type { CurrentUser } from '@/shared/types'

/**
 * سه داشبورد، سه روتر — بندِ ۲ ِ فایلِ «بررسی جدید ۲» (مرحلهٔ ۱۰ · فاز ۱۶).
 *
 * هر داشبورد فقط مسیرهای خودش را می‌شناسد؛ مسیرِ داشبوردِ دیگر «دسترسی ندارید» (همان رفتارِ پیشین)، نشانیِ ناشناخته
 * ۴۰۴، و بیرون از نشست ورود. منوی هر نقش دقیقاً از مسیرهای روترِ خودش است. صفحه‌ها و پوسته ساختگی‌اند: موضوع
 * انتخابِ روتر است، نه خودِ صفحه.
 */

vi.mock('@/shared/lib/api', () => ({
  api: vi.fn().mockResolvedValue({ user: null }),
  ApiError: class extends Error {},
}))
const leaveToAuth = vi.fn()
vi.mock('@/shared/lib/leaveToAuth', () => ({ leaveToAuth: () => leaveToAuth() }))
vi.mock('@/shared/layout/DashboardLayout', () => ({
  DashboardLayout: () => (
    <div data-testid="shell">
      <Outlet />
    </div>
  ),
}))
vi.mock('@/features/dashboard/DashboardPage', () => ({ DashboardPage: () => <p>داشبورد</p> }))
vi.mock('@/features/units/UnitsPage', () => ({ UnitsPage: () => <p>واحدها</p> }))
vi.mock('@/features/system/PlatformStatsPage', () => ({
  PlatformStatsPage: () => <p>آمار پلتفرم</p>,
}))
vi.mock('@/features/error/ForbiddenPage', () => ({ ForbiddenPage: () => <p>دسترسی ندارید</p> }))
vi.mock('@/features/error/NotFoundPage', () => ({ NotFoundPage: () => <p>یافت نشد</p> }))

type Modules = {
  AppRouter: (typeof import('@/app/AppRouter'))['AppRouter']
  routes: typeof import('@/app/dashboardRoutes')
  navigation: typeof import('@/shared/config/navigation')
  useAuthStore: (typeof import('@/shared/stores/authStore'))['useAuthStore']
}
let m: Modules

// ایمپورتِ سرد یک بار و با بودجه‌ی خودش (همان درسِ ۱۰/۱۵)
beforeAll(async () => {
  const [{ AppRouter }, routes, navigation, { useAuthStore }] = await Promise.all([
    import('@/app/AppRouter'),
    import('@/app/dashboardRoutes'),
    import('@/shared/config/navigation'),
    import('@/shared/stores/authStore'),
  ])
  m = { AppRouter, routes, navigation, useAuthStore }
}, 30_000)

afterEach(() => leaveToAuth.mockClear())

const ROLES: CurrentUser['role'][] = ['owner', 'tenant', 'complex_admin', 'super_admin']

function user(role: CurrentUser['role']): CurrentUser {
  return {
    id: 1,
    name: 'کاربر',
    firstName: 'کاربر',
    lastName: 'آزمون',
    needsName: false,
    phone: '09123456789',
    role,
    roleLabel: role,
    accountState: 'member',
    canWrite: true,
    pendingInvitations: 0,
    memberships: [],
    isAdmin: role === 'super_admin' || role === 'complex_admin',
    isSuperAdmin: role === 'super_admin',
    complex: null,
  }
}

let currentPath = ''
function Where() {
  currentPath = useLocation().pathname
  return null
}

function visit(path: string, who: CurrentUser | null) {
  m.useAuthStore.setState({ user: who, isLoading: false })
  render(
    <MemoryRouter initialEntries={[path]}>
      <m.AppRouter />
      <Where />
    </MemoryRouter>,
  )
}

describe('هر نقش روترِ داشبوردِ خودش را می‌گیرد', () => {
  it('ساکن: مسیرِ مدیر و ادمینِ کل «دسترسی ندارید»، نه صفحه', async () => {
    visit('/units', user('tenant'))
    expect(await screen.findByText('دسترسی ندارید')).toBeInTheDocument()
    expect(currentPath).toBe('/forbidden')
    expect(screen.queryByText('واحدها')).toBeNull()
  })

  it('مدیر: مسیرِ مدیریت در پوسته باز است، مسیرِ سکو نه', async () => {
    visit('/units', user('complex_admin'))
    expect(await screen.findByText('واحدها')).toBeInTheDocument()
    expect(screen.getByTestId('shell')).toBeInTheDocument()
  })

  it('مدیر روی مسیرِ ادمینِ کل', async () => {
    visit('/system/stats', user('complex_admin'))
    expect(await screen.findByText('دسترسی ندارید')).toBeInTheDocument()
    expect(currentPath).toBe('/forbidden')
  })

  it('ادمینِ کل: مسیرِ سکو و مدیریتِ مجتمعِ برگزیده', async () => {
    visit('/system/stats', user('super_admin'))
    expect(await screen.findByText('آمار پلتفرم')).toBeInTheDocument()
  })

  it('نشانیِ ناشناخته ۴۰۴ است، نه ۴۰۳', async () => {
    visit('/no-such-page', user('owner'))
    expect(await screen.findByText('یافت نشد')).toBeInTheDocument()
    expect(currentPath).toBe('/no-such-page')
  })

  it('بیرون از نشست، مسیرِ هر داشبورد به ورود می‌رود', async () => {
    visit('/units', null)
    await waitFor(() => expect(leaveToAuth).toHaveBeenCalled())
    expect(screen.queryByText('دسترسی ندارید')).toBeNull()
  })
})

describe('مرزِ ساختاریِ سه روتر', () => {
  it('روترِ واحد هیچ مسیرِ مدیر یا سکو ندارد؛ روترِ مدیر هیچ مسیرِ سکو', () => {
    const paths = (dashboard: 'unit' | 'manager' | 'platform') =>
      m.routes.DASHBOARD_ROUTES[dashboard].map((route) => route.path)
    const manager = m.routes.MANAGER_ROUTES.map((route) => route.path)
    const platform = m.routes.PLATFORM_ROUTES.map((route) => route.path)

    expect(paths('unit').filter((path) => [...manager, ...platform].includes(path))).toEqual([])
    expect(paths('manager').filter((path) => platform.includes(path))).toEqual([])
    expect(new Set(paths('platform')).size).toBe(paths('platform').length)
  })

  it('منوی هر نقش فقط مسیرهای روترِ همان داشبورد را دارد', () => {
    for (const role of ROLES) {
      const own = m.routes.DASHBOARD_ROUTES[m.routes.dashboardFor(role)].map((route) => route.path)
      const menu = m.navigation
        .visibleSections(role)
        .flatMap((section) => section.items.map((item) => item.path))

      expect(menu.length, role).toBeGreaterThan(0)
      expect(
        menu.filter((path) => !own.includes(path)),
        `${role}: آیتمِ منو بیرون از روترش`,
      ).toEqual([])
    }
  })

  it('نقشِ زمینه‌ی فعال داشبورد را تعیین می‌کند', () => {
    expect(ROLES.map((role) => m.routes.dashboardFor(role))).toEqual([
      'unit',
      'unit',
      'manager',
      'platform',
    ])
  })
})
