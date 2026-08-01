import { afterEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { HomeNavbar } from '@/features/landing/home/components/HomeNavbar'

/*
 * ناوبریِ MPA به‌جای روتر، `window.location` را عوض می‌کند؛ در jsdom آن یک
 * خطای «navigation not implemented» می‌دهد که به تست ربطی ندارد.
 */
vi.mock('@/shared/lib/mpaNav', () => ({
  Link: ({ to, children }: { to: string; children?: React.ReactNode }) => (
    <a href={to}>{children}</a>
  ),
  useNavigate: () => vi.fn(),
}))

function signIn() {
  const el = document.createElement('script')
  el.type = 'application/json'
  el.id = 'viewer-state'
  el.textContent = '{"authenticated":true}'
  document.head.appendChild(el)
}

afterEach(() => {
  document.getElementById('viewer-state')?.remove()
})

describe('HomeNavbar', () => {
  it('به مهمان دکمه‌های ورود و ثبت‌نام نشان می‌دهد', () => {
    render(<HomeNavbar />)

    expect(screen.getByRole('button', { name: 'ورود' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'ثبت‌نام رایگان' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'ورود به داشبورد' })).not.toBeInTheDocument()
  })

  /**
   * کاربری که از قبل وارد شده، «ثبت‌نام رایگان» برایش بی‌معناست و «ورود» هم
   * یک گامِ اضافه است (فنی-56a).
   */
  it('به کاربرِ واردشده فقط «ورود به داشبورد» نشان می‌دهد', () => {
    signIn()
    render(<HomeNavbar />)

    expect(screen.getByRole('button', { name: 'ورود به داشبورد' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'ورود' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'ثبت‌نام رایگان' })).not.toBeInTheDocument()
  })
})
