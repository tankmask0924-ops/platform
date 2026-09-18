/** 金额：后端返回的是两位小数字符串，原样显示，空值显示 - */
export function money(value: string | null | undefined): string {
  return value === null || value === undefined || value === '' ? '-' : `¥${value}`
}

/** 返佣比例：后端存小数（1 = 100%），显示成百分比 */
export function ratePercent(rate: string | null | undefined): string {
  if (rate === null || rate === undefined || rate === '') {
    return '-'
  }
  return `${(Number(rate) * 100).toFixed(2)}%`
}

/** 百分比（最多两位小数）转成后端要的比例字符串（最多四位小数） */
export function percentToRate(percent: number): string {
  return (Math.round(percent * 100) / 10000).toFixed(4)
}

/** 多行文本拆成非空行 */
export function splitLines(text: string): string[] {
  return text
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '')
}

export function isNegative(value: string | null | undefined): boolean {
  return typeof value === 'string' && value.trim().startsWith('-')
}

/** 只有 http(s) 链接才渲染成可点击的链接，挡掉 javascript: 之类的地址 */
export function isHttpUrl(value: string | null | undefined): value is string {
  return typeof value === 'string' && /^https?:\/\/\S+$/i.test(value.trim())
}
