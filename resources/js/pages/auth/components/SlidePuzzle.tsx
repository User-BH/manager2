import { useCallback, useEffect, useRef, useState } from 'react'
import { motion } from 'framer-motion'
import { Check, RefreshCw } from 'lucide-react'
import { galleryImages } from '@/data/images'

const WIDTH = 300
const HEIGHT = 160
const PIECE = 46
const TOLERANCE = 8

interface Puzzle {
  image: string
  targetX: number
  gapY: number
}

/**
 * پازلِ کشویی «ربات نیستم».
 *
 * کاربر تکه‌ی گمشده‌ی یک تصویرِ ساختمان را با کشیدن در جای خالی می‌گذارد.
 * هر بار تصویر و جای خالی عوض می‌شود. تا حل نشود، `onSolved` صدا زده نمی‌شود
 * و دکمه‌ی ورود بسته می‌ماند.
 *
 * این یک لایه‌ی «انسان‌سنجیِ» سمت کاربر است، نه اثباتِ سرور؛ همراه محدودیت
 * نرخ و کد پیامکی، جلوی ربات‌های ساده را می‌گیرد.
 */
export function SlidePuzzle({ onSolved }: { onSolved: (solved: boolean) => void }) {
  const [puzzle, setPuzzle] = useState<Puzzle>(() => makePuzzle())
  const [x, setX] = useState(0)
  const [solved, setSolved] = useState(false)
  const [failed, setFailed] = useState(false)
  const trackRef = useRef<HTMLDivElement>(null)
  const dragging = useRef(false)

  const reset = useCallback(() => {
    setPuzzle(makePuzzle())
    setX(0)
    setSolved(false)
    setFailed(false)
    onSolved(false)
  }, [onSolved])

  const move = useCallback((clientX: number) => {
    const track = trackRef.current
    if (!track) return

    const rect = track.getBoundingClientRect()
    // چون کل صفحه راست‌چین است، کشیدن از چپ به راست باید مقدار را زیاد کند
    const raw = clientX - rect.left - PIECE / 2
    setX(Math.max(0, Math.min(WIDTH - PIECE, raw)))
  }, [])

  const end = useCallback(() => {
    if (!dragging.current) return
    dragging.current = false

    setX((current) => {
      if (Math.abs(current - puzzle.targetX) <= TOLERANCE) {
        setSolved(true)
        onSolved(true)
        return puzzle.targetX
      }
      // نزدیک نبود: کمی بلرزد و برگردد
      setFailed(true)
      setTimeout(() => setFailed(false), 400)
      return 0
    })
  }, [puzzle.targetX, onSolved])

  // شنونده‌های سراسری تا کشیدن با خروجِ نشانگر از کادر هم ادامه یابد
  useEffect(() => {
    const onMove = (e: PointerEvent) => dragging.current && move(e.clientX)
    const onUp = () => end()
    window.addEventListener('pointermove', onMove)
    window.addEventListener('pointerup', onUp)
    return () => {
      window.removeEventListener('pointermove', onMove)
      window.removeEventListener('pointerup', onUp)
    }
  }, [move, end])

  return (
    <div className="flex flex-col gap-2">
      <div className="flex items-center justify-between">
        <span className="text-[12px] font-medium" style={{ color: 'var(--text-secondary)' }}>
          {solved ? 'تایید شد، شما ربات نیستید ✓' : 'تکه را در جای خالی بگذارید'}
        </span>
        <button
          type="button"
          onClick={reset}
          aria-label="تصویر تازه"
          className="flex h-7 w-7 items-center justify-center rounded-lg transition-colors hover:bg-(--surface-sunken)"
          style={{ color: 'var(--text-tertiary)' }}
        >
          <RefreshCw size={14} />
        </button>
      </div>

      {/* صحنه */}
      <div
        className="relative overflow-hidden rounded-xl border"
        style={{ width: WIDTH, height: HEIGHT, maxWidth: '100%', borderColor: 'var(--border-subtle)' }}
      >
        <img
          src={puzzle.image}
          alt=""
          className="absolute inset-0 h-full w-full select-none object-cover"
          style={{ width: WIDTH, height: HEIGHT }}
          draggable={false}
        />

        {/* جای خالی */}
        <div
          className="absolute rounded-md"
          style={{
            left: puzzle.targetX,
            top: puzzle.gapY,
            width: PIECE,
            height: PIECE,
            backgroundColor: 'rgba(0,0,0,0.45)',
            boxShadow: 'inset 0 2px 6px rgba(0,0,0,0.5)',
          }}
        />

        {/* تکه‌ی متحرک — همان برشِ تصویر که باید سرِ جایش بنشیند */}
        <motion.div
          onPointerDown={(e) => {
            if (solved) return
            dragging.current = true
            ;(e.target as Element).setPointerCapture?.(e.pointerId)
          }}
          animate={failed ? { x: [0, -6, 6, -4, 0] } : {}}
          transition={{ duration: 0.35 }}
          className="absolute rounded-md border-2 shadow-lg"
          style={{
            left: x,
            top: puzzle.gapY,
            width: PIECE,
            height: PIECE,
            cursor: solved ? 'default' : 'grab',
            borderColor: solved ? 'var(--color-success)' : 'rgba(255,255,255,0.85)',
            backgroundImage: `url(${puzzle.image})`,
            backgroundSize: `${WIDTH}px ${HEIGHT}px`,
            backgroundPosition: `-${puzzle.targetX}px -${puzzle.gapY}px`,
            touchAction: 'none',
          }}
        >
          {solved && (
            <span
              className="flex h-full w-full items-center justify-center rounded-md"
              style={{ backgroundColor: 'color-mix(in srgb, var(--color-success) 55%, transparent)' }}
            >
              <Check size={18} className="text-white" />
            </span>
          )}
        </motion.div>
      </div>

      {/* دستگیره‌ی کشویی زیر تصویر، برای هدایتِ راحت‌تر */}
      <div
        ref={trackRef}
        className="relative h-9 rounded-xl border"
        style={{ width: WIDTH, maxWidth: '100%', backgroundColor: 'var(--surface-sunken)', borderColor: 'var(--border-subtle)' }}
      >
        <div
          className="absolute inset-y-0 right-0 rounded-xl"
          style={{
            // نوارِ پیشرفت از سمت راست پر می‌شود (راست‌چین)
            width: solved ? '100%' : `${(x / (WIDTH - PIECE)) * 100}%`,
            backgroundColor: solved
              ? 'color-mix(in srgb, var(--color-success) 25%, transparent)'
              : 'color-mix(in srgb, var(--color-brand-500) 18%, transparent)',
          }}
        />
        <motion.button
          type="button"
          onPointerDown={(e) => {
            if (solved) return
            dragging.current = true
            ;(e.target as Element).setPointerCapture?.(e.pointerId)
          }}
          animate={failed ? { x: [0, -6, 6, -4, 0] } : {}}
          aria-label="کشیدن برای حل پازل"
          className="absolute top-1/2 flex h-8 w-9 -translate-y-1/2 items-center justify-center rounded-lg text-white shadow"
          style={{
            left: (x / (WIDTH - PIECE)) * (WIDTH - 36),
            cursor: solved ? 'default' : 'grab',
            backgroundColor: solved ? 'var(--color-success)' : 'var(--color-brand-500)',
            touchAction: 'none',
          }}
        >
          {solved ? <Check size={16} /> : '⇄'}
        </motion.button>
      </div>
    </div>
  )
}

function makePuzzle(): Puzzle {
  const image = galleryImages[Math.floor(Math.random() * galleryImages.length)]
  // جای خالی نه خیلی چپ (که بی‌معنی شود) نه خیلی راست
  const targetX = Math.round(80 + Math.random() * (WIDTH - PIECE - 100))
  const gapY = Math.round(18 + Math.random() * (HEIGHT - PIECE - 36))
  return { image, targetX, gapY }
}
