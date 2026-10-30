import { visibleSections } from '@/shared/config/navigation'
import { useAuth } from '@/shared/stores/authStore'
import { SidebarBrand } from './SidebarBrand'
import { SidebarSection } from './SidebarSection'
import type { NavSection } from '@/shared/types'

interface SidebarContentProps {
  collapsed: boolean
  onNavigate?: () => void
  /** منوی پوسته‌ای که منوی نقش را ندارد (داشبوردِ اولیه)؛ بی آن، منوی نقش */
  sections?: NavSection[]
  /** نامِ دسترس‌پذیرِ منو */
  navLabel?: string
}

export function SidebarContent({
  collapsed,
  onNavigate,
  sections: own,
  navLabel,
}: SidebarContentProps) {
  const { user } = useAuth()
  // فقط بخش‌هایی که این نقش اجازه‌ی دیدنشان را دارد
  const sections = own ?? visibleSections(user?.role, { complexSelected: user?.complex != null })

  return (
    <div className="flex h-full flex-col">
      <SidebarBrand collapsed={collapsed} />

      <nav aria-label={navLabel} className="scrollbar-thin flex-1 overflow-y-auto px-2.5 py-4">
        {sections.map((section, index) => (
          <SidebarSection
            key={section.id}
            section={section}
            collapsed={collapsed}
            isFirst={index === 0}
            onNavigate={onNavigate}
          />
        ))}

        {/* «جست‌وجوهای اخیر» از ۱۲/۸ در نوارِ بالاست (بندِ ۵۰)، نه پایینِ سایدبار */}
      </nav>
    </div>
  )
}
