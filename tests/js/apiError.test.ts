import { describe, expect, it } from 'vitest'
import { ApiError } from '@/lib/api'

/**
 * `ApiError` پلِ میانِ خطاهای اعتبارسنجیِ لاراول و پیامِ زیرِ هر ورودی است.
 * اگر `fieldError` درست کار نکند، کاربر خطای سرور را اصلاً نمی‌بیند.
 */
describe('ApiError', () => {
  it('پیام و وضعیت را نگه می‌دارد', () => {
    const error = new ApiError('خطای اعتبارسنجی', 422)
    expect(error.message).toBe('خطای اعتبارسنجی')
    expect(error.status).toBe(422)
    expect(error.name).toBe('ApiError')
  })

  it('یک نمونه‌ی واقعی از Error است (تا instanceof در catch کار کند)', () => {
    expect(new ApiError('x', 500)).toBeInstanceOf(Error)
  })

  it('اولین پیامِ خطای یک فیلد را برمی‌گرداند', () => {
    const error = new ApiError('خطا', 422, {
      phone: ['شماره تلفن یا رمز عبور نادرست است.', 'پیام دوم'],
    })
    expect(error.fieldError('phone')).toBe('شماره تلفن یا رمز عبور نادرست است.')
  })

  it('برای فیلدی که خطا ندارد undefined می‌دهد', () => {
    const error = new ApiError('خطا', 422, { phone: ['نادرست'] })
    expect(error.fieldError('password')).toBeUndefined()
  })

  it('وقتی هیچ خطای فیلدی نیست، ساختار خالیِ امن دارد', () => {
    const error = new ApiError('خطای سرور', 500)
    expect(error.errors).toEqual({})
    expect(error.fieldError('anything')).toBeUndefined()
  })
})
