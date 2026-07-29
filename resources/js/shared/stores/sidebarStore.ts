import { create } from 'zustand'

/** حالتِ نوار کناریِ داشبورد (جمع‌شدن و بازشدنِ موبایل) با zustand. */
interface SidebarState {
  collapsed: boolean
  toggleCollapsed: () => void
  mobileOpen: boolean
  toggleMobileOpen: () => void
  setMobileOpen: (open: boolean) => void
}

export const useSidebarStore = create<SidebarState>((set) => ({
  collapsed: false,
  toggleCollapsed: () => set((state) => ({ collapsed: !state.collapsed })),
  mobileOpen: false,
  toggleMobileOpen: () => set((state) => ({ mobileOpen: !state.mobileOpen })),
  setMobileOpen: (open) => set({ mobileOpen: open }),
}))

/** رابطِ سازگار با نسخه‌ی قبلی. */
export function useSidebar() {
  return useSidebarStore()
}
