import { useCallback, useEffect, useRef, useState } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import {
  Maximize,
  Minimize,
  Pause,
  Play,
  RotateCcw,
  Volume2,
  VolumeX,
} from 'lucide-react'
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

/** وقتی فایل ویدیو هنوز روی سرور نیست. */
function MissingVideoNotice({ poster }: { poster: string }) {
  return (
    <div
      className="relative flex aspect-video w-full items-center justify-center overflow-hidden rounded-3xl border"
      style={{ borderColor: 'var(--border-subtle)' }}
    >
      <img src={poster} alt="" className="absolute inset-0 h-full w-full object-cover" />
      <div className="absolute inset-0" style={{ backgroundColor: 'color-mix(in srgb, #05100c 78%, transparent)' }} />

      <div className="relative max-w-md px-6 text-center">
        <span
          className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl text-white"
          style={{ backgroundColor: 'color-mix(in srgb, var(--color-brand-500) 85%, transparent)' }}
        >
          <Play size={26} fill="currentColor" />
        </span>
        <p className="mt-4 text-base font-extrabold text-white">ویدیوی دمو هنوز بارگذاری نشده است</p>
        <p className="mt-2 text-[12.5px] leading-7 text-white/75">
          فایل را با نام <span className="font-mono">demo.mp4</span> داخل پوشه‌ی{' '}
          <span className="font-mono">public/videos</span> بگذارید تا همین‌جا پخش شود.
          راهنمای کامل در <span className="font-mono">public/videos/README.md</span> است.
        </p>
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
