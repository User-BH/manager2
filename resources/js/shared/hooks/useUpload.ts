import { useCallback, useRef, useState } from 'react'
import axios from 'axios'

/**
 * پیشرفت و لغوِ یک آپلود (مرحلهٔ ۵ · فاز ۶ · بندِ ۵۲).
 *
 * 🔴 پیش از این هیچ آپلودی پیشرفت یا دکمه‌ی لغو نداشت. رسیدِ چهارمگابایتی با
 * اینترنتِ همراه ده‌ها ثانیه طول می‌کشد و کاربر فقط یک دکمه‌ی قفل‌شده می‌دید —
 * نه می‌دانست چقدر مانده، نه می‌توانست منصرف شود جز با بستنِ صفحه.
 *
 * «تلاشِ دوباره» عمداً خودکار نیست: آپلود `POST` است و تکرارِ خودکارش
 * ممکن است رسید را دو بار ثبت کند. فرم فایل را نگه می‌دارد، پس کاربر همان
 * دکمه را دوباره می‌زند.
 *
 * @example
 * const upload = useUpload()
 * await upload.send((options) => api('/pay/1/receipt', { method: 'POST', body, ...options }))
 * <UploadProgress upload={upload} />
 */
export function useUpload() {
  const [progress, setProgress] = useState<number | null>(null)
  const controllerRef = useRef<AbortController | null>(null)

  const send = useCallback(
    async <T>(
      run: (options: {
        signal: AbortSignal
        onUploadProgress: (fraction: number) => void
      }) => Promise<T>,
    ): Promise<T> => {
      const controller = new AbortController()
      controllerRef.current = controller
      setProgress(0)

      try {
        return await run({ signal: controller.signal, onUploadProgress: setProgress })
      } finally {
        controllerRef.current = null
        setProgress(null)
      }
    },
    [],
  )

  const cancel = useCallback(() => controllerRef.current?.abort(), [])

  return { progress, send, cancel }
}

export type UploadHandle = ReturnType<typeof useUpload>

/** آیا این خطا لغوِ خودِ کاربر است؟ لغو خطا نیست و پیامِ خطا نمی‌گیرد. */
export function isUploadCancelled(error: unknown): boolean {
  return axios.isCancel(error) || (error instanceof DOMException && error.name === 'AbortError')
}
