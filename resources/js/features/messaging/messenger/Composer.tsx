import { useEffect, useRef, useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { BarChart3, Loader2, Mic, Paperclip, Pencil, Plus, Reply, Send, X } from 'lucide-react'
import { z } from 'zod'
import { alertError } from '@/shared/lib/alert'
import { EmojiPicker } from './EmojiPicker'
import { PollComposer, emptyPoll, type PollDraft } from './PollComposer'
import { HoldRecorder, isRecordingSupported } from './HoldRecorder'
import type { ChatMessage, UploadRules } from './types'

/**
 * پیش‌آگهیِ سقف و نوع (۱۰/۱۲) — همان قاعده‌ی سرور (`AttachmentPolicy`)، فقط تا کاربر پیش از آپلودِ طولانی بفهمد.
 * سرور خودش با محتوای فایل دوباره می‌سنجد. بی پاسخِ سرور، همان پیش‌فرض‌های `config/messenger.php`.
 */
const DEFAULT_UPLOADS: UploadRules = {
  limitsKb: {
    image: 20480,
    file: 102400,
    audio: 51200,
    video: 512000,
    voice: 1024,
    video_message: 20480,
  },
  extensions: [
    'jpg',
    'jpeg',
    'png',
    'webp',
    'gif',
    'mp4',
    'webm',
    'mov',
    'mp3',
    'm4a',
    'ogg',
    'wav',
    'aac',
    'pdf',
    'txt',
    'csv',
    'docx',
    'xlsx',
    'pptx',
    'doc',
    'xls',
  ],
}

function kindOf(name: string): 'image' | 'video' | 'audio' | 'file' {
  const extension = name.split('.').pop()?.toLowerCase() ?? ''
  if (['jpg', 'jpeg', 'png', 'webp', 'gif'].includes(extension)) return 'image'
  if (['mp4', 'webm', 'mov'].includes(extension)) return 'video'
  if (['mp3', 'm4a', 'ogg', 'wav', 'aac'].includes(extension)) return 'audio'
  return 'file'
}

const messageSchema = z.object({ body: z.string().max(1000, 'پیام بیش از حد طولانی است') })
type MessageFormValues = z.infer<typeof messageSchema>

/** آنچه برای ارسال آماده است — «دوباره» عیناً همین را با همان شناسه می‌فرستد. */
export interface OutgoingDraft {
  body: string
  attachment: File | null
  /** پیوست پیامِ صوتی است (فیلدِ `voice`، نه `attachment`). */
  voice: boolean
  /** پیوست پیامِ ویدیوییِ ضبط‌شده است (فیلدِ `video_message`، ۱۰/۱۲). */
  videoMessage?: boolean
  poll: PollDraft | null
  replyTo: ChatMessage | null
}

/** پاسخ به یک پیام، یا ویرایشِ پیامِ خود (مرحلهٔ ۶ · فاز ۱۴). */
export type ComposeTarget = { mode: 'reply' | 'edit'; message: ChatMessage } | null

/**
 * نوشتنِ پیام (مرحلهٔ ۶ · فاز ۱۲ و ۱۴).
 *
 * از `ConversationThread` جدا شد تا حالتِ فرم، پیوست، نظرسنجی و ضبط کنارِ
 * حالتِ فهرستِ پیام‌ها انباشته نشود.
 *
 * ⚠️ منتظرِ تحویل می‌ماند تا دکمه در این فاصله خاموش باشد: دوبار-کلیک همان
 * متن را با دو شناسه‌ی جدا دو بار می‌فرستاد (قراردادِ فرم‌ها).
 */
export function Composer({
  isAdmin,
  canPoll = isAdmin,
  uploads = DEFAULT_UPLOADS,
  target,
  onCancelTarget,
  onSend,
  onEdit,
  onNotice,
}: {
  isAdmin: boolean
  /** نظرسنجی فقط در گروه (۱۰/۱۱؛ بندِ ۶۱) — گفت‌وگوی خصوصی دکمه‌اش را ندارد */
  canPoll?: boolean
  /** سقف و پسوندهای سرور (۱۰/۱۲) */
  uploads?: UploadRules
  target: ComposeTarget
  onCancelTarget: () => void
  onSend: (draft: OutgoingDraft) => Promise<void>
  onEdit: (message: ChatMessage, body: string) => Promise<void>
  onNotice: (message: string | null) => void
}) {
  const [attachment, setAttachment] = useState<{ file: File; voice: boolean } | null>(null)
  const [pollDraft, setPollDraft] = useState<PollDraft | null>(null)
  // «بیشتر» (۱۲/۷): نظرسنجی و پیامِ ویدیویی، تا ردیفِ نوشتن یک ردیف بماند
  const [extrasOpen, setExtrasOpen] = useState(false)
  const fileInputRef = useRef<HTMLInputElement>(null)
  const textareaRef = useRef<HTMLTextAreaElement | null>(null)

  const {
    register,
    handleSubmit,
    reset,
    setValue,
    setError: setFormError,
    control,
    formState: { errors, isSubmitting },
  } = useForm<MessageFormValues>({
    resolver: zodResolver(messageSchema),
    defaultValues: { body: '' },
  })

  const editing = target?.mode === 'edit' ? target.message : null

  // ویرایش: متنِ همان پیام در کادر؛ پاسخ: فقط فوکوس
  useEffect(() => {
    if (editing) setValue('body', editing.body ?? '')
    if (target) textareaRef.current?.focus()
  }, [target, editing, setValue])

  function insertEmoji(emoji: string) {
    const field = textareaRef.current
    if (!field) return
    const start = field.selectionStart ?? field.value.length
    const end = field.selectionEnd ?? start
    setValue('body', field.value.slice(0, start) + emoji + field.value.slice(end), {
      shouldValidate: true,
    })
    requestAnimationFrame(() => {
      field.focus()
      field.setSelectionRange(start + emoji.length, start + emoji.length)
    })
  }

  function pickAttachment(file: File | null) {
    if (file) {
      const extension = file.name.split('.').pop()?.toLowerCase() ?? ''
      if (!uploads.extensions.includes(extension)) {
        onNotice('این نوع فایل پذیرفته نیست.')
        return
      }
      const limitKb = uploads.limitsKb[kindOf(file.name)]
      if (file.size > limitKb * 1024) {
        onNotice(`حجمِ فایل از سقفِ ${Math.round(limitKb / 1024)} مگابایت بیشتر است.`)
        return
      }
    }
    onNotice(null)
    setAttachment(file ? { file, voice: false } : null)
  }

  /**
   * پیامِ صوتی یا ویدیوییِ ضبط‌شده همان لحظه‌ی رها کردن فرستاده می‌شود (بندهای ۴۴ و ۴۵) — نه پیوستی که باید دوباره
   * «ارسال» شود. پاسخ به پیام اگر انتخاب شده، همراهش می‌رود.
   */
  async function sendRecorded(file: File, kind: 'voice' | 'video_message') {
    const limitKb = uploads.limitsKb[kind]
    if (file.size > limitKb * 1024) {
      onNotice(`ضبط از سقفِ ${Math.round(limitKb / 1024) || 1} مگابایت بیشتر شد.`)
      return
    }
    onNotice(null)
    await onSend({
      body: '',
      attachment: file,
      voice: kind === 'voice',
      videoMessage: kind === 'video_message',
      poll: null,
      replyTo: target?.mode === 'reply' ? target.message : null,
    })
  }

  async function onSubmit(values: MessageFormValues) {
    if (editing) {
      if (!values.body.trim() && !editing.attachment && !editing.poll) {
        setFormError('body', { message: 'متن پیام را وارد کنید' })
        return
      }
      try {
        await onEdit(editing, values.body)
        reset()
      } catch (error) {
        alertError(error, 'ویرایشِ پیام ثبت نشد.')
      }
      return
    }

    const hasPoll = Boolean(pollDraft?.question.trim())

    if (!values.body.trim() && !attachment && !hasPoll) {
      setFormError('body', { message: 'متن پیام را وارد کنید' })
      return
    }
    if (hasPoll && pollDraft!.options.filter((o) => o.text.trim()).length < 2) {
      onNotice('نظرسنجی باید دست‌کم دو گزینه داشته باشد.')
      return
    }

    const draft: OutgoingDraft = {
      body: values.body,
      attachment: attachment?.file ?? null,
      voice: attachment?.voice ?? false,
      poll: hasPoll ? pollDraft : null,
      replyTo: target?.mode === 'reply' ? target.message : null,
    }

    onNotice(null)
    reset()
    setAttachment(null)
    setPollDraft(null)

    await onSend(draft)
  }

  const bodyField = register('body')
  const body = useWatch({ control, name: 'body' }) ?? ''
  const recording = isRecordingSupported()
  const hasExtras = recording || canPoll
  // دکمه‌ی اصلی: ضبطِ صدا فقط وقتی هیچ چیزِ آماده‌ی ارسالی نیست (و مرورگر ضبط دارد)
  const showRecorder = recording && !editing && !attachment && !pollDraft && !body.trim()

  return (
    <div className="flex flex-col">
      {target && (
        <div
          className="mb-2 flex items-center gap-2 rounded-lg px-3 py-2 text-small"
          style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--text-secondary)' }}
        >
          {editing ? <Pencil size={13} /> : <Reply size={13} />}
          <span className="min-w-0 flex-1 truncate">
            {editing
              ? 'ویرایشِ پیام'
              : `پاسخ به ${target.message.authorName}: ${target.message.body ?? ''}`}
          </span>
          <button
            type="button"
            onClick={() => {
              if (editing) reset()
              onCancelTarget()
            }}
            aria-label={editing ? 'لغوِ ویرایش' : 'لغوِ پاسخ'}
          >
            <X size={14} />
          </button>
        </div>
      )}

      {pollDraft && canPoll && !editing && (
        <PollComposer
          draft={pollDraft}
          onChange={setPollDraft}
          onClose={() => setPollDraft(null)}
        />
      )}

      {attachment && (
        <div
          className="mb-2 flex items-center gap-2 rounded-lg px-3 py-2 text-small"
          style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--text-secondary)' }}
        >
          {attachment.voice ? <Mic size={13} /> : <Paperclip size={13} />}
          <span className="flex-1 truncate">
            {attachment.voice ? 'پیامِ صوتی آماده‌ی ارسال' : attachment.file.name}
          </span>
          <button type="button" onClick={() => pickAttachment(null)} aria-label="حذف پیوست">
            <X size={14} />
          </button>
        </div>
      )}

      {/* «بیشتر»: نظرسنجی و پیامِ ویدیویی — فقط آن‌چه این گفت‌وگو و این مرورگر دارد */}
      {extrasOpen && !editing && hasExtras && (
        <div
          role="group"
          aria-label="بیشتر"
          className="mb-2 flex items-center gap-2 rounded-xl px-2 py-1"
          style={{ backgroundColor: 'var(--surface-sunken)' }}
        >
          {recording && (
            <span
              className="flex items-center gap-1 text-tiny"
              style={{ color: 'var(--text-tertiary)' }}
            >
              <HoldRecorder
                mode="video"
                onRecorded={(file) => void sendRecorded(file, 'video_message')}
                onError={onNotice}
              />
              پیامِ ویدیویی
            </span>
          )}
          {canPoll && (
            <button
              type="button"
              onClick={() => setPollDraft((draft) => (draft ? null : emptyPoll()))}
              aria-label="ساخت نظرسنجی"
              aria-pressed={pollDraft !== null}
              className="flex h-9 items-center gap-1 rounded-lg px-2 text-tiny"
              style={{ color: pollDraft ? 'var(--color-brand-500)' : 'var(--text-tertiary)' }}
            >
              <BarChart3 size={16} /> نظرسنجی
            </button>
          )}
        </div>
      )}

      {/*
       * یک ردیف (۱۲/۷، بندهای ۴۴ و ۴۵): اموجی، متن، پیوست و «بیشتر» درونِ کادر؛ کنارش دکمه‌ی اصلی — ضبطِ صدا تا کادر
       * خالی است و «ارسال» از نخستین نویسه، پیوست یا نظرسنجی. همه‌ی دکمه‌ها دست‌کم ۳۶ پیکسل.
       */}
      <form
        onSubmit={(event) => void handleSubmit(onSubmit)(event)}
        className="flex items-end gap-2"
      >
        <div className="field composer-field flex min-w-0 flex-1 items-end gap-0.5 px-1">
          <div className="py-1">
            <EmojiPicker onPick={insertEmoji} />
          </div>
          <textarea
            rows={1}
            aria-label="متنِ پیام"
            placeholder="پیام…"
            className="composer-input min-w-0 flex-1 resize-none bg-transparent px-1 py-2.5 text-body"
            style={{ color: 'var(--text-primary)' }}
            aria-invalid={Boolean(errors.body) || undefined}
            {...bodyField}
            ref={(element) => {
              bodyField.ref(element)
              textareaRef.current = element
            }}
          />
          {!editing && (
            <div className="flex items-center py-1">
              <button
                type="button"
                onClick={() => fileInputRef.current?.click()}
                aria-label="پیوست فایل"
                className="flex h-9 w-9 items-center justify-center rounded-full"
                style={{ color: 'var(--text-tertiary)' }}
              >
                <Paperclip size={18} />
              </button>
              <input
                ref={fileInputRef}
                type="file"
                accept={uploads.extensions.map((extension) => `.${extension}`).join(',')}
                className="hidden"
                onChange={(event) => {
                  pickAttachment(event.target.files?.[0] ?? null)
                  event.target.value = ''
                }}
              />
              {hasExtras && (
                <button
                  type="button"
                  onClick={() => setExtrasOpen((open) => !open)}
                  aria-label="بیشتر: نظرسنجی و پیامِ ویدیویی"
                  aria-expanded={extrasOpen}
                  className="flex h-9 w-9 items-center justify-center rounded-full"
                  style={{ color: extrasOpen ? 'var(--color-brand-500)' : 'var(--text-tertiary)' }}
                >
                  <Plus size={19} />
                </button>
              )}
            </div>
          )}
        </div>

        {showRecorder ? (
          <HoldRecorder
            primary
            mode="voice"
            onRecorded={(file) => void sendRecorded(file, 'voice')}
            onError={onNotice}
          />
        ) : (
          <button
            type="submit"
            disabled={isSubmitting}
            aria-label={editing ? 'ذخیره‌ی ویرایش' : 'ارسال پیام'}
            className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-white disabled:opacity-60"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            {isSubmitting ? <Loader2 size={19} className="animate-spin" /> : <Send size={19} />}
          </button>
        )}
      </form>
      {errors.body && (
        <p className="mt-1 text-small" style={{ color: 'var(--color-danger)' }}>
          {errors.body.message}
        </p>
      )}
    </div>
  )
}
