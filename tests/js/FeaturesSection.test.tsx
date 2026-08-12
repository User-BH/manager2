import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'

import { FeaturesSection } from '@/features/landing/home/components/FeaturesSection'
import { features } from '@/features/landing/content/landingContent'
import { galleryItems } from '@/shared/constants/images'

/**
 * هشت کارتِ ویژگی و گالریِ توضیح‌دار (R32).
 *
 * ─── چرا این تست‌ها وجود دارند ──────────────────────────────────────────────
 * این بخش «ویترین» است و خرابی‌اش خطا نمی‌دهد: کارتی که عکسش نیاید فقط
 * یک قابِ خالی است، و متنی که وعده‌ی فیچرِ نساخته بدهد فقط وقتی معلوم
 * می‌شود که کاربر ثبت‌نام کرده باشد. پس شکلِ داده اینجا قفل می‌شود.
 */
describe('کارت‌های ویژگی', () => {
  it('دقیقاً هشت کارت دارد', () => {
    expect(features).toHaveLength(8)
  })

  it('هر کارت عنوان و توضیحِ پرمحتوا دارد', () => {
    for (const feature of features) {
      expect(feature.title.trim().length).toBeGreaterThan(8)

      /*
       * توضیحِ کوتاه یعنی شعار، نه توضیح. حدِ پایین عمدی است تا کسی
       * کارتی با «مدیریت هوشمند» اضافه نکند.
       */
      expect(feature.description.trim().length).toBeGreaterThan(60)
      expect(feature.icon).toBeTruthy()
    }
  })

  it('عنوان‌ها تکراری نیستند', () => {
    const titles = features.map((f) => f.title)

    expect(new Set(titles).size).toBe(titles.length)
  })

  /**
   * چهار کارتِ تازه هنوز عکس ندارند و کارفرما تهیه‌شان را به بعد موکول
   * کرده. مهم این است که هیچ عکسی **دو بار** استفاده نشود؛ تکرارِ عکس
   * بدتر از نبودنش است چون شبیهِ اشتباه به‌نظر می‌رسد.
   */
  it('هیچ عکسی بین دو کارت تکرار نشده', () => {
    const images = features.map((f) => f.image).filter(Boolean)

    expect(new Set(images).size).toBe(images.length)
  })

  it('همه‌ی هشت کارت رندر می‌شوند، با عکس یا بدونِ آن', () => {
    render(<FeaturesSection />)

    for (const feature of features) {
      expect(screen.getByRole('heading', { name: feature.title })).toBeInTheDocument()
    }
  })

  it('کارتِ بی‌عکس تصویرِ شکسته نمی‌سازد', () => {
    render(<FeaturesSection />)

    const withImage = features.filter((f) => f.image).length

    // فقط کارت‌هایی که واقعاً عکس دارند <img> می‌گیرند
    expect(screen.getAllByRole('img')).toHaveLength(withImage)
  })
})

describe('گالری', () => {
  it('هر قاب توضیح و برچسب دارد، نه فقط تصویر', () => {
    for (const item of galleryItems) {
      expect(item.title.trim()).not.toBe('')
      // خواسته‌ی R32: گالری باید شرحِ ویژگی باشد نه آلبومِ تزئینی
      expect(item.description.trim().length).toBeGreaterThan(60)
      expect(item.tags.length).toBeGreaterThan(0)
    }
  })
})
