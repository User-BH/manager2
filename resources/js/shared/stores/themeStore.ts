import { create } from 'zustand'
import type { Theme } from '@/shared/types'

/**
 * وضعیت تم (روشن/تیره) با zustand.
 *
 * چرا zustand و نه Context: حالتِ سراسری‌ای که در همه‌ی جزیره‌ها لازم است،
 * بدون نیاز به Providerِ دورِ هر درخت. کلاسِ `dark` با یک subscription روی
 * store اعمال می‌شود، پس همان‌جا که theme عوض شود صفحه هم به‌روز می‌شود.
 */

function getSystemTheme(): Theme {
  if (typeof window === 'undefined') return 'light'
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

/** مقدار ذخیره‌شده را می‌خواند؛ هم فرمتِ خام و هم JSONِ نسخه‌ی قدیمی را می‌پذیرد. */
function readStoredTheme(): Theme {
  if (typeof window === 'undefined') return 'light'
  try {
    const raw = window.localStorage.getItem('theme')
    if (!raw) return getSystemTheme()
    const value = raw.startsWith('"') ? (JSON.parse(raw) as string) : raw
    return value === 'dark' ? 'dark' : 'light'
  } catch {
    return getSystemTheme()
  }
}

interface ThemeState {
  theme: Theme
  toggleTheme: () => void
  setTheme: (theme: Theme) => void
}

export const useThemeStore = create<ThemeState>((set) => ({
  theme: readStoredTheme(),
  toggleTheme: () => set((state) => ({ theme: state.theme === 'dark' ? 'light' : 'dark' })),
  setTheme: (theme) => set({ theme }),
}))

/** کلاسِ dark و ذخیره‌سازی را با تمِ فعلی هماهنگ می‌کند. */
function applyTheme(theme: Theme): void {
  if (typeof document === 'undefined') return
  document.documentElement.classList.toggle('dark', theme === 'dark')
  // خام ذخیره می‌شود تا اسکریپتِ ضدِپرشِ داخلِ <head> هم بتواند بخواندش
  try {
    window.localStorage.setItem('theme', theme)
  } catch {
    // فضای ذخیره‌سازی در دسترس نیست؛ بی‌خطر
  }
}

applyTheme(useThemeStore.getState().theme)
useThemeStore.subscribe((state) => applyTheme(state.theme))

/** رابطِ سازگار با نسخه‌ی قبلی: `const { theme, toggleTheme } = useTheme()`. */
export function useTheme() {
  const theme = useThemeStore((state) => state.theme)
  const toggleTheme = useThemeStore((state) => state.toggleTheme)
  return { theme, toggleTheme }
}
