/**
 * موتور محاسبه‌ی ماشین حساب مهندسی.
 *
 * عمداً از eval() یا new Function() استفاده نمی‌شود: ورودی از کاربر می‌آید و
 * در تاریخچه‌ی localStorage هم ذخیره می‌شود، پس اجرای آن به‌عنوان کد یعنی هر
 * چیزی که در آن ذخیره شود بعداً اجرا خواهد شد. به‌جایش عبارت توکنایز و با
 * الگوریتم shunting-yard به RPN تبدیل و بعد ارزیابی می‌شود.
 */

export type AngleMode = 'deg' | 'rad'

export class CalculationError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'CalculationError'
  }
}

type TokenType = 'number' | 'operator' | 'function' | 'paren' | 'constant'

interface Token {
  type: TokenType
  value: string
}

interface OperatorSpec {
  precedence: number
  associativity: 'left' | 'right'
  arity: 1 | 2
  /** عملگر یکانیِ پسوندی مثل ! و ٪ */
  postfix?: boolean
}

const OPERATORS: Record<string, OperatorSpec> = {
  '+': { precedence: 2, associativity: 'left', arity: 2 },
  '-': { precedence: 2, associativity: 'left', arity: 2 },
  '*': { precedence: 3, associativity: 'left', arity: 2 },
  '/': { precedence: 3, associativity: 'left', arity: 2 },
  mod: { precedence: 3, associativity: 'left', arity: 2 },
  '^': { precedence: 5, associativity: 'right', arity: 2 },
  // منفیِ یکانی؛ توکنایزر آن را از تفریق تشخیص می‌دهد و به این تبدیل می‌کند
  neg: { precedence: 4, associativity: 'right', arity: 1 },
  '!': { precedence: 6, associativity: 'left', arity: 1, postfix: true },
  '%': { precedence: 6, associativity: 'left', arity: 1, postfix: true },
}

const CONSTANTS: Record<string, number> = {
  pi: Math.PI,
  e: Math.E,
}

const FUNCTION_NAMES = [
  'sin', 'cos', 'tan', 'asin', 'acos', 'atan',
  'sinh', 'cosh', 'tanh',
  'ln', 'log', 'log2', 'sqrt', 'cbrt', 'abs', 'exp',
  'round', 'floor', 'ceil', 'inv', 'sqr',
] as const

type FunctionName = (typeof FUNCTION_NAMES)[number]

/** تبدیل ارقام فارسی/عربی به لاتین تا کاربر بتواند با هر صفحه‌کلیدی تایپ کند. */
export function normalizeDigits(input: string): string {
  return input
    .replace(/[۰-۹]/g, (d) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
    .replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)))
    .replace(/٫/g, '.')
}

function tokenize(input: string): Token[] {
  const source = normalizeDigits(input)
    .replace(/×/g, '*')
    .replace(/÷/g, '/')
    .replace(/−/g, '-')
    .replace(/π/g, 'pi')

  const tokens: Token[] = []
  let index = 0

  while (index < source.length) {
    const char = source[index]

    if (/\s/.test(char)) {
      index += 1
      continue
    }

    // عدد (با ممیز اختیاری و نماد علمی)
    if (/[0-9.]/.test(char)) {
      const match = /^\d*\.?\d+(?:[eE][+-]?\d+)?|^\d+\.?/.exec(source.slice(index))
      if (!match) throw new CalculationError('عدد نامعتبر است.')

      tokens.push({ type: 'number', value: match[0] })
      index += match[0].length
      continue
    }

    if (/[a-zA-Z]/.test(char)) {
      const match = /^[a-zA-Z][a-zA-Z0-9]*/.exec(source.slice(index))!
      const name = match[0].toLowerCase()

      if (name in CONSTANTS) {
        tokens.push({ type: 'constant', value: name })
      } else if ((FUNCTION_NAMES as readonly string[]).includes(name)) {
        tokens.push({ type: 'function', value: name })
      } else if (name === 'mod') {
        tokens.push({ type: 'operator', value: 'mod' })
      } else {
        throw new CalculationError(`«${match[0]}» شناخته نشد.`)
      }

      index += match[0].length
      continue
    }

    if (char === '(' || char === ')') {
      tokens.push({ type: 'paren', value: char })
      index += 1
      continue
    }

    if (char in OPERATORS) {
      /*
       * تشخیص منفیِ یکانی از تفریق: «-» فقط وقتی یکانی است که اولین توکن
       * باشد، یا بعد از یک عملگرِ دوتایی یا پرانتز باز بیاید. بدون این،
       * «-۵+۳» و «۲*-۳» اشتباه محاسبه می‌شدند.
       */
      const previous = tokens[tokens.length - 1]
      const isUnary =
        char === '-' &&
        (!previous ||
          (previous.type === 'operator' && !OPERATORS[previous.value]?.postfix) ||
          (previous.type === 'paren' && previous.value === '('))

      tokens.push({ type: 'operator', value: isUnary ? 'neg' : char })
      index += 1
      continue
    }

    throw new CalculationError(`نویسه‌ی «${char}» مجاز نیست.`)
  }

  return tokens
}

/** توکنی که یک «مقدار» را تمام می‌کند: عدد، ثابت، پرانتز بسته، یا پسوندی (! و ٪). */
function endsValue(token: Token): boolean {
  return (
    token.type === 'number' ||
    token.type === 'constant' ||
    (token.type === 'paren' && token.value === ')') ||
    (token.type === 'operator' && OPERATORS[token.value]?.postfix === true)
  )
}

/** توکنی که یک «مقدار» را شروع می‌کند: عدد، ثابت، تابع، یا پرانتز باز. */
function startsValue(token: Token): boolean {
  return (
    token.type === 'number' ||
    token.type === 'constant' ||
    token.type === 'function' ||
    (token.type === 'paren' && token.value === '(')
  )
}

/**
 * ضرب ضمنی، همان‌طور که در ریاضی نوشته می‌شود.
 *
 * کاربر انتظار دارد «8sin(58)» یعنی ۸ ضربدر sin(۵۸)، «2(3+4)» یعنی
 * ۲ ضربدر پرانتز، و «(1+2)(3+4)» ضرب دو پرانتز. بدون این، همه‌ی این‌ها
 * «عبارت ناقص» می‌دادند. قاعده: بین توکنی که یک مقدار را تمام می‌کند و
 * توکنی که مقدار تازه‌ای شروع می‌کند، یک «*» گذاشته می‌شود.
 */
function insertImplicitMultiplication(tokens: Token[]): Token[] {
  const result: Token[] = []

  for (let i = 0; i < tokens.length; i++) {
    const previous = tokens[i - 1]
    if (previous && endsValue(previous) && startsValue(tokens[i])) {
      result.push({ type: 'operator', value: '*' })
    }
    result.push(tokens[i])
  }

  return result
}

/**
 * پرانتزهای بازِ بسته‌نشده را در انتها می‌بندد.
 *
 * وقتی کاربر «sin(58» می‌نویسد و «=» می‌زند، انتظار دارد ماشین حساب خودش
 * پرانتز را ببندد و نتیجه را بدهد، نه اینکه خطای «پرانتزها متوازن نیستند»
 * بگیرد. ولی پرانتز بسته‌ی اضافه (مثل «1+2)») همچنان خطاست، چون آنجا
 * حدس زدنِ نیت کاربر ممکن نیست.
 */
function balanceParens(tokens: Token[]): Token[] {
  let depth = 0
  for (const token of tokens) {
    if (token.type === 'paren') {
      depth += token.value === '(' ? 1 : -1
      if (depth < 0) throw new CalculationError('پرانتزها متوازن نیستند.')
    }
  }

  const balanced = [...tokens]
  for (let i = 0; i < depth; i++) {
    balanced.push({ type: 'paren', value: ')' })
  }

  return balanced
}

/** shunting-yard: از نماد میان‌وندی به نماد پسوندی (RPN). */
function toRpn(tokens: Token[]): Token[] {
  const output: Token[] = []
  const stack: Token[] = []

  for (const token of tokens) {
    if (token.type === 'number' || token.type === 'constant') {
      output.push(token)
      continue
    }

    if (token.type === 'function') {
      stack.push(token)
      continue
    }

    if (token.type === 'operator') {
      const spec = OPERATORS[token.value]

      while (stack.length > 0) {
        const top = stack[stack.length - 1]
        if (top.type === 'paren') break

        const topSpec = top.type === 'operator' ? OPERATORS[top.value] : null
        const topPrecedence = top.type === 'function' ? 7 : (topSpec?.precedence ?? 0)

        const shouldPop =
          topPrecedence > spec.precedence ||
          (topPrecedence === spec.precedence && spec.associativity === 'left')

        if (!shouldPop) break
        output.push(stack.pop()!)
      }

      stack.push(token)
      continue
    }

    if (token.value === '(') {
      stack.push(token)
      continue
    }

    // ')'
    let matched = false
    while (stack.length > 0) {
      const top = stack.pop()!
      if (top.type === 'paren' && top.value === '(') {
        matched = true
        break
      }
      output.push(top)
    }
    if (!matched) throw new CalculationError('پرانتزها متوازن نیستند.')

    // تابعِ چسبیده به پرانتز، مثل sin(
    if (stack[stack.length - 1]?.type === 'function') {
      output.push(stack.pop()!)
    }
  }

  while (stack.length > 0) {
    const top = stack.pop()!
    if (top.type === 'paren') throw new CalculationError('پرانتزها متوازن نیستند.')
    output.push(top)
  }

  return output
}

function factorial(n: number): number {
  if (!Number.isInteger(n) || n < 0) {
    throw new CalculationError('فاکتوریل فقط برای عدد صحیح نامنفی تعریف شده است.')
  }
  if (n > 170) throw new CalculationError('عدد برای فاکتوریل خیلی بزرگ است.')

  let result = 1
  for (let i = 2; i <= n; i += 1) result *= i

  return result
}

function applyFunction(name: FunctionName, value: number, mode: AngleMode): number {
  // ورودی توابع مثلثاتی در حالت درجه باید به رادیان تبدیل شود
  const toRadians = (v: number) => (mode === 'deg' ? (v * Math.PI) / 180 : v)
  const fromRadians = (v: number) => (mode === 'deg' ? (v * 180) / Math.PI : v)

  switch (name) {
    case 'sin': return Math.sin(toRadians(value))
    case 'cos': return Math.cos(toRadians(value))
    case 'tan': return Math.tan(toRadians(value))
    case 'asin':
      if (value < -1 || value > 1) throw new CalculationError('ورودی asin باید بین ۱- و ۱ باشد.')
      return fromRadians(Math.asin(value))
    case 'acos':
      if (value < -1 || value > 1) throw new CalculationError('ورودی acos باید بین ۱- و ۱ باشد.')
      return fromRadians(Math.acos(value))
    case 'atan': return fromRadians(Math.atan(value))
    case 'sinh': return Math.sinh(value)
    case 'cosh': return Math.cosh(value)
    case 'tanh': return Math.tanh(value)
    case 'ln':
      if (value <= 0) throw new CalculationError('لگاریتم فقط برای عدد مثبت تعریف شده است.')
      return Math.log(value)
    case 'log':
      if (value <= 0) throw new CalculationError('لگاریتم فقط برای عدد مثبت تعریف شده است.')
      return Math.log10(value)
    case 'log2':
      if (value <= 0) throw new CalculationError('لگاریتم فقط برای عدد مثبت تعریف شده است.')
      return Math.log2(value)
    case 'sqrt':
      if (value < 0) throw new CalculationError('جذر عدد منفی تعریف نشده است.')
      return Math.sqrt(value)
    case 'cbrt': return Math.cbrt(value)
    case 'abs': return Math.abs(value)
    case 'exp': return Math.exp(value)
    case 'round': return Math.round(value)
    case 'floor': return Math.floor(value)
    case 'ceil': return Math.ceil(value)
    case 'inv':
      if (value === 0) throw new CalculationError('تقسیم بر صفر ممکن نیست.')
      return 1 / value
    case 'sqr': return value * value
  }
}

function evaluateRpn(rpn: Token[], mode: AngleMode): number {
  const stack: number[] = []

  for (const token of rpn) {
    if (token.type === 'number') {
      stack.push(Number(token.value))
      continue
    }

    if (token.type === 'constant') {
      stack.push(CONSTANTS[token.value])
      continue
    }

    if (token.type === 'function') {
      const value = stack.pop()
      if (value === undefined) throw new CalculationError('عبارت ناقص است.')

      stack.push(applyFunction(token.value as FunctionName, value, mode))
      continue
    }

    const spec = OPERATORS[token.value]

    if (spec.arity === 1) {
      const value = stack.pop()
      if (value === undefined) throw new CalculationError('عبارت ناقص است.')

      if (token.value === 'neg') stack.push(-value)
      else if (token.value === '!') stack.push(factorial(value))
      else stack.push(value / 100)

      continue
    }

    const right = stack.pop()
    const left = stack.pop()
    if (right === undefined || left === undefined) throw new CalculationError('عبارت ناقص است.')

    switch (token.value) {
      case '+': stack.push(left + right); break
      case '-': stack.push(left - right); break
      case '*': stack.push(left * right); break
      case '/':
        if (right === 0) throw new CalculationError('تقسیم بر صفر ممکن نیست.')
        stack.push(left / right)
        break
      case 'mod':
        if (right === 0) throw new CalculationError('باقیمانده بر صفر تعریف نشده است.')
        stack.push(left % right)
        break
      case '^': stack.push(left ** right); break
    }
  }

  if (stack.length !== 1) throw new CalculationError('عبارت ناقص است.')

  const result = stack[0]
  if (!Number.isFinite(result)) throw new CalculationError('نتیجه عددِ معتبری نیست.')

  return result
}

/** ارزیابی یک عبارت ریاضی. در صورت خطا CalculationError پرتاب می‌کند. */
export function evaluate(expression: string, mode: AngleMode = 'deg'): number {
  const trimmed = expression.trim()
  if (!trimmed) throw new CalculationError('عبارتی وارد نشده است.')

  // ترتیب مهم است: اول ضرب ضمنی درج می‌شود، بعد پرانتزهای باز بسته می‌شوند،
  // و در آخر به RPN تبدیل و ارزیابی می‌شود.
  const tokens = balanceParens(insertImplicitMultiplication(tokenize(trimmed)))

  return evaluateRpn(toRpn(tokens), mode)
}

/**
 * نمایش نتیجه.
 *
 * خطای ممیز شناور (مثل 0.1+0.2) با گرد کردن به ۱۲ رقم معنادار پنهان می‌شود؛
 * اعداد خیلی بزرگ یا خیلی کوچک به نماد علمی می‌روند تا خانه‌ی نمایش نشکند.
 */
export function formatResult(value: number): string {
  if (Number.isInteger(value) && Math.abs(value) < 1e15) return String(value)

  const abs = Math.abs(value)
  if (abs !== 0 && (abs >= 1e15 || abs < 1e-9)) return value.toExponential(8)

  return String(Number(value.toPrecision(12)))
}
