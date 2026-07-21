import { Swiper, SwiperSlide } from 'swiper/react'
import { Autoplay, EffectFade, Pagination } from 'swiper/modules'
import { ExternalLink } from 'lucide-react'
import { adSlides } from '@/data/adSlides'

import 'swiper/css'
import 'swiper/css/effect-fade'
import 'swiper/css/pagination'

const AD_DURATION_MS = 7000

export function AdBannerSection() {
  return (
    <section className="mx-auto max-w-6xl px-4 pt-6 sm:px-6">
      <Swiper
        modules={[Autoplay, EffectFade, Pagination]}
        effect="fade"
        fadeEffect={{ crossFade: true }}
        loop
        autoplay={{ delay: AD_DURATION_MS, disableOnInteraction: false, pauseOnMouseEnter: true }}
        pagination={{ clickable: true }}
        dir="rtl"
        className="ad-banner-swiper overflow-hidden rounded-3xl shadow-lg"
      >
        {adSlides.map((ad) => (
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
                  <p className="mt-1 text-xs leading-6 text-white/90 sm:text-[13px]">{ad.subtitle}</p>
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
