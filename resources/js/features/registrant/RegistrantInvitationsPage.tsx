import { MailOpen } from 'lucide-react'
import { useDocumentTitle } from '@/shared/hooks'
import { ReceivedInvitations } from '@/shared/ui/invitations/ReceivedInvitations'

/**
 * دعوت‌های مدیرانِ مجتمع‌ها — صفحه‌ی داشبوردِ اولیه (مرحلهٔ ۱۳ · فاز ۵؛ ایرادات ۱۱، بندهای ۳.۴، ۳.۶ و ۵.۱۳).
 *
 * مقصدِ اعلانِ دعوت و گزینه‌ی «دعوت‌ها» ِ منوی آدمک؛ از داشبوردِ مجتمع با زمینه‌ی اولیه به این‌جا می‌آییم. هر دعوت با
 * داده‌ی واقعیِ سرور (`GET invitations`): نامِ مجتمع، واحد، نقش و دعوت‌کننده؛ پیوستن با کدِ تأییدِ تازه و رد. دیدنِ
 * این صفحه یا خواندنِ اعلان هیچ عضویتی نمی‌سازد (بندِ ۳.۵).
 */
export function RegistrantInvitationsPage() {
  useDocumentTitle('دعوت‌ها')

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-5">
      <header>
        <h1 className="text-lg font-extrabold" style={{ color: 'var(--text-primary)' }}>
          دعوت‌ها
        </h1>
        <p className="mt-1 text-body leading-6" style={{ color: 'var(--text-secondary)' }}>
          دعوت‌هایی که مدیرانِ مجتمع‌ها برای شما فرستاده‌اند. پیوستن با کدِ تأییدی است که به شماره‌ی
          خودتان می‌رسد؛ تا آن نزنید، عضو نمی‌شوید.
        </p>
      </header>

      <ReceivedInvitations
        showTitle={false}
        empty={
          <div
            className="flex flex-col items-center gap-2 rounded-2xl border border-dashed p-8 text-center"
            style={{ borderColor: 'var(--border-default)' }}
          >
            <MailOpen size={26} aria-hidden="true" style={{ color: 'var(--text-tertiary)' }} />
            <p className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>
              دعوتِ در انتظاری ندارید.
            </p>
            <p className="text-small" style={{ color: 'var(--text-tertiary)' }}>
              دعوتی که پذیرفته، رد، باطل یا منقضی شده باشد این‌جا نمی‌ماند.
            </p>
          </div>
        }
      />
    </div>
  )
}
