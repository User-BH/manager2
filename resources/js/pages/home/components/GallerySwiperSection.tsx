import { useState, type CSSProperties } from 'react'
import { ZoomIn } from 'lucide-react'
import { galleryItems } from '@/data/images'
import { RevealOnScroll } from './RevealOnScroll'
import { GalleryLightbox } from './GalleryLightbox'

// این سه مقدار باید دقیقاً با مقادیر متناظرشان در index.css (.gallery-marquee-item) یکی باشند
const ITEM_WIDTH_PX = 280
const ITEM_GAP_PX = 20
const ITEMS_PER_BLOCK = galleryItems.length

// عرض دقیق px یک «بلوک» کامل از تصاویر اصلی - این عدد دقیقاً مسافتی است که
// انیمیشن باید جابه‌جا شود تا چرخه بدون درز و بدون خطای انباشتی تکرار شود.
// (محاسبه بر مبنای px مطلق، نه درصدی از کل عرض مسیر، تا هیچ خطای رُند‌شدنی رخ ندهد)
const BLOCK_WIDTH_PX = ITEMS_PER_BLOCK * (ITEM_WIDTH_PX + ITEM_GAP_PX)

// آرایه سه بار پشت‌سرهم تکرار می‌شود تا حتی در صفحه‌نمایش‌های خیلی عریض هم
// همیشه حداقل یک کپی کامل دیگر در ادامه‌ی مسیر دیده شود و هیچ‌گاه فضای خالی نیاید
const REPEAT_COUNT = 3
const loopedImages = Array.from({ length: REPEAT_COUNT }).flatMap(() => galleryItems)

// سرعت ثابت حرکت: حدود ۵۷ پیکسل بر ثانیه، فارغ از اینکه چند تصویر در گالری باشد
const PIXELS_PER_SECOND = 57
const ANIMATION_DURATION_SECONDS = BLOCK_WIDTH_PX / PIXELS_PER_SECOND

const trackStyle = {
  '--gallery-block-width': `${BLOCK_WIDTH_PX}px`,
  '--gallery-marquee-duration': `${ANIMATION_DURATION_SECONDS}s`,
} as CSSProperties

export function GallerySwiperSection() {
  const [openIndex, setOpenIndex] = useState<number | null>(null)

  return (
    <section id="gallery" className="overflow-hidden py-20" style={{ backgroundColor: 'var(--surface-sunken)' }}>
      <div className="mx-auto max-w-6xl px-4 sm:px-6" dir="rtl">
        <RevealOnScroll className="mx-auto max-w-xl text-center">
          <h2 className="text-2xl font-extrabold sm:text-3xl" style={{ color: 'var(--text-primary)' }}>
            نگاهی به فضای مجتمع‌ها
          </h2>
          <p className="mt-3 text-[14.5px] leading-7" style={{ color: 'var(--text-secondary)' }}>
            برای دیدن تصویر در حالت بزرگ‌نمایی، روی هر کدام کلیک کنید
          </p>
        </RevealOnScroll>
      </div>

      <div className="mt-12">
        {/* نوار بی‌نهایت (marquee) با انیمیشن CSS خام - تضمین حرکت کاملاً یکنواخت و بدون مکث.
            dir="ltr" روی خود ویوپورت صریح است تا کلیپ/اورفلو مستقل از جهت RTL صفحه باشد. */}
        <div className="gallery-marquee-viewport" dir="ltr">
          <div className="gallery-marquee-track" style={trackStyle}>
            {loopedImages.map((item, index) => (
              <button
                key={index}
                onClick={() => setOpenIndex(index % galleryItems.length)}
                aria-label={`بزرگ‌نمایی: ${item.title}`}
                className="gallery-marquee-item group"
              >
                <img
                  src={item.src}
                  alt={item.title}
                  width={800}
                  height={1000}
                  loading="lazy"
                  decoding="async"
                  className="h-full w-full cursor-zoom-in object-cover transition-transform duration-700 group-hover:scale-110"
                  draggable={false}
                />

                {/* پوشش هاور: آیکون ذره‌بین + عنوانِ همان تصویر */}
                <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-2 bg-black/0 opacity-0 transition-opacity duration-300 group-hover:bg-black/35 group-hover:opacity-100">
                  <ZoomIn size={26} className="text-white drop-shadow" />
                  <span className="px-3 text-center text-[12.5px] font-bold text-white drop-shadow">
                    {item.title}
                  </span>
                </div>
              </button>
            ))}
          </div>
        </div>
      </div>

      <GalleryLightbox
        items={galleryItems}
        index={openIndex}
        onClose={() => setOpenIndex(null)}
        onNavigate={setOpenIndex}
      />
    </section>
  )
}
