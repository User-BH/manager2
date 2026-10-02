import { useEffect, useState } from 'react'
import { api } from '@/shared/lib/api'
import { contactInfo, socialLinks } from '@/shared/config/brand'

/**
 * راه‌های تماسِ واقعی — همان چیزی که ادمینِ کل در «فوتر و شبکه‌ها» ثبت می‌کند
 * (مرحلهٔ ۷ · فاز ۴).
 *
 * فوتر و صفحه‌ی پشتیبانی هر دو از همین می‌خوانند. پیش از این فقط فوتر
 * می‌خواند و متنِ پشتیبانی شماره و ایمیلِ خودش را جدا می‌نوشت — یعنی دو منبع
 * که دیر یا زود از هم جدا می‌افتادند.
 */
export interface SiteContact {
  title: string
  address: string | null
  phone: string | null
  email: string | null
  mapEmbedUrl: string | null
}

export interface SiteSocial {
  id: string
  label: string
  href: string
}

export interface SiteContent {
  contact: SiteContact
  social: SiteSocial[]
}

/** پیش‌فرض تا پیش از رسیدنِ پاسخ، و اگر سرور در دسترس نبود. */
export const DEFAULT_SITE_CONTENT: SiteContent = {
  contact: {
    title: 'ارتباط با ما',
    address: null,
    phone: contactInfo.phone,
    email: null,
    mapEmbedUrl: null,
  },
  social: socialLinks.map((s) => ({ id: s.id, label: s.label, href: s.href })),
}

/*
 * ⚠️ یک درخواست برای کلِ صفحه، هرچند چند کامپوننت بخواهندش. شکست هم کش
 * نمی‌شود تا بازدیدِ بعدی دوباره امتحان کند.
 */
let pending: Promise<SiteContent> | null = null

function load(): Promise<SiteContent> {
  pending ??= api<SiteContent>('/site-settings').catch((error: unknown) => {
    pending = null
    throw error
  })
  return pending
}

/** فقط برای تست: هر تست درخواستِ خودش را ببیند. */
export function resetSiteContentCache(): void {
  pending = null
}

export function useSiteContent(): SiteContent {
  const [content, setContent] = useState<SiteContent>(DEFAULT_SITE_CONTENT)

  useEffect(() => {
    let alive = true

    load()
      .then((data) => {
        if (alive) setContent(data)
      })
      .catch(() => {
        // پیش‌فرض می‌ماند؛ فوتر و تماس هرگز به‌خاطرِ این خالی نمی‌شوند
      })

    return () => {
      alive = false
    }
  }, [])

  return content
}
