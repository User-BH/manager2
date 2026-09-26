import { afterEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import {
  blogPostSchema,
  fromLocalInput,
  parseTags,
  toLocalInput,
} from '@/features/system/blog/schema'
import { ShareButton } from '@/features/landing/blog/ShareButton'
import { isBlogEnabled } from '@/shared/lib/viewer'

/**
 * بلاگ در فرانت (مرحلهٔ ۶ · فاز ۱۱).
 *
 * متنِ مقاله را سرور می‌سازد (Blade + `BlogRenderer`)؛ این‌جا فقط چیزهایی
 * سنجیده می‌شود که واقعاً در مرورگر زندگی می‌کنند: تبدیلِ زمانِ انتشار،
 * برچسب‌ها، قاعده‌ی نامک، دکمه‌ی اشتراک و پیوندِ منو.
 */

const clipboard = vi.hoisted(() => ({ copyText: vi.fn() }))
vi.mock('@/shared/lib/clipboard', () => clipboard)

const navigate = vi.fn()
vi.mock('@/shared/lib/mpaNav', () => ({
  Link: ({ to, children }: { to: string; children?: React.ReactNode }) => (
    <a href={to}>{children}</a>
  ),
  useNavigate: () => navigate,
}))

afterEach(() => {
  document.querySelector('meta[name="blog-enabled"]')?.remove()
  clipboard.copyText.mockReset()
})

function enableBlog() {
  const meta = document.createElement('meta')
  meta.name = 'blog-enabled'
  meta.content = '1'
  document.head.appendChild(meta)
}

describe('فرمِ مقاله', () => {
  it('برچسب‌ها با «،» یا «,» جدا، یکتا و حداکثر ده‌تا', () => {
    expect(parseTags('مالی، شارژ ,مالی ، ')).toEqual(['مالی', 'شارژ'])
    expect(parseTags(Array.from({ length: 15 }, (_, i) => `ب${i}`).join('،'))).toHaveLength(10)
  })

  /**
   * 🔴 زمانِ انتشار به وقتِ مرورگر وارد و به UTC فرستاده می‌شود؛ بدونِ این،
   * «ساعتِ ۱۰» روی سرورِ UTC سه ساعت و نیم دیرتر منتشر می‌شد.
   */
  it('زمانِ محلی به UTC می‌رود و برمی‌گردد', () => {
    const local = '2026-10-01T10:00'
    const iso = fromLocalInput(local)

    expect(iso).toBe(new Date(local).toISOString())
    expect(toLocalInput(iso)).toBe(local)
    expect(fromLocalInput('')).toBeNull()
    expect(toLocalInput(null)).toBe('')
  })

  it('نامکِ فارسی پذیرفته و نویسه‌ی ناروا رد می‌شود', () => {
    const base = {
      title: 'عنوان',
      excerpt: '',
      body: 'متن',
      categoryId: '',
      tags: '',
      status: 'draft' as const,
      publishedAt: '',
      cover: '',
      metaTitle: '',
      metaDescription: '',
    }

    expect(blogPostSchema.safeParse({ ...base, slug: 'شارژ-ساختمان-۱۴۰۵' }).success).toBe(true)
    expect(blogPostSchema.safeParse({ ...base, slug: '' }).success).toBe(true)
    expect(blogPostSchema.safeParse({ ...base, slug: '../admin' }).success).toBe(false)
    expect(blogPostSchema.safeParse({ ...base, slug: 'a b' }).success).toBe(false)
  })
})

describe('دکمه‌ی اشتراک', () => {
  it('کپیِ موفق را می‌گوید', async () => {
    clipboard.copyText.mockResolvedValue(true)
    render(<ShareButton url="https://sakna.test/blog/x" title="x" />)

    fireEvent.click(screen.getByRole('button', { name: /کپیِ پیوند/ }))

    expect(await screen.findByText('پیوند کپی شد.')).toBeInTheDocument()
    expect(clipboard.copyText).toHaveBeenCalledWith('https://sakna.test/blog/x')
  })

  /** ⚠️ همان درسِ فاز ۸: شکستِ کپی گفته می‌شود، نه بلعیده. */
  it('شکستِ کپی را هم می‌گوید', async () => {
    clipboard.copyText.mockResolvedValue(false)
    render(<ShareButton url="https://sakna.test/blog/x" title="x" />)

    fireEvent.click(screen.getByRole('button', { name: /کپیِ پیوند/ }))

    expect(await screen.findByText(/کپی نشد/)).toBeInTheDocument()
  })
})

describe('پیوندِ بلاگ در منو', () => {
  it('فقط وقتی سرور گفته بلاگ روشن است', async () => {
    const { HomeNavbar } = await import('@/features/landing/home/components/HomeNavbar')

    const { unmount } = render(<HomeNavbar />)
    expect(isBlogEnabled()).toBe(false)
    expect(screen.queryByRole('button', { name: 'بلاگ' })).not.toBeInTheDocument()
    unmount()

    enableBlog()
    render(<HomeNavbar />)
    expect(isBlogEnabled()).toBe(true)
    expect(screen.getByRole('button', { name: 'بلاگ' })).toBeInTheDocument()
  })

  it('روی خودِ بلاگ، پیوندِ «بلاگ» نیست', async () => {
    const { HomeNavbar } = await import('@/features/landing/home/components/HomeNavbar')
    enableBlog()

    render(<HomeNavbar page="blog" />)
    expect(screen.queryByRole('button', { name: 'بلاگ' })).not.toBeInTheDocument()
  })
})
