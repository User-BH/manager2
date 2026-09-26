import { create } from 'zustand'
import { api } from '@/shared/lib/api'

/**
 * «رمزتان را دوباره بزنید» پیش از عملیاتِ حساس (مرحلهٔ ۵ · فاز ۱۰).
 *
 * `requestReauth()` پنجره را باز می‌کند و تا کاربر تأیید یا انصراف دهد منتظر
 * می‌ماند. ⚠️ چند درخواستِ هم‌زمان که هر دو تأیید بخواهند **یک** پنجره
 * می‌بینند، نه دو پنجره‌ی روی‌هم.
 */

interface ReauthState {
  open: boolean
  settle: (confirmed: boolean) => void
}

export const useReauthStore = create<ReauthState>(() => ({
  open: false,
  settle: () => {},
}))

let pending: Promise<boolean> | null = null

export function requestReauth(): Promise<boolean> {
  if (pending) return pending

  pending = new Promise<boolean>((resolve) => {
    useReauthStore.setState({
      open: true,
      settle: (confirmed) => {
        pending = null
        useReauthStore.setState({ open: false, settle: () => {} })
        resolve(confirmed)
      },
    })
  })

  return pending
}

/**
 * برای پیوندهای معمولیِ مرورگر (دانلودِ بکاپ): اگر تأییدِ تازه هست بی‌پنجره
 * `true`، وگرنه پنجره.
 */
export async function ensureRecentPassword(): Promise<boolean> {
  const { confirmed } = await api<{ confirmed: boolean }>('/profile/confirm-password')

  return confirmed || requestReauth()
}
