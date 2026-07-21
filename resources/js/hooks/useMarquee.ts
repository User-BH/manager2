import { useEffect, useRef } from 'react'

interface UseMarqueeOptions {
  /** سرعت حرکت بر حسب پیکسل در ثانیه */
  speed?: number
  /** جهت حرکت - rtl یعنی محتوا از چپ به راست منتقل می‌شود (مناسب چیدمان راست‌به‌چپ) */
  direction?: 'rtl' | 'ltr'
  enabled?: boolean
}

/**
 * یک نوار بی‌نهایت (marquee) با حرکت کاملاً یکنواخت می‌سازد.
 * محتوای داخل track باید دو بار تکرار شده باشد (یا بیشتر) تا وقتی نیمه‌ی اول
 * به انتها رسید، نیمه‌ی دوم دقیقاً جای آن را بگیرد و هیچ پرش/مکثی دیده نشود.
 *
 * برخلاف autoplay داخلی Swiper، اینجا خود ما هر فریم موقعیت را با
 * requestAnimationFrame جلو می‌بریم، پس هیچ "easing" یا مکث بین چرخه‌ها وجود ندارد.
 */
export function useMarquee<T extends HTMLElement>({
  speed = 60,
  direction = 'rtl',
  enabled = true,
}: UseMarqueeOptions = {}) {
  const trackRef = useRef<T>(null)
  const offsetRef = useRef(0)
  const pausedRef = useRef(false)

  useEffect(() => {
    if (!enabled) return
    const track = trackRef.current
    if (!track) return
    const trackElement = track

    let frameId: number
    let lastTime: number | null = null
    const sign = direction === 'rtl' ? 1 : -1

    function tick(now: number) {
      if (lastTime === null) lastTime = now
      const delta = (now - lastTime) / 1000
      lastTime = now

      if (!pausedRef.current) {
        offsetRef.current += speed * delta

        // نیمه‌ی محتوا دقیقاً یک نسخه‌ی تکراری است؛ وقتی به اندازه‌ی نیمی از کل عرض رسیدیم
        // بدون transition به صفر برمی‌گردیم، چون نقطه‌ی شروع نیمه‌ی دوم بصری یکسان با ابتدای نیمه‌ی اول است
        const halfWidth = trackElement.scrollWidth / 2
        if (halfWidth > 0 && offsetRef.current >= halfWidth) {
          offsetRef.current -= halfWidth
        }

        trackElement.style.transform = `translate3d(${sign * -offsetRef.current}px, 0, 0)`
      }

      frameId = requestAnimationFrame(tick)
    }

    frameId = requestAnimationFrame(tick)
    return () => cancelAnimationFrame(frameId)
  }, [speed, direction, enabled])

  return {
    trackRef,
    pause: () => {
      pausedRef.current = true
    },
    resume: () => {
      pausedRef.current = false
    },
  }
}
