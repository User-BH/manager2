import { visibleSections } from '@/config/navigation'
import { useAuth } from '@/context/AuthContext'
import { SidebarBrand } from './SidebarBrand'
import { SidebarSection } from './SidebarSection'
import { SidebarRecentSearches } from './SidebarRecentSearches'

interface SidebarContentProps {
  collapsed: boolean
  onNavigate?: () => void
}

export function SidebarContent({ collapsed, onNavigate }: SidebarContentProps) {
  const { user } = useAuth()
  // فقط بخش‌هایی که این نقش اجازه‌ی دیدنشان را دارد
  const sections = visibleSections(user?.role)

  return (
    <div className="flex h-full flex-col">
      <SidebarBrand collapsed={collapsed} />

      <nav className="scrollbar-thin flex-1 overflow-y-auto px-2.5 py-4">
        {sections.map((section, index) => (
          <SidebarSection
            key={section.id}
            section={section}
            collapsed={collapsed}
            isFirst={index === 0}
            onNavigate={onNavigate}
          />
        ))}

        <SidebarRecentSearches collapsed={collapsed} onNavigate={onNavigate} />
      </nav>
    </div>
  )
}
