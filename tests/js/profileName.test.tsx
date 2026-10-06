import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ProfileEditForm } from '@/features/profile/ProfileEditForm'
import type { ProfileFields } from '@/features/profile/types'

/**
 * فرمِ پروفایلِ مشترکِ چهار داشبورد: نام و نام خانوادگیِ جدا (مرحلهٔ ۹ · فاز ۳).
 *
 * به خواستِ کارفرما فرمِ مشترک دو فیلدی شد؛ نامِ نمایشی را سرور از این دو
 * می‌سازد (`PersonNameTest`). اینجا: چه چیزی فرستاده می‌شود و چه چیزی نه.
 */

const apiMock = vi.fn()
vi.mock('@/shared/lib/api', () => ({
  api: (...args: unknown[]) => apiMock(...args),
  ApiError: class extends Error {
    errors: Record<string, string[]> = {}
  },
}))
vi.mock('@/shared/lib/alert', () => ({ alertError: vi.fn(), toastSuccess: vi.fn() }))

function profile(overrides: Partial<ProfileFields> = {}): ProfileFields {
  return {
    id: 1,
    name: 'وحید حسنعلی پور مرادی',
    firstName: null,
    lastName: null,
    needsName: true,
    phone: '09120000002',
    email: null,
    nationalId: null,
    birthDate: null,
    birthDateLabel: null,
    emergencyPhone: null,
    address: null,
    bio: null,
    role: 'owner',
    roleLabel: 'مالک',
    isAdmin: false,
    isActive: true,
    canMessage: true,
    joinedAt: '۱۴۰۵/۰۷/۱۵',
    complex: null,
    ...overrides,
  }
}

describe('پروفایل: نام و نام خانوادگیِ جدا', () => {
  beforeEach(() => apiMock.mockReset().mockResolvedValue({}))

  it('حسابِ قبلی نامش را می‌بیند و خالی‌ها را خودش پر می‌کند — بی شکستنِ حدسی', () => {
    render(<ProfileEditForm profile={profile()} onSaved={vi.fn()} onCancel={vi.fn()} />)

    expect(screen.getByRole('status')).toHaveTextContent('«وحید حسنعلی پور مرادی»')
    expect(screen.getByLabelText('نام')).toHaveValue('')
    expect(screen.getByLabelText('نام خانوادگی')).toHaveValue('')
  })

  it('دو بخش را می‌فرستد و `name` را نه', async () => {
    const user = userEvent.setup()
    const onSaved = vi.fn()
    render(<ProfileEditForm profile={profile()} onSaved={onSaved} onCancel={vi.fn()} />)

    await user.type(screen.getByLabelText('نام'), 'وحید')
    await user.type(screen.getByLabelText('نام خانوادگی'), 'حسنعلی‌پور مرادی')
    await user.click(screen.getByRole('button', { name: /ذخیره تغییرات/ }))

    await waitFor(() => expect(onSaved).toHaveBeenCalled())
    const [path, options] = apiMock.mock.calls[0]
    expect(path).toBe('/profile')
    expect(options.body).toMatchObject({ first_name: 'وحید', last_name: 'حسنعلی‌پور مرادی' })
    expect(options.body).not.toHaveProperty('name')
  })

  it('حرفِ لاتین و رقم پیش از ارسال گرفته می‌شوند', async () => {
    const user = userEvent.setup()
    render(
      <ProfileEditForm
        profile={profile({ firstName: 'سارا', lastName: 'امینی', needsName: false })}
        onSaved={vi.fn()}
        onCancel={vi.fn()}
      />,
    )

    expect(screen.queryByRole('status')).toBeNull()
    await user.clear(screen.getByLabelText('نام'))
    await user.type(screen.getByLabelText('نام'), 'Sara2')
    await user.click(screen.getByRole('button', { name: /ذخیره تغییرات/ }))

    expect(await screen.findByText('نام فقط با حروف فارسی نوشته شود.')).toBeInTheDocument()
    expect(apiMock).not.toHaveBeenCalled()
  })
})
