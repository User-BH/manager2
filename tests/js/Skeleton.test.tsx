import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import {
  CardListSkeleton,
  DashboardSkeleton,
  FormSkeleton,
  TableSkeleton,
} from '@/shared/ui/Skeleton'

/**
 * قرارداد اسکلتون‌ها.
 *
 * دو چیز اینجا سنجیده می‌شود و هر دو در عمل شکستنی‌اند:
 *
 * ۱. **دسترس‌پذیری** — وضعیتِ بارگذاری باید *یک بار* اعلام شود. اگر هر بلوکِ
 *    خاکستری برای صفحه‌خوان دیدنی بماند، کاربرِ نابینا ده‌ها عنصرِ بی‌معنا
 *    می‌شنود؛ بدتر از نبودنِ اسکلتون.
 * ۲. **شکل‌پذیری** — تعدادِ ردیف/فیلد باید واقعاً از prop پیروی کند، وگرنه
 *    اسکلتون هم‌اندازه‌ی محتوا نمی‌شود و با رسیدنِ داده صفحه می‌پرد.
 */

const skeletons = [
  ['TableSkeleton', <TableSkeleton key="t" />],
  ['DashboardSkeleton', <DashboardSkeleton key="d" />],
  ['FormSkeleton', <FormSkeleton key="f" />],
  ['CardListSkeleton', <CardListSkeleton key="c" />],
] as const

describe('اسکلتون‌ها — دسترس‌پذیری', () => {
  it.each(skeletons)('%s وضعیت را دقیقاً یک بار اعلام می‌کند', (_name, element) => {
    render(element)

    const statuses = screen.getAllByRole('status')
    expect(statuses).toHaveLength(1)
    expect(statuses[0]).toHaveAttribute('aria-label', 'در حال بارگذاری')
  })

  it.each(skeletons)('%s بلوک‌های تزئینی را از صفحه‌خوان پنهان می‌کند', (_name, element) => {
    const { container } = render(element)

    const bars = container.querySelectorAll('.animate-pulse')
    expect(bars.length).toBeGreaterThan(0)
    // اگر یکی از این‌ها aria-hidden نداشته باشد، صفحه‌خوان نویز می‌خواند
    expect([...bars].every((bar) => bar.hasAttribute('aria-hidden'))).toBe(true)
  })
})

describe('اسکلتون‌ها — شکل‌پذیری', () => {
  it('TableSkeleton تعدادِ خواسته‌شده ردیف و ستون می‌سازد', () => {
    const { container } = render(<TableSkeleton rows={5} columns={3} />)

    // ۵ ردیف × ۳ ستون + ۳ سرستون
    expect(container.querySelectorAll('.animate-pulse')).toHaveLength(5 * 3 + 3)
  })

  it('FormSkeleton برای هر فیلد یک برچسب و یک ورودی می‌گذارد', () => {
    const { container } = render(<FormSkeleton fields={4} />)

    // ۴ × (برچسب + ورودی) + دکمه‌ی ثبت
    expect(container.querySelectorAll('.animate-pulse')).toHaveLength(4 * 2 + 1)
  })

  it('CardListSkeleton به تعدادِ خواسته‌شده کارت می‌سازد', () => {
    const { container } = render(<CardListSkeleton items={3} />)

    // هر کارت ۴ بلوک دارد (عنوان، تاریخ، دو خط متن)
    expect(container.querySelectorAll('.animate-pulse')).toHaveLength(3 * 4)
  })

  it('DashboardSkeleton چهار کارتِ آمار و دو نمودار دارد', () => {
    const { container } = render(<DashboardSkeleton />)

    // ۴ کارت × ۲ بلوک + ۲ نمودار
    expect(container.querySelectorAll('.animate-pulse')).toHaveLength(4 * 2 + 2)
  })
})
