import { useState } from 'react'
import { BackupList, type BackupRow } from '@/shared/ui/BackupList'
import { ErrorState, LoadingState } from '@/shared/ui/PageState'
import { useApi } from '@/shared/hooks/useApi'
import { useDocumentTitle } from '@/shared/hooks'
import { api } from '@/shared/lib/api'
import { alertError, toastSuccess } from '@/shared/lib/alert'
import { RestorePanel } from './RestorePanel'

export function SystemBackupPage() {
  const [busy, setBusy] = useState(false)

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

          <RestorePanel />
        </>
      )}
    </div>
  )
}
