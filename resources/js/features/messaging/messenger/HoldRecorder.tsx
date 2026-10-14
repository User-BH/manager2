import { useEffect, useRef, useState } from 'react'
import { Mic } from 'lucide-react'

/** هر دو: یک دقیقه، مثلِ پیام‌رسان‌های آشنا. */
export const MAX_RECORD_SECONDS = 60

/** کشیدنِ انگشت بیش از این (پیکسل) به کنار یعنی «لغو» (بندهای ۴۴ و ۴۵). */
export const CANCEL_DISTANCE = 80

/** رها کردنِ زودتر از این یعنی لمسِ اتفاقی، نه پیام. */
export const MIN_RECORD_MS = 400

const FORMATS: Record<'voice' | 'video', [mime: string, extension: string][]> = {
  voice: [
    ['audio/webm;codecs=opus', 'webm'],
    ['audio/ogg;codecs=opus', 'ogg'],
    ['audio/mp4', 'm4a'],
  ],
  video: [
    ['video/webm;codecs=vp8,opus', 'webm'],
    ['video/webm', 'webm'],
    ['video/mp4', 'mp4'],
  ],
}

export function isRecordingSupported(): boolean {
  return (
    typeof MediaRecorder !== 'undefined' &&
    typeof navigator !== 'undefined' &&
    typeof navigator.mediaDevices?.getUserMedia === 'function'
  )
}

/**
 * «نگه دار، ضبط کن، رها کن، فرستاده شد» — پیامِ صوتی و ویدیویی (مرحلهٔ ۱۰ · فاز ۱۲؛ بندهای ۴۴ و ۴۵).
 *
 * - **انگشت یا موشواره:** فشار شروع می‌کند، رها کردن می‌فرستد. کشیدن بیش از `CANCEL_DISTANCE` به کنار، پیش از رها
 *   کردن، لغو است و هیچ چیزی فرستاده نمی‌شود — `pointercancel` (تماسِ تلفن، اسکرول) هم.
 * - **کیبورد:** نگه داشتنِ Space یا Enter همان، و Escape لغو.
 * - رها کردنِ زودتر از `MIN_RECORD_MS` فقط راهنما می‌گوید؛ رها کردن پیش از آماده‌شدنِ میکروفون یا دوربین هم
 *   چیزی نمی‌فرستد.
 *
 * ترکِ صفحه وسطِ ضبط: میکروفون و دوربین بسته می‌شوند و چیزی فرستاده نمی‌شود.
 */
export function HoldRecorder({
  mode,
  onRecorded,
  onError,
}: {
  mode: 'voice' | 'video'
  onRecorded: (file: File) => void
  onError: (message: string | null) => void
}) {
  const [seconds, setSeconds] = useState<number | null>(null)
  const [cancelArmed, setCancelArmed] = useState(false)
  const recorderRef = useRef<MediaRecorder | null>(null)
  const streamRef = useRef<MediaStream | null>(null)
  const previewRef = useRef<HTMLVideoElement | null>(null)
  const discardRef = useRef(false)
  const releasedRef = useRef(false)
  const startedAtRef = useRef(0)
  const originXRef = useRef(0)
  const label = mode === 'voice' ? 'پیامِ صوتی' : 'پیامِ ویدیویی'

  const releaseDevices = () => {
    streamRef.current?.getTracks().forEach((track) => track.stop())
    streamRef.current = null
    recorderRef.current = null
    setSeconds(null)
    setCancelArmed(false)
  }

  useEffect(
    () => () => {
      discardRef.current = true
      if (recorderRef.current?.state === 'recording') recorderRef.current.stop()
      streamRef.current?.getTracks().forEach((track) => track.stop())
    },
    [],
  )

  useEffect(() => {
    if (seconds === null) return
    if (seconds >= MAX_RECORD_SECONDS) {
      finish(false)
      return
    }
    const timer = setTimeout(() => setSeconds((s) => (s === null ? null : s + 1)), 1000)
    return () => clearTimeout(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [seconds])

  async function start() {
    releasedRef.current = false
    discardRef.current = false
    const format = FORMATS[mode].find(([mime]) => MediaRecorder.isTypeSupported(mime))

    let stream: MediaStream
    try {
      stream = await navigator.mediaDevices.getUserMedia(
        mode === 'voice'
          ? { audio: true }
          : { audio: true, video: { facingMode: 'user', width: 480, height: 480 } },
      )
    } catch {
      onError(mode === 'voice' ? 'دسترسی به میکروفون داده نشد.' : 'دسترسی به دوربین داده نشد.')
      return
    }

    // رها شد پیش از آن‌که دستگاه آماده شود: چیزی ضبط نمی‌شود
    if (releasedRef.current) {
      stream.getTracks().forEach((track) => track.stop())
      onError(`برای ضبطِ ${label} دکمه را نگه دارید.`)
      return
    }

    const recorder = new MediaRecorder(stream, {
      ...(format ? { mimeType: format[0] } : {}),
      ...(mode === 'voice' ? { audioBitsPerSecond: 32_000 } : { videoBitsPerSecond: 600_000 }),
    })
    const chunks: Blob[] = []

    recorder.ondataavailable = (event) => {
      if (event.data.size > 0) chunks.push(event.data)
    }
    recorder.onstop = () => {
      const fallback = mode === 'voice' ? 'audio/webm' : 'video/webm'
      const type = (recorder.mimeType || format?.[0] || fallback).split(';')[0] ?? fallback
      const extension = format?.[1] ?? 'webm'
      releaseDevices()
      if (discardRef.current || chunks.length === 0) return
      onRecorded(
        new File(chunks, `${mode === 'voice' ? 'voice' : 'video-message'}.${extension}`, { type }),
      )
    }

    streamRef.current = stream
    recorderRef.current = recorder
    startedAtRef.current = Date.now()
    if (previewRef.current && mode === 'video') previewRef.current.srcObject = stream
    recorder.start()
    onError(null)
    setSeconds(0)
  }

  /** رها کردن: فرستادن، یا — اگر لغو خواسته شده یا خیلی کوتاه بود — دور ریختن */
  function finish(cancel: boolean) {
    releasedRef.current = true
    const recorder = recorderRef.current
    if (!recorder) return

    const tooShort = Date.now() - startedAtRef.current < MIN_RECORD_MS
    if (tooShort && !cancel) onError(`برای ضبطِ ${label} دکمه را نگه دارید.`)
    discardRef.current = cancel || tooShort
    if (recorder.state === 'recording') recorder.stop()
  }

  const recording = seconds !== null

  return (
    <div className="relative">
      {recording && (
        <div
          className="absolute bottom-full left-0 mb-2 flex items-center gap-2 rounded-xl px-3 py-2 text-small shadow-lg"
          style={{ backgroundColor: 'var(--surface-raised)', color: 'var(--text-primary)' }}
          role="status"
        >
          {mode === 'video' && (
            <video
              ref={previewRef}
              muted
              playsInline
              autoPlay
              className="h-24 w-24 rounded-full object-cover"
              aria-hidden="true"
            />
          )}
          <span
            className="h-2 w-2 animate-pulse rounded-full"
            style={{ backgroundColor: 'var(--color-danger)' }}
            aria-hidden="true"
          />
          <span className="tabular-nums">
            {seconds} / {MAX_RECORD_SECONDS}
          </span>
          <span style={{ color: cancelArmed ? 'var(--color-danger)' : 'var(--text-tertiary)' }}>
            {cancelArmed ? 'رها کنید تا لغو شود' : 'برای لغو به کنار بکشید'}
          </span>
        </div>
      )}
      <button
        type="button"
        aria-label={`برای ضبطِ ${label} نگه دارید`}
        aria-pressed={recording}
        className="flex h-9 w-9 touch-none select-none items-center justify-center rounded-lg"
        style={{ color: recording ? 'var(--color-danger)' : 'var(--text-tertiary)' }}
        onPointerDown={(event) => {
          event.preventDefault()
          originXRef.current = event.clientX
          event.currentTarget.setPointerCapture?.(event.pointerId)
          void start()
        }}
        onPointerMove={(event) => {
          if (!recording && !recorderRef.current) return
          setCancelArmed(Math.abs(event.clientX - originXRef.current) > CANCEL_DISTANCE)
        }}
        onPointerUp={(event) => {
          finish(Math.abs(event.clientX - originXRef.current) > CANCEL_DISTANCE)
        }}
        onPointerCancel={() => finish(true)}
        onKeyDown={(event) => {
          if (event.key === 'Escape') {
            finish(true)
            return
          }
          if ((event.key === ' ' || event.key === 'Enter') && !event.repeat) {
            event.preventDefault()
            void start()
          }
        }}
        onKeyUp={(event) => {
          if (event.key === ' ' || event.key === 'Enter') {
            event.preventDefault()
            finish(false)
          }
        }}
      >
        {mode === 'voice' ? <Mic size={16} /> : <CameraIcon />}
      </button>
    </div>
  )
}

/**
 * دوربین — درون‌خطی، نه آیکونِ lucide: آیکونِ تازه چانکِ مشترکِ آیکون‌ها را بزرگ می‌کرد که صفحه‌ی پشتیبانی هم بارش
 * می‌کند (`bundleBudget`)؛ این فقط در چانکِ تنبلِ پیام‌رسان است.
 */
function CameraIcon() {
  return (
    <svg
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <rect x="2" y="6" width="14" height="12" rx="2" />
      <path d="m16 10 6-3v10l-6-3" />
    </svg>
  )
}
