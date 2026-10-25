import { Share } from 'lucide-react'
import type { InstallGuide } from './installState'

/**
 * راهنمای مرورگرهای بی‌رویداد (مرحلهٔ ۸ · فاز ۲). این مرورگرها نمی‌توانند
 * بگویند ساکنا نصب است یا نه، پس متن **مشروط** است و دکمه‌ی «نصب» ندارد —
 * فقط «متوجه شدم».
 */
export const GUIDE_STEPS: Record<InstallGuide, React.ReactNode> = {
  ios: (
    <>
      دکمه‌ی اشتراک‌گذاری{' '}
      <Share className="inline h-3.5 w-3.5 align-[-2px]" aria-label="اشتراک‌گذاری" /> را بزنید و
      «Add to Home Screen» را انتخاب کنید.
    </>
  ),
  'macos-safari': <>از منوی File گزینه‌ی «Add to Dock» را انتخاب کنید.</>,
  'firefox-android': <>از منوی ⋮ گزینه‌ی «Install» یا «Add to Home screen» را انتخاب کنید.</>,
}
