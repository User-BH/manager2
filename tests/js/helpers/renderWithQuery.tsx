import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import type { ReactElement, ReactNode } from 'react'

/**
 * رندرِ کامپوننت با همه‌ی Providerهای لازم برای صفحه‌های داشبورد.
 *
 * ─── چرا برای هر تست یک QueryClient تازه؟ ──────────────────────────────────
 * کش بینِ تست‌ها نشت می‌کند: تستِ دوم داده‌ی تستِ اول را می‌بیند و بی‌آنکه
 * درخواستی بزند سبز می‌شود — یعنی تستی که هیچ‌چیز را ثابت نمی‌کند. با ساختِ
 * کلاینت در هر فراخوانی، هر تست از صفر شروع می‌کند.
 *
 * ─── چرا `retry: false`؟ ───────────────────────────────────────────────────
 * در تست باید فوراً شکست دیده شود. با retryِ روشن، تستِ «حالتِ خطا» چند بار
 * تلاش و بینشان صبر می‌کند و یا کند می‌شود یا با timeout می‌افتد — و علتش هم
 * اصلاً پیدا نیست.
 */
export function renderWithQuery(ui: ReactElement) {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: 0, staleTime: 0 },
      mutations: { retry: false },
    },
  })

  function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>{children}</MemoryRouter>
      </QueryClientProvider>
    )
  }

  return { queryClient, ...render(ui, { wrapper: Wrapper }) }
}
