import { Link } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, BarChart3 } from 'lucide-react'
import { Card } from '@/shared/ui/Card'
import { queryKeys } from '@/shared/lib/queryKeys'
import { PollCard, type MessagePoll } from '@/features/messaging/messenger/PollCard'

/**
 * کارتِ نظرسنجی‌های باز در داشبورد (R24).
 *
 * ─── چرا اینجا و نه فقط در پیام‌رسان ───────────────────────────────────────
 * نظرسنجی در جریانِ گفت‌وگو بالا می‌رود و پس از چند پیام دیده نمی‌شود.
 * مشارکتِ پایین در ساختمان معمولاً بی‌علاقگی نیست، فراموشی است — و راهِ حلش
 * یادآوریِ پیامکی نیست (پیامک فقط برای کدِ ورود است)، بلکه نگه‌داشتنِ
 * نظرسنجی جلوی چشم تا وقتی مهلتش تمام نشده.
 *
 * همان `PollCard` پیام‌رسان استفاده می‌شود، نه یک نسخه‌ی دوم: رأی‌دادن از
 * داشبورد و از چت باید دقیقاً یک رفتار داشته باشد.
 */
export function OpenPollsCard({
  polls,
  isAdmin = false,
}: {
  polls: MessagePoll[]
  isAdmin?: boolean
}) {
  const queryClient = useQueryClient()

  if (polls.length === 0) return null

  return (
    <Card>
      <div className="mb-3 flex items-center justify-between">
        <h2
          className="flex items-center gap-2 text-[15px] font-bold"
          style={{ color: 'var(--text-primary)' }}
        >
          <BarChart3 size={16} style={{ color: 'var(--color-brand-500)' }} />
          نظرسنجی‌های باز
        </h2>

        <Link
          to="/messenger"
          className="flex items-center gap-1 text-[12px]"
          style={{ color: 'var(--color-brand-500)' }}
        >
          پیام‌رسان
          <ArrowLeft size={13} />
        </Link>
      </div>

      <div className="flex flex-col gap-3">
        {polls.map((poll) => (
          <PollCard
            key={poll.id}
            poll={poll}
            isMine={false}
            isAdmin={isAdmin}
            /*
             * نتیجه‌ی تازه مستقیم در کشِ داشبورد نشانده می‌شود؛ بدونِ این،
             * کارت تا واکشیِ بعدی رأیِ ثبت‌شده را نشان نمی‌داد و کاربر فکر
             * می‌کرد رأیش نخورده و دوباره کلیک می‌کرد.
             */
            onVoted={(updated) =>
              queryClient.setQueryData(
                queryKeys.dashboard.all(),
                (current: { openPolls?: MessagePoll[] } | undefined) =>
                  current
                    ? {
                        ...current,
                        openPolls: (current.openPolls ?? []).map((p) =>
                          p.id === updated.id ? updated : p,
                        ),
                      }
                    : current,
              )
            }
          />
        ))}
      </div>
    </Card>
  )
}
