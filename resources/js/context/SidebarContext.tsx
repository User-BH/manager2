import { createContext, useContext, type ReactNode } from 'react'
import { useToggle } from '@/hooks'

interface SidebarContextValue {
  collapsed: boolean
  toggleCollapsed: () => void
  mobileOpen: boolean
  toggleMobileOpen: () => void
  setMobileOpen: (open: boolean) => void
}

const SidebarContext = createContext<SidebarContextValue | undefined>(undefined)

export function SidebarProvider({ children }: { children: ReactNode }) {
  const [collapsed, toggleCollapsed] = useToggle(false)
  const [mobileOpen, toggleMobileOpen, setMobileOpen] = useToggle(false)

  return (
    <SidebarContext.Provider
      value={{ collapsed, toggleCollapsed, mobileOpen, toggleMobileOpen, setMobileOpen }}
    >
      {children}
    </SidebarContext.Provider>
  )
}

export function useSidebar() {
  const ctx = useContext(SidebarContext)
  if (!ctx) throw new Error('useSidebar باید داخل SidebarProvider استفاده شود')
  return ctx
}
