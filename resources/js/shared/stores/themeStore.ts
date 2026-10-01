import { create } from 'zustand'
import type { Theme } from '@/shared/types'

/**
 * وضعیت تم (روشن/تیره) با zustand.
 *
 * چرا zustand و نه Context: حالتِ سراسری‌ای که در همه‌ی جزیره‌ها لازم است،
 * بدون نیاز به Providerِ دورِ هر درخت. کلاسِ `dark` با یک subscription روی
 * store اعمال می‌شود، پس همان‌جا که theme عوض شود صفحه هم به‌روز می‌شود.
 *
 * ─── قاعده (مرحلهٔ ۷ · فاز ۱) ─────────────────────────────────────────────
 * پیش‌فرض **روشن** است؛ تیره فقط با انتخابِ خودِ کاربر. همین قاعده در
 * `partials/theme-init.blade.php` پیش از اولین رنگ‌آمیزی اجرا می‌شود و این دو
 * باید یکی بمانند — وگرنه صفحه هنگامِ بارگذاری پرش می‌کند.
 *
 * 🔴 پیش از این تمِ سیستم‌عامل خوانده می‌شد و در اولین بازدید **ذخیره**
 * می‌شد: حدسِ ما به‌جای انتخابِ کاربر ثبت می‌شد و دیگر پاک نمی‌شد. حالا فقط
 * کلیکِ کاربر چیزی می‌نویسد. کلیدِ قدیم (`theme`) به همین آلودگی کنار گذاشته
 * شد و یک بار پاک می‌شود.
 */
export const THEME_KEY = 'sakena:theme'
const LEGACY_KEY = 'theme'

const BAR_COLOR: Record<Theme, string> = { light: '#0f6e56', dark: '#0f1411' }

function readStoredTheme(): Theme {
  if (typeof window === 'undefined') return 'light'
  try {
    window.localStorage.removeItem(LEGACY_KEY)
    return window.localStorage.getItem(THEME_KEY) === 'dark' ? 'dark' : 'light'
  } catch {
    return 'light'
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

/** کلاسِ dark و رنگِ نوارِ مرورگر را با تمِ فعلی هماهنگ می‌کند. */
function applyTheme(theme: Theme): void {
  if (typeof document === 'undefined') return
  document.documentElement.classList.toggle('dark', theme === 'dark')
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', BAR_COLOR[theme])
}

/** فقط انتخابِ کاربر ذخیره می‌شود — خام، تا اسکریپتِ ضدِپرشِ <head> هم بخواندش. */
function storeTheme(theme: Theme): void {
  try {
    window.localStorage.setItem(THEME_KEY, theme)
  } catch {
    // فضای ذخیره‌سازی در دسترس نیست؛ تم تا پایانِ همین بازدید می‌ماند
  }
}

applyTheme(useThemeStore.getState().theme)
useThemeStore.subscribe((state, previous) => {
  if (state.theme === previous.theme) return
  applyTheme(state.theme)
  storeTheme(state.theme)
})

/** رابطِ سازگار با نسخه‌ی قبلی: `const { theme, toggleTheme } = useTheme()`. */
export function useTheme() {
  const theme = useThemeStore((state) => state.theme)
  const toggleTheme = useThemeStore((state) => state.toggleTheme)
  return { theme, toggleTheme }
}
