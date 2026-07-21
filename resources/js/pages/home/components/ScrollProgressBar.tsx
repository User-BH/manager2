import { useScrollProgress } from '@/hooks'

export function ScrollProgressBar() {
  const progress = useScrollProgress()

  return (
    <div className="fixed inset-x-0 top-0 z-[60] h-[3px] bg-transparent">
      <div
        className="h-full transition-[width] duration-150 ease-out"
        style={{
          width: `${progress}%`,
          background: 'linear-gradient(90deg, var(--color-brand-400), var(--color-accent-500))',
        }}
      />
    </div>
  )
}
