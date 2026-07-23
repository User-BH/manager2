import { useState } from 'react'
import { ShieldCheck } from 'lucide-react'
import { Card } from '@/components/ui/Card'
import { BackupList, type BackupRow } from '@/components/ui/BackupList'
import { ErrorState, LoadingState } from '@/components/ui/PageState'
import { useApi } from '@/hooks/useApi'
import { useDocumentTitle } from '@/hooks'
import { api } from '@/lib/api'
import { alertError, toastSuccess } from '@/lib/alert'

export function ComplexBackupPage() {
  const [busy, setBusy] = useState(false)

  useDocumentTitle('بکاپ مجتمع')

  const { data, error, isLoading, reload } = useApi<{ data: BackupRow[] }>('/backups')

  async function createBackup() {
    setBusy(true)
    try {
      await api('/backups', { method: 'POST' })
      toastSuccess('نسخه پشتیبان ساخته شد.')
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
          بکاپ مجتمع
        </h1>
        <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
          خروجی JSON خودکفا از دادهٔ همین مجتمع
        </p>
      </header>

      <Card>
        <div className="flex items-start gap-2.5">
          <span style={{ color: 'var(--color-brand-500)' }}>
            <ShieldCheck size={18} />
          </span>
          <p className="text-[13px] leading-7" style={{ color: 'var(--text-secondary)' }}>
            بکاپ شامل واحدها، ساکنین، قوانین شارژ، هزینه‌ها، قبوض، پرداخت‌ها و اطلاعیه‌های همین
            مجتمع است. رمز عبور کاربران در خروجی نوشته نمی‌شود. فایل روی دیسک خصوصی سرور ذخیره
            می‌شود و فقط از همین صفحه قابل دانلود است.
          </p>
        </div>
      </Card>

      {isLoading && <LoadingState rows={3} />}
      {error && <ErrorState message={error} onRetry={reload} />}

      {data && !isLoading && (
        <BackupList
          backups={data.data}
          busy={busy}
          onCreate={createBackup}
          createLabel="گرفتن بکاپ جدید"
          emptyMessage="هنوز بکاپی گرفته نشده است."
          delay={0.05}
        />
      )}
    </div>
  )
}
