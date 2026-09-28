import { useForm, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2 } from 'lucide-react'
import { z } from 'zod'
import { Modal } from '@/shared/ui/Modal'
import { CheckField, SelectField, TextField } from '@/shared/ui/Field'
import { api } from '@/shared/lib/api'
import { alertError } from '@/shared/lib/alert'
import type { ConversationSummary } from './types'

const schema = z
  .object({
    type: z.enum(['unit', 'group']),
    unitId: z.string(),
    title: z.string().trim().max(120, 'نام حداکثر ۱۲۰ نویسه است.'),
    unitIds: z.array(z.string()),
    membersCanPost: z.boolean(),
  })
  .superRefine((values, ctx) => {
    if (values.type === 'unit' && !values.unitId) {
      ctx.addIssue({ code: 'custom', path: ['unitId'], message: 'واحد را انتخاب کنید.' })
    }
    if (values.type === 'group' && !values.title) {
      ctx.addIssue({ code: 'custom', path: ['title'], message: 'نامِ گروه را بنویسید.' })
    }
    if (values.type === 'group' && values.unitIds.length === 0) {
      ctx.addIssue({ code: 'custom', path: ['unitIds'], message: 'دست‌کم یک واحد انتخاب کنید.' })
    }
  })

type Values = z.infer<typeof schema>

/**
 * شروعِ گفت‌وگو — فقط مدیریت (مرحلهٔ ۶ · فاز ۱۲): رشته‌ی خصوصی با یک واحد
 * (اگر هست، همان باز می‌شود) یا گروهی از واحدها.
 *
 * ⚠️ اعضای گروه پس از ساخت عوض نمی‌شوند؛ این در خودِ فرم گفته می‌شود.
 */
export function NewConversationDialog({
  units,
  onClose,
  onCreated,
}: {
  units: { id: number; label: string }[]
  onClose: () => void
  onCreated: (conversation: ConversationSummary) => void
}) {
  const {
    register,
    handleSubmit,
    control,
    formState: { errors, isSubmitting },
  } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { type: 'unit', unitId: '', title: '', unitIds: [], membersCanPost: true },
  })

  const type = useWatch({ control, name: 'type' })

  async function submit(values: Values) {
    try {
      const { conversation } = await api<{ conversation: ConversationSummary }>(
        '/messenger/conversations',
        {
          method: 'POST',
          body:
            values.type === 'unit'
              ? { type: 'unit', unit_id: Number(values.unitId) }
              : {
                  type: 'group',
                  title: values.title,
                  unit_ids: values.unitIds.map(Number),
                  members_can_post: values.membersCanPost,
                },
        },
      )
      onCreated(conversation)
    } catch (error) {
      alertError(error, 'ساختِ گفت‌وگو ممکن نشد.')
    }
  }

  return (
    <Modal open title="گفت‌وگوی تازه" onClose={onClose}>
      <form onSubmit={handleSubmit(submit)} className="flex flex-col gap-4 p-5">
        <SelectField
          label="نوع"
          options={[
            { value: 'unit', label: 'خصوصی با یک واحد' },
            { value: 'group', label: 'گروهی از واحدها' },
          ]}
          {...register('type')}
        />

        {type === 'unit' ? (
          <SelectField
            label="واحد"
            placeholder="انتخاب کنید"
            options={units.map((u) => ({ value: String(u.id), label: u.label }))}
            error={errors.unitId?.message}
            {...register('unitId')}
          />
        ) : (
          <>
            <TextField label="نامِ گروه" error={errors.title?.message} {...register('title')} />
            <fieldset className="flex flex-col gap-2">
              <legend
                className="mb-1 text-small font-medium"
                style={{ color: 'var(--text-secondary)' }}
              >
                واحدها (پس از ساخت عوض نمی‌شوند)
              </legend>
              <div className="grid max-h-48 grid-cols-2 gap-1.5 overflow-y-auto sm:grid-cols-3">
                {units.map((unit) => (
                  <label key={unit.id} className="flex items-center gap-1.5 text-small">
                    <input type="checkbox" value={String(unit.id)} {...register('unitIds')} />
                    {unit.label}
                  </label>
                ))}
              </div>
              {errors.unitIds && (
                <p role="alert" className="text-small" style={{ color: 'var(--color-danger)' }}>
                  {errors.unitIds.message}
                </p>
              )}
            </fieldset>
            <CheckField label="اعضا هم بنویسند" {...register('membersCanPost')} />
          </>
        )}

        <div className="flex justify-end gap-2">
          <button
            type="button"
            onClick={onClose}
            className="rounded-xl border px-4 py-2 text-small"
            style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
          >
            انصراف
          </button>
          <button
            type="submit"
            disabled={isSubmitting}
            className="flex items-center gap-2 rounded-xl px-4 py-2 text-small font-semibold text-white disabled:opacity-50"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            {isSubmitting && <Loader2 size={16} className="animate-spin" />}
            باز کن
          </button>
        </div>
      </form>
    </Modal>
  )
}
