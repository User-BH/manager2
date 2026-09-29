import { useEffect, useRef, useState } from 'react'
import { Mic, Square, X } from 'lucide-react'

/** سقفِ ضبط؛ همراهِ نرخِ ۳۲ کیلوبیت، زیرِ سقفِ ۱ مگابایتِ سرور می‌ماند. */
export const MAX_VOICE_SECONDS = 60

/** به ترتیبِ ترجیح: WebM/Ogg در کرومیوم و فایرفاکس، MP4 در Safari. */
const CANDIDATES: [mime: string, extension: string][] = [
  ['audio/webm;codecs=opus', 'webm'],
  ['audio/ogg;codecs=opus', 'ogg'],
  ['audio/mp4', 'm4a'],
]

export function isVoiceSupported(): boolean {
  return (
    typeof MediaRecorder !== 'undefined' &&
    typeof navigator !== 'undefined' &&
    typeof navigator.mediaDevices?.getUserMedia === 'function'
  )
}

/**
 * ضبطِ پیامِ صوتی (مرحلهٔ ۶ · فاز ۱۴) — با `MediaRecorder` خودِ مرورگر، بی‌پکیج.
 *
 * ⚠️ مرورگری که `MediaRecorder` ندارد دکمه را نمی‌بیند، نه دکمه‌ای که کار
 * نکند. میکروفون فقط پس از کلیک و اجازه‌ی کاربر باز می‌شود و با پایانِ ضبط
 * (یا ترکِ صفحه) بسته می‌شود — چراغِ ضبطِ مرورگر روشن نمی‌ماند.
 */
export function VoiceRecorder({
  onRecorded,
  onError,
}: {
  onRecorded: (file: File) => void
  onError: (message: string) => void
}) {
  const [seconds, setSeconds] = useState<number | null>(null)
  const recorderRef = useRef<MediaRecorder | null>(null)
  const streamRef = useRef<MediaStream | null>(null)
  const discardRef = useRef(false)

  const release = () => {
    streamRef.current?.getTracks().forEach((track) => track.stop())
    streamRef.current = null
    recorderRef.current = null
    setSeconds(null)
  }

  // ترکِ صفحه وسطِ ضبط: میکروفون بسته می‌شود و چیزی فرستاده نمی‌شود
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
    if (seconds >= MAX_VOICE_SECONDS) {
      recorderRef.current?.stop()
      return
    }
    const timer = setTimeout(() => setSeconds((s) => (s === null ? null : s + 1)), 1000)
    return () => clearTimeout(timer)
  }, [seconds])

  async function start() {
    const format = CANDIDATES.find(([mime]) => MediaRecorder.isTypeSupported(mime))

    let stream: MediaStream
    try {
      stream = await navigator.mediaDevices.getUserMedia({ audio: true })
    } catch {
      onError('دسترسی به میکروفون داده نشد.')
      return
    }

    const recorder = new MediaRecorder(stream, {
      ...(format ? { mimeType: format[0] } : {}),
      audioBitsPerSecond: 32_000,
    })
    const chunks: Blob[] = []
    discardRef.current = false

    recorder.ondataavailable = (event) => {
      if (event.data.size > 0) chunks.push(event.data)
    }
    recorder.onstop = () => {
      const type = (recorder.mimeType || format?.[0] || 'audio/webm').split(';')[0]
      const extension = format?.[1] ?? 'webm'
      release()
      if (discardRef.current || chunks.length === 0) return
      onRecorded(new File(chunks, `voice.${extension}`, { type }))
    }

    streamRef.current = stream
    recorderRef.current = recorder
    recorder.start()
    setSeconds(0)
  }

  function stop(discard: boolean) {
    discardRef.current = discard
    recorderRef.current?.stop()
  }

  if (seconds === null) {
    return (
      <button
        type="button"
        onClick={() => void start()}
        aria-label="ضبطِ پیامِ صوتی"
        className="flex h-9 w-9 items-center justify-center rounded-lg"
        style={{ color: 'var(--text-tertiary)' }}
      >
        <Mic size={16} />
      </button>
    )
  }

  return (
    <div
      className="flex items-center gap-1.5 rounded-lg px-2 text-small"
      style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--color-danger)' }}
      role="status"
    >
      <span className="h-2 w-2 animate-pulse rounded-full bg-current" aria-hidden="true" />
      <span className="tabular-nums">
        در حالِ ضبط · {seconds} / {MAX_VOICE_SECONDS} ثانیه
      </span>
      <button
        type="button"
        onClick={() => stop(false)}
        aria-label="پایانِ ضبط و افزودن"
        className="flex h-8 w-8 items-center justify-center"
      >
        <Square size={14} />
      </button>
      <button
        type="button"
        onClick={() => stop(true)}
        aria-label="لغوِ ضبط"
        className="flex h-8 w-8 items-center justify-center"
      >
        <X size={14} />
      </button>
    </div>
  )
}
