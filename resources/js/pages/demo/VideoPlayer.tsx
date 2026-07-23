import { useCallback, useEffect, useRef, useState } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import {
  Image as ImageIcon,
  Maximize,
  Minimize,
  Pause,
  Play,
  RotateCcw,
  Volume2,
  VolumeX,
} from 'lucide-react'
import { galleryItems } from '@/data/images'
import { toPersianDigits } from '@/lib/format'

export interface VideoChapter {
  /** ثانیه‌ی شروع فصل در ویدیو. */
  at: number
  label: string
}

/**
 * پخش‌کننده‌ی ویدیوی سفارشی.
 *
 * کنترل‌های پیش‌فرض مرورگر (`controls`) عمداً استفاده نشده‌اند: ظاهرشان در هر
 * مرورگر فرق می‌کند، فارسی نیستند و با پالت سایت جور در نمی‌آیند. اینجا همان
 * امکانات با ظاهر خودمان ساخته شده: پخش/مکث، نوار پیشرفت قابل‌کشیدن، صدا،
 * تمام‌صفحه و فصل‌بندی.
 *
 * اگر فایل ویدیو موجود نباشد (`onError`) به‌جای پخش‌کننده‌ی خراب، یک پیام
 * راهنما نشان داده می‌شود تا صفحه بی‌استفاده نشود.
 */
export function VideoPlayer({
  src,
  poster,
  chapters = [],
  onMissing,
}: {
  src: string
  poster: string
  chapters?: VideoChapter[]
  onMissing?: () => void
}) {
  const videoRef = useRef<HTMLVideoElement>(null)
  const shellRef = useRef<HTMLDivElement>(null)

  const [playing, setPlaying] = useState(false)
  const [muted, setMuted] = useState(false)
  const [progress, setProgress] = useState(0)
  const [duration, setDuration] = useState(0)
  const [fullscreen, setFullscreen] = useState(false)
  const [failed, setFailed] = useState(false)
  const [ended, setEnded] = useState(false)

  const togglePlay = useCallback(() => {
    const video = videoRef.current
    if (!video) return

    if (video.paused) {
      void video.play()
    } else {
      video.pause()
    }
  }, [])

  const seekTo = useCallback((seconds: number) => {
    const video = videoRef.current
    if (!video) return

    video.currentTime = seconds
    if (video.paused) void video.play()
  }, [])

  // میان‌بُرهای صفحه‌کلید، مثل پخش‌کننده‌های واقعی
  useEffect(() => {
    function handleKey(event: KeyboardEvent) {
      const target = event.target as HTMLElement | null
      if (target && /^(INPUT|TEXTAREA)$/.test(target.tagName)) return

      const video = videoRef.current
      if (!video) return

      if (event.key === ' ' || event.key === 'k') {
        event.preventDefault()
        togglePlay()
      }
      if (event.key === 'ArrowLeft') video.currentTime = Math.max(0, video.currentTime - 5)
      if (event.key === 'ArrowRight') video.currentTime = Math.min(video.duration, video.currentTime + 5)
      if (event.key === 'm') setMuted((prev) => !prev)
    }

    window.addEventListener('keydown', handleKey)
    return () => window.removeEventListener('keydown', handleKey)
  }, [togglePlay])

  useEffect(() => {
    function onFullscreenChange() {
      setFullscreen(document.fullscreenElement === shellRef.current)
    }
    document.addEventListener('fullscreenchange', onFullscreenChange)
    return () => document.removeEventListener('fullscreenchange', onFullscreenChange)
  }, [])

  useEffect(() => {
    const video = videoRef.current
    if (video) video.muted = muted
  }, [muted])

  /*
   * خطای بارگذاری ممکن است پیش از وصل‌شدنِ onError رخ دهد (مرورگر خیلی زود
   * می‌فهمد فایل نیست). پس یک‌بار هم پس از mount مستقیم `video.error` را
   * می‌خوانیم؛ وگرنه پخش‌کننده‌ی خالی باقی می‌ماند و پیام راهنما نمی‌آید.
   */
  useEffect(() => {
    const video = videoRef.current
    if (!video) return

    function markFailed() {
      setFailed(true)
      onMissing?.()
    }

    if (video.error) {
      markFailed()
      return
    }

    video.addEventListener('error', markFailed)
    return () => video.removeEventListener('error', markFailed)
  }, [onMissing])

  function toggleFullscreen() {
    if (document.fullscreenElement) {
      void document.exitFullscreen()
    } else {
      void shellRef.current?.requestFullscreen()
    }
  }

  if (failed) {
    return <MissingVideoNotice poster={poster} />
  }

  const percent = duration > 0 ? (progress / duration) * 100 : 0

  return (
    <div
      ref={shellRef}
      className="group relative overflow-hidden rounded-3xl border shadow-2xl"
      style={{ borderColor: 'var(--border-subtle)', backgroundColor: '#05100c' }}
    >
      <video
        ref={videoRef}
        src={src}
        poster={poster}
        playsInline
        preload="metadata"
        className="aspect-video w-full bg-black object-cover"
        onClick={togglePlay}
        onPlay={() => {
          setPlaying(true)
          setEnded(false)
        }}
        onPause={() => setPlaying(false)}
        onEnded={() => setEnded(true)}
        onTimeUpdate={(event) => setProgress(event.currentTarget.currentTime)}
        onLoadedMetadata={(event) => setDuration(event.currentTarget.duration)}
        onError={() => {
          setFailed(true)
          onMissing?.()
        }}
      />

      {/* دکمه‌ی بزرگ پخش وسط تصویر — فقط وقتی متوقف است */}
      <AnimatePresence>
        {!playing && (
          <motion.button
            initial={{ opacity: 0, scale: 0.8 }}
            animate={{ opacity: 1, scale: 1 }}
            exit={{ opacity: 0, scale: 0.8 }}
            transition={{ duration: 0.2 }}
            onClick={togglePlay}
            aria-label={ended ? 'پخش دوباره' : 'پخش ویدیو'}
            className="absolute inset-0 z-10 flex items-center justify-center"
          >
            <span
              className="flex h-20 w-20 items-center justify-center rounded-full text-white shadow-2xl backdrop-blur-sm transition-transform duration-200 hover:scale-110"
              style={{ backgroundColor: 'color-mix(in srgb, var(--color-brand-500) 88%, transparent)' }}
            >
              {ended ? <RotateCcw size={30} /> : <Play size={32} className="ml-[-3px]" fill="currentColor" />}
            </span>

            {/* حلقه‌ی تپنده دور دکمه */}
            <motion.span
              animate={{ scale: [1, 1.35], opacity: [0.5, 0] }}
              transition={{ duration: 1.8, repeat: Infinity, ease: 'easeOut' }}
              className="absolute h-20 w-20 rounded-full"
              style={{ border: '2px solid var(--color-brand-300)' }}
            />
          </motion.button>
        )}
      </AnimatePresence>

      {/* نوار کنترل — با هاور یا هنگام توقف دیده می‌شود */}
      <div
        className="absolute inset-x-0 bottom-0 z-20 translate-y-full p-3 transition-transform duration-300 group-hover:translate-y-0 sm:p-4"
        style={{
          background: 'linear-gradient(to top, rgba(5,16,12,0.92), rgba(5,16,12,0.55) 60%, transparent)',
          transform: playing ? undefined : 'translateY(0)',
        }}
        dir="ltr"
      >
        {/* نوار پیشرفت */}
        <div className="relative mb-3">
          <input
            type="range"
            min={0}
            max={duration || 0}
            step={0.1}
            value={progress}
            onChange={(event) => seekTo(Number(event.target.value))}
            aria-label="موقعیت پخش"
            className="demo-progress w-full"
            style={{ ['--played' as string]: `${percent}%` }}
          />

          {/* نشانگر فصل‌ها روی نوار */}
          {duration > 0 &&
            chapters.map((chapter) => (
              <button
                key={chapter.at}
                onClick={() => seekTo(chapter.at)}
                title={chapter.label}
                aria-label={`رفتن به ${chapter.label}`}
                className="absolute top-1/2 h-2.5 w-0.5 -translate-y-1/2 rounded-full bg-white/70 transition-transform hover:scale-y-150"
                style={{ left: `${(chapter.at / duration) * 100}%` }}
              />
            ))}
        </div>

        <div className="flex items-center gap-3">
          <button onClick={togglePlay} aria-label={playing ? 'مکث' : 'پخش'} className="text-white transition-transform hover:scale-110">
            {playing ? <Pause size={19} fill="currentColor" /> : <Play size={19} fill="currentColor" />}
          </button>

          <button onClick={() => setMuted((prev) => !prev)} aria-label={muted ? 'صدادار' : 'بی‌صدا'} className="text-white transition-transform hover:scale-110">
            {muted ? <VolumeX size={19} /> : <Volume2 size={19} />}
          </button>

          <span className="font-mono text-[11.5px] tabular-nums text-white/85" dir="ltr">
            {formatTime(progress)} / {formatTime(duration)}
          </span>

          <button
            onClick={toggleFullscreen}
            aria-label={fullscreen ? 'خروج از تمام‌صفحه' : 'تمام‌صفحه'}
            className="ml-auto text-white transition-transform hover:scale-110"
          >
            {fullscreen ? <Minimize size={18} /> : <Maximize size={18} />}
          </button>
        </div>
      </div>
    </div>
  )
}

/**
 * جایگزین وقتی فایل ویدیو روی سرور نیست.
 *
 * نسخه‌ی قبلی به بازدیدکننده می‌گفت فایل را در `public/videos` بگذارد — یعنی
 * دستورالعمل داخلیِ توسعه روی یک صفحه‌ی عمومی. حالا به‌جایش یک تور تصویری
 * خودکار از خودِ مجتمع نشان داده می‌شود که برای بازدیدکننده معنا دارد، و صفحه
 * حتی بدون ویدیو هم کارِ خودش را می‌کند.
 */
function MissingVideoNotice({ poster }: { poster: string }) {
  const slides = galleryItems.slice(0, 5)
  const [index, setIndex] = useState(0)
  const [paused, setPaused] = useState(false)

  useEffect(() => {
    if (paused) return

    const timer = setInterval(() => setIndex((i) => (i + 1) % slides.length), 4500)

    return () => clearInterval(timer)
  }, [paused, slides.length])

  const slide = slides[index] ?? { src: poster, title: '', description: '', tags: [] }

  return (
    <div
      className="relative aspect-video w-full overflow-hidden rounded-3xl border"
      style={{ borderColor: 'var(--border-subtle)' }}
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
    >
      <AnimatePresence mode="wait">
        <motion.img
          key={slide.src}
          src={slide.src}
          alt={slide.title}
          initial={{ opacity: 0, scale: 1.04 }}
          animate={{ opacity: 1, scale: 1 }}
          exit={{ opacity: 0 }}
          transition={{ duration: 0.7, ease: [0.22, 1, 0.36, 1] }}
          className="absolute inset-0 h-full w-full object-cover"
        />
      </AnimatePresence>

      <div
        className="absolute inset-0"
        style={{
          background:
            'linear-gradient(0deg, color-mix(in srgb, #05100c 88%, transparent) 0%, color-mix(in srgb, #05100c 30%, transparent) 55%, transparent 100%)',
        }}
      />

      <div className="absolute inset-x-0 bottom-0 p-5 sm:p-7" dir="rtl">
        <span className="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-2.5 py-1 text-[11px] font-semibold text-white backdrop-blur">
          <ImageIcon size={12} />
          تور تصویری
        </span>

        <AnimatePresence mode="wait">
          <motion.div
            key={slide.title}
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -8 }}
            transition={{ duration: 0.4 }}
          >
            <h3 className="mt-2.5 text-lg font-extrabold text-white sm:text-xl">{slide.title}</h3>
            <p className="mt-1.5 max-w-xl text-[12.5px] leading-7 text-white/80 sm:text-[13.5px]">
              {slide.description}
            </p>
          </motion.div>
        </AnimatePresence>

        {/* نشانگر اسلاید، هم‌زمان دکمه‌ی پرش */}
        <div className="mt-4 flex items-center gap-1.5">
          {slides.map((s, i) => (
            <button
              key={s.src}
              onClick={() => setIndex(i)}
              aria-label={`اسلاید ${i + 1}: ${s.title}`}
              className="h-1 rounded-full transition-all duration-300"
              style={{
                width: i === index ? 28 : 12,
                backgroundColor: i === index ? '#fff' : 'rgba(255,255,255,0.35)',
              }}
            />
          ))}
        </div>
      </div>
    </div>
  )
}

function formatTime(seconds: number): string {
  if (!Number.isFinite(seconds)) return '۰:۰۰'

  const total = Math.floor(seconds)
  const m = Math.floor(total / 60)
  const s = total % 60

  return `${toPersianDigits(m)}:${toPersianDigits(String(s).padStart(2, '0'))}`
}
