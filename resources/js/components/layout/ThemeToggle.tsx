import { motion, AnimatePresence } from 'framer-motion'
import { Moon, Sun } from 'lucide-react'
import { useTheme } from '@/context/ThemeContext'
import { IconButton } from '@/components/ui/IconButton'
import { Tooltip } from '@/components/ui/Tooltip'

export function ThemeToggle() {
  const { theme, toggleTheme } = useTheme()
  const isDark = theme === 'dark'

  return (
    <Tooltip label={isDark ? 'حالت روشن' : 'حالت تاریک'}>
      <IconButton
        variant="outline"
        onClick={toggleTheme}
        aria-label={isDark ? 'تغییر به حالت روشن' : 'تغییر به حالت تاریک'}
        className="overflow-hidden"
      >
        <AnimatePresence mode="wait" initial={false}>
          <motion.span
            key={isDark ? 'moon' : 'sun'}
            initial={{ rotate: -90, opacity: 0, scale: 0.5 }}
            animate={{ rotate: 0, opacity: 1, scale: 1 }}
            exit={{ rotate: 90, opacity: 0, scale: 0.5 }}
            transition={{ duration: 0.25 }}
            className="flex items-center justify-center"
            style={{ color: isDark ? 'var(--color-brand-300)' : 'var(--color-accent-500)' }}
          >
            {isDark ? <Moon size={17} /> : <Sun size={18} />}
          </motion.span>
        </AnimatePresence>
      </IconButton>
    </Tooltip>
  )
}
