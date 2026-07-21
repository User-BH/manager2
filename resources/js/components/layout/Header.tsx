import { Bell, Menu } from 'lucide-react'
import { SearchBox } from './SearchBox'
import { ThemeToggle } from './ThemeToggle'
import { UserMenu } from './UserMenu'
import { IconButton } from '@/components/ui/IconButton'
import { useSidebar } from '@/context/SidebarContext'

export function Header() {
  const { setMobileOpen } = useSidebar()

  return (
    <header
      className="sticky top-0 z-20 flex h-16 items-center gap-3 border-b px-4 backdrop-blur sm:px-6"
      style={{
        backgroundColor: 'color-mix(in srgb, var(--surface-base) 92%, transparent)',
        borderColor: 'var(--border-subtle)',
      }}
    >
      <button
        onClick={() => setMobileOpen(true)}
        className="flex h-9 w-9 items-center justify-center rounded-xl transition-colors hover:bg-(--surface-sunken) lg:hidden"
        style={{ color: 'var(--text-secondary)' }}
        aria-label="باز کردن منو"
      >
        <Menu size={20} />
      </button>

      <div className="flex-1">
        <SearchBox onSearch={(q) => console.log('جستجو:', q)} />
      </div>

      <div className="flex items-center gap-2">
        <IconButton variant="outline" aria-label="اعلان‌ها" className="relative">
          <Bell size={17} style={{ color: 'var(--text-secondary)' }} />
          <span
            className="absolute -left-0.5 -top-0.5 flex h-2.5 w-2.5 rounded-full ring-2"
            style={{
              backgroundColor: 'var(--color-accent-500)',
              ['--tw-ring-color' as string]: 'var(--surface-base)',
            }}
          />
        </IconButton>

        <ThemeToggle />

        <div className="mx-1 h-7 w-px" style={{ backgroundColor: 'var(--border-subtle)' }} />

        <UserMenu />
      </div>
    </header>
  )
}
