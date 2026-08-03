import { describe, expect, it } from 'vitest'

import { availableMoves, type ServiceRequest } from '@/features/requests/types'

/**
 * دکمه‌های تغییرِ وضعیتِ درخواست (R25).
 *
 * ⚠️ این تابع **مجوز نیست** — قاعده‌ی واقعی در `ServiceRequestService` سمتِ
 * سرور اعمال می‌شود و تستِ Feature سنجیده‌اش. کارِ این تست فقط این است که
 * دکمه‌ای نشان داده نشود که کلیکش قطعاً ۴۲۲ می‌گیرد؛ کاربری که سه دکمه
 * می‌بیند و هر سه خطا می‌دهند، به سامانه اعتماد نمی‌کند.
 */
function make(overrides: Partial<ServiceRequest> = {}): ServiceRequest {
  return {
    id: 1,
    title: 'آسانسور',
    description: 'گیر می‌کند',
    category: 'elevator',
    categoryLabel: 'آسانسور',
    priority: 'normal',
    priorityLabel: 'عادی',
    priorityColor: 'slate',
    status: 'new',
    statusLabel: 'ثبت‌شده',
    statusColor: 'sky',
    isOpen: true,
    unitLabel: 'واحد ۱',
    requesterName: 'علی',
    assignee: null,
    attachment: null,
    createdAt: '۱۴۰۵/۰۵/۱۲',
    resolvedAt: null,
    closedAt: null,
    can: { assign: false, noteInternally: false, isRequester: false, isAssignee: false },
    ...overrides,
  }
}

const labels = (...args: Parameters<typeof availableMoves>) =>
  availableMoves(...args).map((move) => move.status)

describe('availableMoves', () => {
  it('ساکنِ صاحبِ درخواست روی درخواستِ تازه هیچ دکمه‌ای ندارد', () => {
    expect(labels(make(), false, false, true)).toEqual([])
  })

  it('مسئول کار را برمی‌دارد و انجامش را اعلام می‌کند', () => {
    expect(labels(make(), false, true, false)).toEqual(['in_progress', 'resolved'])
  })

  it('رد کردن فقط به مدیر نشان داده می‌شود', () => {
    expect(labels(make(), true, false, false)).toContain('rejected')
    expect(labels(make(), false, true, false)).not.toContain('rejected')
  })

  it('پس از «انجام شد»، صاحبِ درخواست هم تایید دارد هم اعتراض', () => {
    expect(labels(make({ status: 'resolved' }), false, false, true)).toEqual([
      'closed',
      'in_progress',
    ])
  })

  it('مدیر نمی‌تواند به‌جای ساکن درخواست را ببندد', () => {
    expect(labels(make({ status: 'resolved' }), true, false, false)).toEqual(['in_progress'])
  })

  it('پرونده‌ی بسته فقط با مدیر دوباره باز می‌شود', () => {
    expect(labels(make({ status: 'closed' }), true, false, true)).toEqual(['in_progress'])
    expect(labels(make({ status: 'closed' }), false, false, true)).toEqual([])
  })

  it('روی درخواستِ ردشده، ساکن دکمه‌ای ندارد', () => {
    expect(labels(make({ status: 'rejected' }), false, false, true)).toEqual([])
  })
})
