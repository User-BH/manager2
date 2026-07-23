import { useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AlertTriangle, Loader2, Upload } from 'lucide-react'
import { Card } from '@/components/ui/Card'
import { BackupList, type BackupRow } from '@/components/ui/BackupList'
import { ErrorState, LoadingState } from '@/components/ui/PageState'
import { useApi } from '@/hooks/useApi'
import { useDocumentTitle } from '@/hooks'
import { useAuth } from '@/context/AuthContext'
import { api, ApiError } from '@/lib/api'
import { alertError, confirmAction, toastSuccess } from '@/lib/alert'

export function SystemBackupPage() {
  const [busy, setBusy] = useState(false)
  const [restoring, setRestoring] = useState(false)
  const [restoreError, setRestoreError] = useState<string | null>(null)
  const fileRef = useRef<HTMLInputElement>(null)
  const navigate = useNavigate()
  const { setUser } = useAuth()

  useDocumentTitle('بکاپ کل سیستم')

  const { data, error, isLoading, reload } = useApi<{ data: BackupRow[] }>('/system/backups')

  async function createBackup() {
    setBusy(true)
    try {
      await api('/system/backups', { method: 'POST' })
      toastSuccess('نسخه پشتیبان کامل ساخته شد.')
      reload()
    } catch (err) {
      alertError(err, 'ساخت نسخه پشتیبان ممکن نشد.')
    } finally {
      setBusy(false)
    }
  }

  async function restore(file: File) {
    const confirmed = await confirmAction({
      title: 'بازیابی کل سیستم؟',
      text:
        'تمام دادهٔ فعلی پاک و با محتوای این فایل جایگزین می‌شود. ' +
        'این عمل برگشت‌پذیر نیست و نشست شما هم بسته خواهد شد.',
      confirmLabel: 'بازیابی کن',
      danger: true,
    })
    if (!confirmed) return

    setRestoring(true)
    setRestoreError(null)

    try {
      const form = new FormData()
      form.append('backup', file)

      await api('/system/backups/restore', { method: 'POST', body: form })

      // حساب کاربری فعلی هم بازنویسی شده و نشست بسته شده است.
      setUser(null)
      navigate('/auth', { replace: true })
    } catch (err) {
      setRestoreError(err instanceof ApiError ? err.message : 'بازیابی ناموفق بود.')
    } finally {
      setRestoring(false)
      if (fileRef.current) fileRef.current.value = ''
    }
  }

  return (
    <div className="flex flex-col gap-5">
      <header>
        <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
          بکاپ کل سیستم
        </h1>
        <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
          خروجی کامل از همهٔ مجتمع‌ها و جدول‌ها
        </p>
      </header>

      {isLoading && <LoadingState rows={3} />}
      {error && <ErrorState message={error} onRetry={reload} />}

      {data && !isLoading && (
        <>
          <BackupList
            backups={data.data}
            busy={busy}
            onCreate={createBackup}
            createLabel="گرفتن بکاپ کامل"
            emptyMessage="هنوز بکاپ کاملی گرفته نشده است."
          />

          <Card title="بازیابی از فایل" delay={0.05}>
            <div
              className="mb-4 flex items-start gap-2.5 rounded-xl px-4 py-3"
              style={{
                backgroundColor: 'color-mix(in srgb, var(--color-danger) 10%, transparent)',
                color: 'var(--color-danger)',
              }}
            >
              <AlertTriangle size={17} className="mt-0.5 shrink-0" />
              <p className="text-[13px] leading-6">
                بازیابی تمام جدول‌ها را خالی می‌کند و از روی فایل دوباره می‌سازد. دادهٔ فعلی از بین
                می‌رود و بعد از پایان، از حساب خارج می‌شوید. پیش از این کار حتماً یک بکاپ تازه
                بگیرید.
              </p>
            </div>

            {restoreError && (
              <p className="mb-3 text-[13px]" style={{ color: 'var(--color-danger)' }}>
                {restoreError}
              </p>
            )}

            <div className="flex flex-wrap items-center gap-3">
              <input
                ref={fileRef}
                type="file"
                accept=".json,application/json"
                disabled={restoring}
                onChange={(e) => {
                  const file = e.target.files?.[0]
                  if (file) void restore(file)
                }}
                className="block w-full max-w-sm rounded-xl border px-3 py-2.5 text-[13px] file:ml-3 file:rounded-lg file:border-0 file:px-3 file:py-1.5 file:text-white"
                style={{
                  backgroundColor: 'var(--surface-sunken)',
                  borderColor: 'var(--border-subtle)',
                  color: 'var(--text-primary)',
                }}
              />

              {restoring && (
                <span className="flex items-center gap-2 text-[13px]" style={{ color: 'var(--text-secondary)' }}>
                  <Loader2 size={15} className="animate-spin" />
                  در حال بازیابی…
                </span>
              )}

              {!restoring && (
                <span className="flex items-center gap-1.5 text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
                  <Upload size={12} />
                  فقط فایل JSON بکاپ، حداکثر ۵۰ مگابایت
                </span>
              )}
            </div>
          </Card>
        </>
      )}
    </div>
  )
}
