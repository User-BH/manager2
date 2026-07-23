import { useEffect, useState } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import { ArrowUp } from 'lucide-react'
import { scrollToTop } from '@/lib/scroll'
import { SupportChat } from './SupportChat'

/**
 * دو دکمه‌ی شناور گوشه‌ی پایین-راست صفحه‌ی فرود.
 *
 * دکمه‌ی «بالا» فقط بعد از کمی اسکرول ظاهر می‌شود؛ وقتی کاربر بالای صفحه است
 * دلیلی برای نمایشش نیست و فقط شلوغی می‌کند. دکمه‌ی پشتیبانی همیشه هست چون
 * ممکن است کاربر همان ابتدا سوال داشته باشد.
 */
export function FloatingActions() {
  const [showTop, setShowTop] = useState(false)

  useEffect(() => {
    const onScroll = () => setShowTop(window.scrollY > 600)

    onScroll()
    window.addEventListener('scroll', onScroll, { passive: true })

    return () => window.removeEventListener('scroll', onScroll)
  }, [])

  return (
    <>
      {/* دکمه‌ی بازگشت به بالا — بالای دکمه‌ی پشتیبانی می‌نشیند */}
      <AnimatePresence>
        {showTop && (
          <motion.button
            onClick={scrollToTop}
            aria-label="بازگشت به بالای صفحه"
            title="بازگشت به بالا"
            initial={{ opacity: 0, scale: 0.8, y: 10 }}
            animate={{ opacity: 1, scale: 1, y: 0 }}
            exit={{ opacity: 0, scale: 0.8, y: 10 }}
            whileHover={{ scale: 1.08 }}
            whileTap={{ scale: 0.94 }}
            className="fixed bottom-[6.25rem] right-5 z-40 flex h-11 w-11 items-center justify-center rounded-full border shadow-lg backdrop-blur"
            style={{
              backgroundColor: 'color-mix(in srgb, var(--surface-base) 88%, transparent)',
              borderColor: 'var(--border-subtle)',
              color: 'var(--color-brand-600)',
            }}
          >
            <ArrowUp size={20} />
          </motion.button>
        )}
      </AnimatePresence>

      <SupportChat />
    </>
  )
}
