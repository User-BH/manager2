import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

/**
 * تصویرِ پروفایل، «اطلاعاتِ مجتمع» و «اطلاعاتِ واحد» در رابط — مرحلهٔ ۱۰ · فاز ۷ (بندهای ۲، ۵، ۷، ۹ و ۱۸).
 *
 * قاعده‌ها در سرور است (`ProfilePhotoTest`، `CommunityAndUnitInformationTest`)؛ اینجا: آواتار بی تصویر به شمایلِ
 * نام برمی‌گردد، فایلِ تصویر خام می‌رود و سرور پاکش می‌کند، صفحه‌ی مجتمع نوع و شمارش‌ها را نمی‌فرستد، و صفحه‌ی
 * واحد هیچ ورودیِ شارژی نمی‌فرستد.
 */

const calls = vi.hoisted(() => ({
  list: [] as { path: string; options?: { method?: string; body?: unknown } }[],
}))
const routes = vi.hoisted((): { map: Record<string, unknown> } => ({ map: {} }))
const confirm = vi.hoisted(() => vi.fn(() => Promise.resolve(true)))

vi.mock('@/shared/lib/api', () => {
  class ApiError extends Error {
    status = 422
    errors: Record<string, string[]> = {}
    fieldError(field: string) {
      return this.errors[field]?.[0]
    }
  }
  return {
    ApiError,
    setCsrfToken: vi.fn(),
    api: vi.fn((path: string, options?: { method?: string; body?: unknown }) => {
      calls.list.push({ path, options })
      const key = `${options?.method ?? 'GET'} ${path}`
      return Promise.resolve(key in routes.map ? routes.map[key] : (routes.map[path] ?? {}))
    }),
  }
})
vi.mock('@/shared/lib/alert', () => ({
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  alertError: vi.fn(),
  alertInfo: vi.fn(),
  confirmAction: confirm,
}))

const { UserAvatar } = await import('@/shared/ui/UserAvatar')
const { ProfilePhotoControl } = await import('@/features/profile/ProfilePhotoControl')
const { ComplexInformationPage } = await import('@/features/settings/ComplexInformationPage')
const { UnitInformationPage } = await import('@/features/units/UnitInformationPage')
const { navSections } = await import('@/shared/config/navigation')

function wrap(node: React.ReactNode, path = '/', pattern = '*') {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path={pattern} element={node} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  calls.list = []
  routes.map = {}
  confirm.mockClear()
})

describe('تصویرِ پروفایل', () => {
  it('با نشانی تصویر را نشان می‌دهد؛ بی نشانی یا با خطای بار، شمایلِ نام', () => {
    const { container, rerender } = render(
      <UserAvatar name="مریم" url="/api/v1/users/7/avatar?v=a1" />,
    )
    const img = container.querySelector('img')!
    expect(img.getAttribute('src')).toBe('/api/v1/users/7/avatar?v=a1')

    fireEvent.error(img)
    expect(container.querySelector('img')).toBeNull()
    expect(screen.getByRole('img', { name: 'مریم' })).toBeInTheDocument()

    rerender(<UserAvatar name="علی" url={null} />)
    expect(container.querySelector('img')).toBeNull()
  })

  it('فایل خام با نامِ avatar می‌رود و برداشتن پیش از ارسال تأیید می‌گیرد', async () => {
    const user = userEvent.setup()
    const onChanged = vi.fn()
    wrap(<ProfilePhotoControl hasPhoto onChanged={onChanged} />)

    const file = new File(['x'], 'me.jpg', { type: 'image/jpeg' })
    await user.upload(screen.getByLabelText('انتخابِ تصویرِ پروفایل'), file)
    await waitFor(() => expect(calls.list.some((c) => c.path === '/profile/avatar')).toBe(true))
    const sent = calls.list.find((c) => c.path === '/profile/avatar')!.options!
    expect(sent.method).toBe('POST')
    expect((sent.body as FormData).get('avatar')).toBe(file)

    await user.click(screen.getByRole('button', { name: /برداشتن/ }))
    await waitFor(() => expect(confirm).toHaveBeenCalled())
    await waitFor(() =>
      expect(
        calls.list.some((c) => c.options?.method === 'DELETE' && c.path === '/profile/avatar'),
      ).toBe(true),
    )
  })
})

describe('«اطلاعات مجتمع»', () => {
  const response = {
    information: {
      name: 'مجتمع آفتاب',
      typeLabel: 'مسکونی',
      brandName: null,
      address: 'خیابان اول',
      province: 'تهران',
      city: 'تهران',
      postalCode: null,
      latitude: null,
      longitude: null,
      description: null,
      structure: null,
      commonAreas: null,
      facilities: ['elevator'],
      facilitiesOther: null,
      phone: null,
      board: null,
      responseHours: null,
      emergencyInfo: null,
      completedAt: null,
    },
    derived: {
      buildings: 1,
      blocks: 2,
      floors: 6,
      units: 24,
      commercialSpaces: 3,
      owners: 18,
      tenants: 5,
      occupiedUnits: 20,
      vacantUnits: 4,
      activeUnits: 24,
      managers: ['علی مدیر'],
    },
    facilityOptions: [
      { value: 'elevator', label: 'آسانسور' },
      { value: 'pool', label: 'استخر' },
    ],
  }

  it('شمارش‌ها فقط نمایش‌اند؛ ذخیره نه نوع می‌فرستد نه شمارش', async () => {
    const user = userEvent.setup()
    routes.map = { '/complex/information': response }

    wrap(<ComplexInformationPage />)

    expect(await screen.findByText('علی مدیر', { exact: false })).toBeInTheDocument()
    expect(screen.getByLabelText<HTMLInputElement>('نوع مجتمع').disabled).toBe(true)
    await user.click(screen.getByLabelText('استخر'))
    await user.click(screen.getByRole('button', { name: /ذخیره اطلاعات مجتمع/ }))

    await waitFor(() => expect(calls.list.some((c) => c.options?.method === 'PUT')).toBe(true))
    const body = calls.list.find((c) => c.options?.method === 'PUT')!.options!.body as Record<
      string,
      unknown
    >
    expect(body.facilities).toEqual(['elevator', 'pool'])
    expect(body).not.toHaveProperty('type')
    for (const derived of ['units', 'owners', 'tenants', 'managers', 'buildings']) {
      expect(body).not.toHaveProperty(derived)
    }
    // خالی یعنی null، نه رشته‌ی خالی
    expect(body.postal_code).toBeNull()
  })

  it('در منوی کناری فقط برای مدیر است', () => {
    const item = navSections.flatMap((s) => s.items).find((i) => i.path === '/settings/information')
    expect(item?.label).toBe('اطلاعات مجتمع')
    expect(item?.roles).toEqual(expect.arrayContaining(['complex_admin']))
    expect(item?.roles).not.toEqual(expect.arrayContaining(['owner']))
  })
})

describe('«اطلاعات واحد»', () => {
  it('مشخصاتِ شارژ فقط نمایش‌اند و هرگز فرستاده نمی‌شوند', async () => {
    const user = userEvent.setup()
    routes.map = {
      '/units/5/information': {
        information: {
          unitType: null,
          block: null,
          roomsCount: null,
          bedroomsCount: null,
          bathroomsCount: null,
          parkingNumbers: null,
          storageNumbers: null,
          hasTerrace: null,
          hasBalcony: null,
          orientation: null,
          meters: {},
          description: null,
          completedAt: null,
        },
        managed: {
          unitNumber: '5',
          floor: 2,
          building: null,
          area: 120,
          parkingCount: 1,
          storageCount: 0,
          occupancyLabel: 'مستأجر',
        },
        people: [{ name: 'رضا', phone: '09120000000', relationLabel: 'مستأجر' }],
        unitTypeOptions: [{ value: 'residential', label: 'مسکونی' }],
        orientationOptions: [{ value: 'south', label: 'جنوبی' }],
      },
    }

    wrap(<UnitInformationPage />, '/units/5/information', '/units/:unitId/information')

    expect(await screen.findByText('120')).toBeInTheDocument()
    expect(screen.getByText(/رضا/)).toBeInTheDocument()
    await user.selectOptions(screen.getByLabelText('نوع واحد'), 'residential')
    await user.type(screen.getByLabelText('کنتور آب'), '12345')
    await user.click(screen.getByRole('button', { name: /ذخیره اطلاعات واحد/ }))

    await waitFor(() => expect(calls.list.some((c) => c.options?.method === 'PUT')).toBe(true))
    const put = calls.list.find((c) => c.options?.method === 'PUT')!
    expect(put.path).toBe('/units/5/information')
    const body = put.options!.body as Record<string, unknown>
    expect(body.unit_type).toBe('residential')
    expect(body.meters).toEqual({ water: '12345', electricity: null, gas: null })
    for (const billing of [
      'area',
      'floor',
      'unit_number',
      'residents_count',
      'coefficient',
      'parking_count',
    ]) {
      expect(body).not.toHaveProperty(billing)
    }
  })
})
