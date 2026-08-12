import { Swiper, SwiperSlide } from 'swiper/react'
import { ContactAvatar } from '@/shared/common/ContactAvatar'
import { Autoplay, Pagination } from 'swiper/modules'
import { Quote } from 'lucide-react'
import { testimonials } from '@/features/landing/content/landingContent'
import { RevealOnScroll } from './RevealOnScroll'

import 'swiper/css'
import 'swiper/css/pagination'

export function TestimonialsSection() {
  return (
    <section id="testimonials" className="mx-auto max-w-4xl px-4 py-20 sm:px-6" dir="rtl">
      <RevealOnScroll className="mx-auto max-w-xl text-center">
        <h2
          className="text-2xl font-extrabold sm:text-3xl"
          style={{ color: 'var(--text-primary)' }}
        >
          تجربه‌ی مدیران مجتمع‌ها
        </h2>
        <p className="mt-3 text-[14.5px] leading-7" style={{ color: 'var(--text-secondary)' }}>
          نظر کسانی که هر روز از این پنل برای مدیریت مجتمع خودشان استفاده می‌کنند
        </p>
      </RevealOnScroll>

      <RevealOnScroll delay={0.15} className="mt-12">
        <Swiper
          modules={[Autoplay, Pagination]}
          slidesPerView={1}
          loop
          autoplay={{ delay: 4500, disableOnInteraction: false }}
          pagination={{ clickable: true }}
          dir="rtl"
          className="!pb-12"
        >
          {testimonials.map((item) => (
            <SwiperSlide key={item.name}>
              <div
                className="mx-auto flex max-w-xl flex-col items-center rounded-3xl border p-8 text-center sm:p-10"
                style={{
                  borderColor: 'var(--border-subtle)',
                  backgroundColor: 'var(--surface-base)',
                }}
              >
                <Quote size={28} style={{ color: 'var(--color-brand-300)' }} />
                <p className="mt-5 text-[15px] leading-8" style={{ color: 'var(--text-primary)' }}>
                  {item.quote}
                </p>

                <div className="mt-6 flex items-center gap-3">
                  {/*
                    آواتارِ تصویری جای عکسِ استوک (R33).

                    عکسِ آدمِ واقعی زیرِ نقلِ‌قولی که نگفته، هم از نظر
                    اخلاقی درست نیست و هم اگر بازدیدکننده همان عکس را
                    جای دیگری ببیند، اعتمادش به کلِ صفحه می‌ریزد.
                  */}
                  <ContactAvatar name={item.name} size={44} className="shrink-0" />
                  <div className="text-right">
                    <p className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
                      {item.name}
                    </p>
                    <p className="text-xs" style={{ color: 'var(--text-tertiary)' }}>
                      {item.role}
                    </p>
                  </div>
                </div>
              </div>
            </SwiperSlide>
          ))}
        </Swiper>
      </RevealOnScroll>
    </section>
  )
}
