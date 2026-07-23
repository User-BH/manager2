import { useEffect, useState } from 'react'
import { Swiper, SwiperSlide } from 'swiper/react'
import { Autoplay, EffectFade, Pagination } from 'swiper/modules'
import { ExternalLink } from 'lucide-react'
import { api } from '@/lib/api'

import 'swiper/css'
import 'swiper/css/effect-fade'
import 'swiper/css/pagination'

const AD_DURATION_MS = 7000

export interface AdSlide {
  id: number
  title: string
  subtitle: string | null
  href: string
  image: string
}

/**
 * بنرهای تبلیغاتی صفحه‌ی فرود.
 *
 * داده از پنل مدیریت می‌آید (`/api/ads`)، نه از فایل ثابت؛ پس ادمین کل
 * می‌تواند بدون بیلد و استقرار دوباره، تبلیغ اضافه یا کم کند.
 */
export function AdBannerSection() {
  const [ads, setAds] = useState<AdSlide[] | null>(null)

  useEffect(() => {
    const controller = new AbortController()

    api<{ ads: AdSlide[] }>('/ads', { signal: controller.signal })
      .then((data) => setAds(data.ads))
      // تبلیغات بخش فرعی صفحه‌اند؛ خطایشان نباید به کاربر هشدار بدهد،
      // فقط بخش نمایش داده نمی‌شود.
      .catch(() => setAds([]))

    return () => controller.abort()
  }, [])

  // در حال بارگذاری: قابی هم‌اندازه‌ی بنر تا صفحه هنگام رسیدن داده نپرد
  if (ads === null) {
    return (
      <section className="mx-auto max-w-6xl px-4 pt-6 sm:px-6" aria-hidden>
        <div className="h-44 w-full animate-pulse rounded-3xl bg-[var(--surface-2)] sm:h-56 lg:h-64" />
      </section>
    )
  }

  if (ads.length === 0) return null

  return (
    <section className="mx-auto max-w-6xl px-4 pt-6 sm:px-6">
      <Swiper
        modules={[Autoplay, EffectFade, Pagination]}
        effect="fade"
        fadeEffect={{ crossFade: true }}
        // با یک اسلاید، حلقه و صفحه‌بندی بی‌معنی است
        loop={ads.length > 1}
        autoplay={
          ads.length > 1
            ? { delay: AD_DURATION_MS, disableOnInteraction: false, pauseOnMouseEnter: true }
            : false
        }
        pagination={ads.length > 1 ? { clickable: true } : false}
        dir="rtl"
        className="ad-banner-swiper overflow-hidden rounded-3xl shadow-lg"
      >
        {ads.map((ad) => (
          <SwiperSlide key={ad.id}>
            <a
              href={ad.href}
              target="_blank"
              rel="noopener noreferrer sponsored"
              className="group relative block h-44 w-full sm:h-56 lg:h-64"
              aria-label={`تبلیغ: ${ad.title}`}
            >
              <img
                src={ad.image}
                alt={ad.title}
                width={1600}
                height={520}
                loading="lazy"
                decoding="async"
                className="absolute inset-0 h-full w-full object-cover transition-transform duration-700 group-hover:scale-105"
                draggable={false}
              />
              {/* گرادینت قوی‌تر و پخش‌تر روی کل تصویر - زمینه‌ی عمومی متن */}
              <div
                className="absolute inset-0"
                style={{
                  background:
                    'linear-gradient(90deg, color-mix(in srgb, black 75%, transparent) 0%, color-mix(in srgb, black 45%, transparent) 45%, color-mix(in srgb, black 10%, transparent) 75%, transparent 100%)',
                }}
              />

              <div className="absolute inset-0 flex flex-col items-start justify-center gap-2 px-6 text-right sm:px-10" dir="rtl">
                {/* پس‌زمینه‌ی مجزا و مات پشت بلوک متن - مستقل از رنگ خود تصویر همیشه خوانا می‌ماند */}
                <div
                  className="max-w-md rounded-2xl px-4 py-3.5 backdrop-blur-md sm:px-5 sm:py-4"
                  style={{ backgroundColor: 'color-mix(in srgb, black 38%, transparent)' }}
                >
                  <span className="inline-block rounded-full bg-white/25 px-2.5 py-0.5 text-[10px] font-semibold text-white">
                    تبلیغ
                  </span>
                  <h3 className="mt-2 text-lg font-extrabold text-white drop-shadow-sm sm:text-xl">
                    {ad.title}
                  </h3>
                  {ad.subtitle && (
                    <p className="mt-1 text-xs leading-6 text-white/90 sm:text-[13px]">{ad.subtitle}</p>
                  )}
                  <span className="mt-2 inline-flex items-center gap-1.5 text-xs font-semibold text-white">
                    مشاهده پیشنهاد
                    <ExternalLink size={13} />
                  </span>
                </div>
              </div>
            </a>
          </SwiperSlide>
        ))}
      </Swiper>
    </section>
  )
}
