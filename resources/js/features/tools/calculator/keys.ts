/**
 * تکه‌ای از ماشین‌حساب که از `CalculatorPage.tsx` بیرون کشیده شد (R39 · آیتم ⑤).
 *
 * ⚠️ این جابه‌جایی عمداً **هیچ منطقی را عوض نمی‌کند**: فایلِ اصلی ۹۶۸ خط بود
 * و همه‌ی زیرکامپوننت‌هایش را هم داخلِ خودش داشت. تجزیه از قبل انجام شده
 * بود؛ چیزی که نبود، مرزِ فایلی بود.
 */

import type { KeySpec } from './types'

export const SCIENTIFIC: KeySpec[][] = [
  [
    { label: '2nd', variant: 'function', title: 'توابع معکوس' },
    { label: 'sin', insert: 'sin(', variant: 'function' },
    { label: 'cos', insert: 'cos(', variant: 'function' },
    { label: 'tan', insert: 'tan(', variant: 'function' },
    { label: 'π', insert: 'pi', variant: 'function' },
  ],
  [
    { label: 'x²', insert: 'sqr(', variant: 'function', title: 'مجذور' },
    { label: 'xʸ', insert: '^', variant: 'function', title: 'به توان' },
    { label: '√', insert: 'sqrt(', variant: 'function', title: 'جذر' },
    { label: '∛', insert: 'cbrt(', variant: 'function', title: 'ریشه سوم' },
    { label: 'e', insert: 'e', variant: 'function' },
  ],
  [
    { label: 'ln', insert: 'ln(', variant: 'function', title: 'لگاریتم طبیعی' },
    { label: 'log', insert: 'log(', variant: 'function', title: 'لگاریتم مبنای ۱۰' },
    { label: '1/x', insert: 'inv(', variant: 'function', title: 'معکوس' },
    { label: 'n!', insert: '!', variant: 'function', title: 'فاکتوریل' },
    { label: '|x|', insert: 'abs(', variant: 'function', title: 'قدرمطلق' },
  ],
]

export const SECOND_ROW: KeySpec[] = [
  { label: '2nd', variant: 'function', title: 'بازگشت' },
  { label: 'sin⁻¹', insert: 'asin(', variant: 'function' },
  { label: 'cos⁻¹', insert: 'acos(', variant: 'function' },
  { label: 'tan⁻¹', insert: 'atan(', variant: 'function' },
  { label: 'mod', insert: ' mod ', variant: 'function', title: 'باقیمانده' },
]

export const SECOND_ROWS: KeySpec[][] = [
  SECOND_ROW,
  [
    { label: 'sinh', insert: 'sinh(', variant: 'function' },
    { label: 'cosh', insert: 'cosh(', variant: 'function' },
    { label: 'tanh', insert: 'tanh(', variant: 'function' },
    { label: 'eˣ', insert: 'exp(', variant: 'function' },
    { label: 'log₂', insert: 'log2(', variant: 'function' },
  ],
  [
    { label: 'round', insert: 'round(', variant: 'function', title: 'گرد کردن' },
    { label: '⌊x⌋', insert: 'floor(', variant: 'function', title: 'کف' },
    { label: '⌈x⌉', insert: 'ceil(', variant: 'function', title: 'سقف' },
    { label: 'n!', insert: '!', variant: 'function', title: 'فاکتوریل' },
    { label: '|x|', insert: 'abs(', variant: 'function', title: 'قدرمطلق' },
  ],
]

export const NUMPAD: KeySpec[][] = [
  [
    { label: 'AC', variant: 'danger', title: 'پاک کردن همه' },
    { label: '⌫', variant: 'danger', title: 'حذف آخرین نویسه' },
    { label: '%', variant: 'operator', title: 'درصد' },
    { label: '÷', insert: '/', variant: 'operator' },
  ],
  [
    { label: '7', variant: 'digit' },
    { label: '8', variant: 'digit' },
    { label: '9', variant: 'digit' },
    { label: '×', insert: '*', variant: 'operator' },
  ],
  [
    { label: '4', variant: 'digit' },
    { label: '5', variant: 'digit' },
    { label: '6', variant: 'digit' },
    { label: '−', insert: '-', variant: 'operator' },
  ],
  [
    { label: '1', variant: 'digit' },
    { label: '2', variant: 'digit' },
    { label: '3', variant: 'digit' },
    { label: '+', variant: 'operator' },
  ],
  [
    { label: '(', variant: 'operator' },
    { label: '0', variant: 'digit' },
    { label: '.', variant: 'digit' },
    { label: ')', variant: 'operator' },
  ],
]
