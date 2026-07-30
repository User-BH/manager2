import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ErrorBoundary } from '@/shared/ui/ErrorBoundary'

/**
 * دیوارِ آتشِ خطاهای رندر.
 *
 * تستِ Error Boundary در jsdom یک ویژگی دارد: React خطای گرفته‌شده را **باز هم**
 * روی کنسول چاپ می‌کند. آن را ساکت می‌کنیم تا خروجیِ تست خوانا بماند، ولی فقط
 * در همین فایل.
 */

function Boom({ shouldThrow }: { shouldThrow: boolean }): React.ReactElement {
  if (shouldThrow) throw new Error('کامپوننت ترکید')
  return <p>محتوای سالم</p>
}

beforeEach(() => {
  vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('ErrorBoundary', () => {
  it('وقتی خطایی نیست، فرزندان را عادی نشان می‌دهد', () => {
    render(
      <ErrorBoundary>
        <Boom shouldThrow={false} />
      </ErrorBoundary>,
    )

    expect(screen.getByText('محتوای سالم')).toBeInTheDocument()
  })

  it('خطای رندر را می‌گیرد و به‌جای صفحه‌ی سفید پیامِ فارسی نشان می‌دهد', () => {
    render(
      <ErrorBoundary>
        <Boom shouldThrow />
      </ErrorBoundary>,
    )

    expect(screen.getByRole('alert')).toBeInTheDocument()
    expect(screen.getByText('این بخش درست بارگذاری نشد')).toBeInTheDocument()
  })

  it('خطا را برای گزارش‌دهی بیرون می‌دهد (قلابِ Sentry در R8)', () => {
    const onError = vi.fn()

    render(
      <ErrorBoundary onError={onError}>
        <Boom shouldThrow />
      </ErrorBoundary>,
    )

    expect(onError).toHaveBeenCalledTimes(1)
    expect((onError.mock.calls[0][0] as Error).message).toBe('کامپوننت ترکید')
  })

  it('نمای جایگزینِ دلخواه را می‌پذیرد', () => {
    render(
      <ErrorBoundary fallback={(error) => <p>نمای سفارشی: {error.message}</p>}>
        <Boom shouldThrow />
      </ErrorBoundary>,
    )

    expect(screen.getByText(/نمای سفارشی/)).toBeInTheDocument()
  })

  /*
   * مهم‌ترین تستِ این فایل.
   *
   * بدونِ resetKey، یک‌بار خطا یعنی همیشه خطا: boundary در حالتِ خطا گیر می‌کند
   * و کاربر هر صفحه‌ای که برود همان پیامِ خطا را می‌بیند. چون در داشبورد
   * `resetKey={pathname}` است، این تست دقیقاً همان سناریوی ناوبری را می‌سنجد.
   */
  it('با تغییرِ resetKey (ناوبری) از حالتِ خطا بیرون می‌آید', async () => {
    const user = userEvent.setup()

    function Harness() {
      const [route, setRoute] = useState('/a')
      return (
        <>
          <button onClick={() => setRoute('/b')}>برو به صفحه‌ی دیگر</button>
          <ErrorBoundary resetKey={route}>
            {/* فقط مسیرِ اول می‌ترکد؛ مسیرِ دوم سالم است */}
            <Boom shouldThrow={route === '/a'} />
          </ErrorBoundary>
        </>
      )
    }

    render(<Harness />)
    expect(screen.getByText('این بخش درست بارگذاری نشد')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'برو به صفحه‌ی دیگر' }))

    expect(screen.getByText('محتوای سالم')).toBeInTheDocument()
    expect(screen.queryByText('این بخش درست بارگذاری نشد')).not.toBeInTheDocument()
  })

  it('دکمه‌ی «تلاش دوباره» وضعیت را پاک می‌کند', async () => {
    const user = userEvent.setup()

    function Harness() {
      // پس از اولین رندر دیگر نمی‌ترکد، تا اثرِ دکمه دیده شود
      const [fixed, setFixed] = useState(false)
      return (
        <ErrorBoundary
          fallback={(_error, reset) => (
            <button
              onClick={() => {
                setFixed(true)
                reset()
              }}
            >
              تلاش دوباره
            </button>
          )}
        >
          <Boom shouldThrow={!fixed} />
        </ErrorBoundary>
      )
    }

    render(<Harness />)
    await user.click(screen.getByRole('button', { name: 'تلاش دوباره' }))

    expect(screen.getByText('محتوای سالم')).toBeInTheDocument()
  })

  it('خطای یک صفحه، بقیه‌ی رابط را از بین نمی‌برد', () => {
    render(
      <div>
        <nav>منوی کناری</nav>
        <ErrorBoundary>
          <Boom shouldThrow />
        </ErrorBoundary>
      </div>,
    )

    // دقیقاً چیزی که چیدمانِ داشبورد تضمین می‌کند: پوسته زنده می‌ماند
    expect(screen.getByText('منوی کناری')).toBeInTheDocument()
    expect(screen.getByRole('alert')).toBeInTheDocument()
  })
})

/**
 * خطای «چانکِ کهنه» — سناریوی انتشارِ نسخه‌ی تازه.
 *
 * این حالت را هنگام راستی‌آزماییِ R7 در مرورگرِ واقعی دیدم: پس از یک بیلدِ تازه،
 * تبی که باز مانده بود دنبال فایلی گشت که دیگر وجود نداشت. چون نامِ فایل‌ها هش
 * دارند، «تلاش دوباره» بی‌فایده است و فقط بارگذاری دوباره درمانش می‌کند.
 */
describe('ErrorBoundary — چانکِ کهنه پس از انتشار', () => {
  function ChunkBoom(): React.ReactElement {
    throw new TypeError('Failed to fetch dynamically imported module: /build/assets/Page-abc123.js')
  }

  it('پیامِ مخصوصِ نسخه‌ی تازه را نشان می‌دهد، نه پیامِ عمومیِ خطا', () => {
    render(
      <ErrorBoundary>
        <ChunkBoom />
      </ErrorBoundary>,
    )

    expect(screen.getByText('نسخه‌ی تازه‌ای منتشر شده است')).toBeInTheDocument()
    expect(screen.queryByText('این بخش درست بارگذاری نشد')).not.toBeInTheDocument()
  })

  it('دکمه‌اش «بارگذاری دوباره» است، چون ریستِ ساده همان آدرسِ ناموجود را می‌خواهد', () => {
    render(
      <ErrorBoundary>
        <ChunkBoom />
      </ErrorBoundary>,
    )

    expect(screen.getByRole('button', { name: /بارگذاری دوباره/ })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^تلاش دوباره$/ })).not.toBeInTheDocument()
  })
})
