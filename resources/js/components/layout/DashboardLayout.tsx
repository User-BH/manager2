import { Outlet } from 'react-router-dom'
import { Sidebar } from './Sidebar'
import { Header } from './Header'
import { SidebarProvider } from '@/context/SidebarContext'

export function DashboardLayout() {
  return (
    <SidebarProvider>
      <div
        className="flex h-screen overflow-hidden"
        dir="rtl"
        style={{ backgroundColor: 'var(--surface-canvas)' }}
      >
        <Sidebar />

        <div className="flex min-w-0 flex-1 flex-col">
          <Header />

          <main className="scrollbar-thin flex-1 overflow-y-auto p-4 sm:p-6">
            <Outlet />
          </main>
        </div>
      </div>
    </SidebarProvider>
  )
}
