import { afterEach, describe, expect, it, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HomeNavbar } from '@/features/landing/home/components/HomeNavbar'

/*
 * ناوبریِ MPA به‌جای روتر، `window.location` را عوض می‌کند؛ در jsdom آن یک
 * خطای «navigation not implemented» می‌دهد که به تست ربطی ندارد.
 */
const navigate = vi.fn()

vi.mock('@/shared/lib/mpaNav', () => ({
  Link: ({ to, children }: { to: string; children?: React.ReactNode }) => (
    <a href={to}>{children}</a>
  ),
  useNavigate: () => navigate,
}))

function signIn() {
  const el = document.createElement('script')
  el.type = 'application/json'
  el.id = 'viewer-state'
  el.textContent = '{"authenticated":true}'
  document.head.appendChild(el)
}

/** پنلِ برگر فقط پس از کلیک روی دکمه‌ی «منو» رندر می‌شود. */
async function openMenu() {
  await userEvent.click(screen.getByRole('button', { name: 'منو' }))
}

/** خودِ پنل — نه کلِ هدر — تا ادعاها به دکمه‌های دسکتاپ نشت نکنند. */
function menuPanel(): HTMLElement {
  const panel = screen.getByRole('button', { name: 'منو' }).closest('header')
    ?.lastElementChild as HTMLElement

  expect(panel).toBeTruthy()

  return panel
}

afterEach(() => {
  document.getElementById('viewer-state')?.remove()
  navigate.mockClear()
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

  // ── دکمه‌ی تم بیرون از منو (R31) ────────────────────────────────────────

  it('دکمه‌ی تم بدونِ بازکردنِ منو در دسترس است', () => {
    render(<HomeNavbar />)

    /*
     * دو نسخه رندر می‌شود (دسکتاپ و کنارِ برگر) و CSS یکی را پنهان می‌کند؛
     * مهم این است که در موبایل هم بدونِ بازکردنِ منو یکی هست.
     */
    expect(screen.getAllByRole('button', { name: /تغییر به حالت/ }).length).toBeGreaterThan(0)
  })

  it('دکمه‌ی تم دیگر داخلِ منوی برگر نیست', async () => {
    render(<HomeNavbar />)
    await openMenu()

    /*
     * ⚠️ خواسته‌ی اصلیِ R31: تغییرِ تم در موبایل نباید پشتِ دو کلیک باشد.
     * پیش از این تنها راهش بازکردنِ همین منو بود.
     */
    expect(within(menuPanel()).queryByRole('button', { name: /تغییر به حالت/ })).toBeNull()
    expect(within(menuPanel()).queryByText('تغییر تم')).toBeNull()
  })

  it('پنلِ منو سفیدِ مات نیست و پشتش دیده می‌شود', async () => {
    render(<HomeNavbar />)
    await openMenu()

    const style = menuPanel().style

    // باگِ تأییدشده: پس‌زمینه `#ffffff` بدونِ blur بود
    expect(style.backgroundColor).not.toBe('rgb(255, 255, 255)')
    expect(style.backgroundColor).toContain('color-mix')
    expect(style.backdropFilter).toContain('blur')
  })

  // ── آیتم‌های دمو و سوالات متداول (R31) ──────────────────────────────────

  it('در صفحه‌ی فرود هر دو مقصد در هدر هستند', () => {
    render(<HomeNavbar />)

    expect(screen.getByRole('button', { name: 'دمو' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'سوالات متداول' })).toBeInTheDocument()
  })

  it('«سوالات متداول» به همان بخش در صفحه‌ی پشتیبانی می‌رود', async () => {
    render(<HomeNavbar />)
    await userEvent.click(screen.getByRole('button', { name: 'سوالات متداول' }))

    // بخش است و نه صفحه‌ی جدا؛ `?topic=faq` همان را باز و به آن اسکرول می‌کند
    expect(navigate).toHaveBeenCalledWith('/support?topic=faq')
  })

  it('در صفحه‌ی دمو، آیتمِ «دمو» حذف می‌شود', async () => {
    render(<HomeNavbar page="demo" />)

    expect(screen.queryByRole('button', { name: 'دمو' })).toBeNull()
    expect(screen.getByRole('button', { name: 'سوالات متداول' })).toBeInTheDocument()

    await openMenu()
    expect(within(menuPanel()).queryByRole('button', { name: 'دمو' })).toBeNull()
  })

  it('در صفحه‌ی پشتیبانی، آیتمِ «سوالات متداول» حذف می‌شود', async () => {
    render(<HomeNavbar page="support" />)

    expect(screen.queryByRole('button', { name: 'سوالات متداول' })).toBeNull()
    expect(screen.getByRole('button', { name: 'دمو' })).toBeInTheDocument()

    await openMenu()
    expect(within(menuPanel()).queryByRole('button', { name: 'سوالات متداول' })).toBeNull()
  })

  // ── محتوای منوی برگر (R31) ──────────────────────────────────────────────

  it('منوی برگر برای مهمان دقیقاً چهار دکمه دارد', async () => {
    render(<HomeNavbar />)
    await openMenu()

    expect(
      within(menuPanel())
        .getAllByRole('button')
        .map((b) => b.textContent),
    ).toEqual(['ورود', 'ثبت‌نام رایگان', 'دمو', 'سوالات متداول'])
  })

  it('منوی کاربرِ واردشده به‌جای ورود و ثبت‌نام، داشبورد دارد', async () => {
    signIn()
    render(<HomeNavbar />)
    await openMenu()

    expect(
      within(menuPanel())
        .getAllByRole('button')
        .map((b) => b.textContent),
    ).toEqual(['ورود به داشبورد', 'دمو', 'سوالات متداول'])
  })

  it('کلیک روی آیتمِ منو، منو را می‌بندد', async () => {
    render(<HomeNavbar />)
    await openMenu()

    await userEvent.click(within(menuPanel()).getByRole('button', { name: 'دمو' }))

    // منوی بازمانده روی صفحه، مقصدِ بعدی را می‌پوشاند
    expect(screen.getByRole('button', { name: 'منو' })).toHaveAttribute('aria-expanded', 'false')
  })
})
