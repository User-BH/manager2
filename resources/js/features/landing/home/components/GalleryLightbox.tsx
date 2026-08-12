import { useCallback, useEffect, useRef, useState } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import { ChevronLeft, ChevronRight, Search, X } from 'lucide-react'
import type { GalleryItem } from '@/shared/constants/images'

/** چند برابر شدنِ تصویر زیر ذره‌بین. */
const ZOOM_FACTOR = 2.4

/**
 * لایت‌باکس گالری.
 *
 * دو چیز از `react-medium-image-zoom` (که قبلاً استفاده می‌شد) نمی‌آمد و
 * باعث شد این نسخه دستی نوشته شود: پنلِ توضیح کنار تصویر، و ذره‌بینی که
 * دقیقاً همان نقطه‌ای را که ماوس رویش است بزرگ می‌کند.
 *
 * بزرگ‌نمایی با `background-position` انجام می‌شود نه با transform: این‌طور
 * فقط ناحیه‌ی زیر نشانگر بزرگ می‌شود و بقیه‌ی تصویر سر جایش می‌ماند.
 */
export function GalleryLightbox({
  items,
  index,
  onClose,
  onNavigate,
}: {
  items: GalleryItem[]
  /** اندیس تصویر باز؛ null یعنی بسته. */
  index: number | null
  onClose: () => void
  onNavigate: (nextIndex: number) => void
}) {
  const isOpen = index !== null
  const item = isOpen ? items[index] : null

  const frameRef = useRef<HTMLDivElement>(null)
  const [lens, setLens] = useState<{ x: number; y: number } | null>(null)

  const goNext = useCallback(() => {
    if (index === null) return
    onNavigate((index + 1) % items.length)
  }, [index, items.length, onNavigate])

  const goPrev = useCallback(() => {
    if (index === null) return
    onNavigate((index - 1 + items.length) % items.length)
  }, [index, items.length, onNavigate])

  // کلیدهای Escape و جهت‌ها؛ و قفل اسکرول پس‌زمینه تا صفحه زیر لایت‌باکس نلغزد
  useEffect(() => {
    if (!isOpen) return

    function handleKey(event: KeyboardEvent) {
      if (event.key === 'Escape') onClose()
      // در چیدمان راست‌به‌چپ، فلش چپ یعنی «بعدی»
      if (event.key === 'ArrowLeft') goNext()
      if (event.key === 'ArrowRight') goPrev()
    }

    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    window.addEventListener('keydown', handleKey)

    return () => {
      document.body.style.overflow = previousOverflow
      window.removeEventListener('keydown', handleKey)
    }
  }, [isOpen, onClose, goNext, goPrev])

  // با عوض شدن تصویر، ذره‌بین باید ریست شود
  useEffect(() => setLens(null), [index])

  function handleMove(event: React.MouseEvent<HTMLDivElement>) {
    const frame = frameRef.current
    if (!frame) return

    const rect = frame.getBoundingClientRect()
    // درصدِ موقعیت نشانگر داخل قاب — همان چیزی که background-position می‌خواهد
    const x = ((event.clientX - rect.left) / rect.width) * 100
    const y = ((event.clientY - rect.top) / rect.height) * 100

    setLens({ x: Math.min(100, Math.max(0, x)), y: Math.min(100, Math.max(0, y)) })
  }

  return (
    <AnimatePresence>
      {isOpen && item && (
        <motion.div
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          transition={{ duration: 0.22 }}
          className="fixed inset-0 z-[100] flex items-center justify-center p-4 sm:p-8"
          style={{ backgroundColor: 'color-mix(in srgb, #05100c 88%, transparent)' }}
          onClick={onClose}
          dir="rtl"
        >
          <motion.div
            initial={{ opacity: 0, scale: 0.94, y: 18 }}
            animate={{ opacity: 1, scale: 1, y: 0 }}
            exit={{ opacity: 0, scale: 0.96, y: 10 }}
            transition={{ duration: 0.28, ease: [0.22, 1, 0.36, 1] }}
            // کلیک داخل کادر نباید لایت‌باکس را ببندد
            onClick={(event) => event.stopPropagation()}
            /*
              ─── باگِ اندازه‌گیری‌شده و رفعش (R33) ────────────────────────
              در ۳۹۰×۵۶۰ گرید تک‌ستونه می‌شد، پس تصویر و متن **زیرِ هم**
              می‌نشستند: تصویر `70vh` + متن `80vh` = ۱۵۰vh در ظرفی با
              `max-h-full` و `overflow-hidden`. نتیجه این بود که پنلِ متن
              تا `y=831` می‌رفت — ۲۷۱ پیکسل بیرون از صفحه — و چون ظرف
              `overflow-hidden` داشت، بریده می‌شد نه اسکرول. یعنی توضیحِ
              هر تصویر در موبایل **اصلاً قابلِ خواندن نبود**.

              سه چیز با هم لازم بود:
                • `dvh` به‌جای `vh` — در موبایل نوارِ آدرسِ مرورگر `vh` را
                  بزرگ‌تر از فضای واقعی گزارش می‌کند.
                • ردیفِ دومِ گرید `minmax(0,1fr)` تا فضای باقیمانده را
                  بگیرد و **بتواند کوچک شود**؛ بدونِ `minmax(0,…)` ردیف
                  به اندازه‌ی محتوایش باز می‌ماند.
                • `min-h-0` روی خودِ پنل، وگرنه `overflow-y-auto` داخلِ
                  گرید هیچ‌وقت فعال نمی‌شود.
            */
            className="grid max-h-[100dvh] w-full max-w-5xl grid-cols-1 grid-rows-[auto_minmax(0,1fr)] overflow-hidden rounded-3xl border shadow-2xl md:grid-rows-1 md:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]"
            style={{
              backgroundColor: 'var(--surface-base)',
              borderColor: 'var(--border-subtle)',
            }}
          >
            {/* ---------- تصویر با ذره‌بین ---------- */}
            <div
              ref={frameRef}
              onMouseMove={handleMove}
              onMouseLeave={() => setLens(null)}
              // در موبایل تصویر سهمِ کمتری می‌گیرد تا متن جا داشته باشد
              className="relative aspect-[4/5] max-h-[42dvh] w-full cursor-zoom-in overflow-hidden md:aspect-auto md:max-h-[80dvh]"
              style={{ backgroundColor: 'var(--surface-sunken)' }}
            >
              <img
                src={item.src}
                alt={item.title}
                width={800}
                height={1000}
                className="h-full w-full object-cover transition-opacity duration-200"
                style={{ opacity: lens ? 0 : 1 }}
                draggable={false}
              />

              {/* لایه‌ی بزرگ‌نمایی: همان تصویر، ولی چند برابر و مرکزشده روی
                  نقطه‌ای که ماوس رویش است. */}
              {lens && (
                <div
                  className="absolute inset-0"
                  style={{
                    backgroundImage: `url(${item.src})`,
                    backgroundSize: `${ZOOM_FACTOR * 100}%`,
                    backgroundPosition: `${lens.x}% ${lens.y}%`,
                    backgroundRepeat: 'no-repeat',
                  }}
                />
              )}

              {!lens && (
                <div
                  className="pointer-events-none absolute bottom-3 right-3 flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold text-white backdrop-blur-sm"
                  style={{ backgroundColor: 'color-mix(in srgb, #05100c 55%, transparent)' }}
                >
                  <Search size={12} />
                  نشانگر را روی تصویر ببرید
                </div>
              )}

              {/* جابه‌جایی بین تصاویر */}
              <button
                onClick={goPrev}
                aria-label="تصویر قبلی"
                className="absolute right-3 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full text-white backdrop-blur-sm transition-transform hover:scale-110"
                style={{ backgroundColor: 'color-mix(in srgb, #05100c 45%, transparent)' }}
              >
                <ChevronRight size={18} />
              </button>
              <button
                onClick={goNext}
                aria-label="تصویر بعدی"
                className="absolute left-3 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full text-white backdrop-blur-sm transition-transform hover:scale-110"
                style={{ backgroundColor: 'color-mix(in srgb, #05100c 45%, transparent)' }}
              >
                <ChevronLeft size={18} />
              </button>
            </div>

            {/* ---------- پنل توضیحات ---------- */}
            <div className="scrollbar-thin relative flex min-h-0 flex-col overflow-y-auto p-6 sm:p-7 md:max-h-[80dvh]">
              <button
                onClick={onClose}
                aria-label="بستن"
                className="absolute left-4 top-4 flex h-8 w-8 items-center justify-center rounded-full transition-colors hover:bg-(--surface-sunken)"
                style={{ color: 'var(--text-tertiary)' }}
              >
                <X size={17} />
              </button>

              <motion.span
                key={`n-${index}`}
                initial={{ opacity: 0, x: 12 }}
                animate={{ opacity: 1, x: 0 }}
                transition={{ duration: 0.3 }}
                className="text-[11px] font-bold tabular-nums"
                style={{ color: 'var(--color-brand-500)' }}
              >
                {toFa(index + 1)} از {toFa(items.length)}
              </motion.span>

              <motion.h3
                key={`t-${index}`}
                initial={{ opacity: 0, x: 16 }}
                animate={{ opacity: 1, x: 0 }}
                transition={{ duration: 0.35, delay: 0.05 }}
                className="mt-2 text-xl font-extrabold sm:text-2xl"
                style={{ color: 'var(--text-primary)' }}
              >
                {item.title}
              </motion.h3>

              <motion.div
                key={`u-${index}`}
                initial={{ width: 0 }}
                animate={{ width: 56 }}
                transition={{ duration: 0.45, delay: 0.12 }}
                className="mt-3 h-1 rounded-full"
                style={{ backgroundColor: 'var(--color-brand-500)' }}
              />

              <motion.p
                key={`d-${index}`}
                initial={{ opacity: 0, y: 12 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.4, delay: 0.15 }}
                className="mt-5 text-[14px] leading-8"
                style={{ color: 'var(--text-secondary)' }}
              >
                {item.description}
              </motion.p>

              <motion.div
                key={`g-${index}`}
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                transition={{ duration: 0.4, delay: 0.25 }}
                className="mt-6 flex flex-wrap gap-2"
              >
                {item.tags.map((tag) => (
                  <span
                    key={tag}
                    className="rounded-full px-3 py-1 text-[11.5px] font-semibold"
                    style={{
                      backgroundColor:
                        'color-mix(in srgb, var(--color-brand-500) 12%, transparent)',
                      color: 'var(--color-brand-600)',
                    }}
                  >
                    {tag}
                  </span>
                ))}
              </motion.div>

              {/* بندانگشتی‌ها برای پرش سریع */}
              <div className="mt-auto flex flex-wrap gap-2 pt-7">
                {items.map((thumb, thumbIndex) => (
                  <button
                    key={thumb.src}
                    onClick={() => onNavigate(thumbIndex)}
                    aria-label={thumb.title}
                    className="h-11 w-9 overflow-hidden rounded-lg border-2 transition-all duration-200 hover:scale-110"
                    style={{
                      borderColor: thumbIndex === index ? 'var(--color-brand-500)' : 'transparent',
                      opacity: thumbIndex === index ? 1 : 0.55,
                    }}
                  >
                    <img
                      src={thumb.src}
                      alt=""
                      loading="lazy"
                      className="h-full w-full object-cover"
                    />
                  </button>
                ))}
              </div>
            </div>
          </motion.div>
        </motion.div>
      )}
    </AnimatePresence>
  )
}

function toFa(value: number): string {
  return String(value).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[Number(d)])
}
