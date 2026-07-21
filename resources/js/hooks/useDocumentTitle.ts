import { useEffect } from 'react'
import { BRAND_NAME } from '@/config/brand'

/**
 * عنوان تب مرورگر را بر اساس صفحه‌ی فعلی تغییر می‌دهد.
 * با خروج از کامپوننت، عنوان قبلی به‌صورت خودکار بازنمی‌گردد چون صفحه‌ی بعدی خودش عنوان جدید ست می‌کند.
 */
export function useDocumentTitle(pageTitle?: string) {
  useEffect(() => {
    document.title = pageTitle ? `${pageTitle} | ${BRAND_NAME}` : BRAND_NAME
  }, [pageTitle])
}
