import { useState } from 'react'
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { Modal } from '@/shared/ui/Modal'
import { ErrorState, InlineSpinner } from '@/shared/ui/PageState'
import { UserAvatar } from '@/shared/ui/UserAvatar'
import { api } from '@/shared/lib/api'
import { alertError } from '@/shared/lib/alert'
import { formatNumber } from '@/shared/lib/format'
import { queryKeys } from '@/shared/lib/queryKeys'
import type { GroupInfo } from './types'

/**
 * اطلاعاتِ گروه (مرحلهٔ ۱۰ · فاز ۹؛ بندهای ۳۵، ۳۶ و ۷۰ ِ فایلِ معماری).
 *
 * نام، تصویر، شمارِ اعضا، مالکان و مستأجران، و فهرستِ اعضا با تصویر، نام، نقش و واحد. همه را سرور از **عضویتِ
 * فعال** می‌شمارد؛ این‌جا چیزی شمرده نمی‌شود. فهرست ۵۰ تایی است و «اعضای بیشتر» صفحه‌ی بعد را هم می‌آورد.
 * مدیر همین‌جا «فقط مدیریت بنویسد» را می‌گذارد یا برمی‌دارد.
 */
export function GroupInfoPanel({
  conversationId,
  onClose,
}: {
  conversationId: number
  onClose: () => void
}) {
  const queryClient = useQueryClient()
  const [pages, setPages] = useState(1)

  const { data, error, isLoading, isFetching, refetch } = useQuery({
    queryKey: queryKeys.messenger.info(conversationId, pages),
    queryFn: async ({ signal }) => {
      const read: GroupInfo[] = []
      do {
        read.push(
          await api<GroupInfo>(
            `/messenger/conversations/${conversationId}/info?page=${read.length + 1}`,
            { signal },
          ),
        )
      } while (read[read.length - 1]?.hasMore && read.length < pages)

      return { ...read[read.length - 1], members: read.flatMap((page) => page.members) }
    },
    placeholderData: keepPreviousData,
  })

  async function setPosting(membersCanPost: boolean) {
    try {
      await api(`/messenger/conversations/${conversationId}/posting`, {
        method: 'POST',
        body: { members_can_post: membersCanPost },
      })
      await queryClient.invalidateQueries({ queryKey: queryKeys.messenger.all() })
    } catch (failure) {
      alertError(failure, 'تغییر ثبت نشد.')
    }
  }

  return (
    <Modal open title="اطلاعاتِ گروه" onClose={onClose}>
      {isLoading && <InlineSpinner />}
      {error && <ErrorState error={error} onRetry={() => void refetch()} />}

      {data && (
        <div className="flex flex-col gap-4">
          <div className="flex items-center gap-3">
            <UserAvatar name={data.conversation.title} url={null} size={48} />
            <div className="min-w-0">
              <p className="truncate text-body font-bold" style={{ color: 'var(--text-primary)' }}>
                {data.conversation.title}
              </p>
              <p className="text-small" style={{ color: 'var(--text-tertiary)' }}>
                {formatNumber(data.counts.members)} عضو
              </p>
            </div>
          </div>

          <dl className="grid grid-cols-3 gap-2 text-center">
            {[
              ['اعضا', data.counts.members],
              ['مالکان', data.counts.owners],
              ['مستأجران', data.counts.tenants],
            ].map(([label, value]) => (
              <div
                key={label}
                className="rounded-xl py-2"
                style={{ backgroundColor: 'var(--surface-sunken)' }}
              >
                <dt className="text-tiny" style={{ color: 'var(--text-tertiary)' }}>
                  {label}
                </dt>
                <dd className="text-body font-bold" style={{ color: 'var(--text-primary)' }}>
                  {formatNumber(Number(value))}
                </dd>
              </div>
            ))}
          </dl>

          {data.conversation.canManage && (
            <label
              className="flex items-center gap-2 text-small"
              style={{ color: 'var(--text-secondary)' }}
            >
              <input
                type="checkbox"
                checked={!data.conversation.membersCanPost}
                onChange={(event) => void setPosting(!event.target.checked)}
              />
              فقط مدیریت در این گروه بنویسد
            </label>
          )}

          <ul aria-label="اعضای گروه" className="flex flex-col gap-1">
            {data.members.map((member) => (
              <li key={member.id} className="flex items-center gap-3 rounded-xl px-2 py-1.5">
                <UserAvatar name={member.name} url={member.avatarUrl} size={36} />
                <span className="min-w-0 flex-1">
                  <span
                    className="block truncate text-small font-semibold"
                    style={{ color: 'var(--text-primary)' }}
                  >
                    {member.name}
                  </span>
                  <span className="block text-tiny" style={{ color: 'var(--text-tertiary)' }}>
                    {[member.roleLabel, member.unit].filter(Boolean).join(' · ')}
                    {member.isModerator && ' · ناظرِ گروه'}
                  </span>
                </span>
              </li>
            ))}
          </ul>

          {data.hasMore && (
            <button
              type="button"
              disabled={isFetching}
              onClick={() => setPages((count) => count + 1)}
              className="rounded-xl py-2 text-small font-semibold disabled:opacity-60"
              style={{ color: 'var(--color-brand-600)', backgroundColor: 'var(--surface-sunken)' }}
            >
              {isFetching ? 'در حال بارگذاری…' : 'اعضای بیشتر'}
            </button>
          )}
        </div>
      )}
    </Modal>
  )
}
