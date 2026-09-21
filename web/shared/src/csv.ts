/**
 * 导出成 CSV 文件（requirements.md 7.2 商户后台「资金流水 / 返佣 / 订单」的"支持筛选导出"）。
 *
 * CSV 在前端拼，不在后端生成：这样枚举值的中文名只在 labels.ts 存一份，导出的列
 * 和页面上看到的列天然一致，后端不用再抄一份中文标签跟着前端漂。后端只提供不分页
 * 的导出接口（`/export`，带行数上限）。
 */

/** 一列：表头 + 从一行数据里取值 */
export interface CsvColumn<T> {
  header: string
  value: (row: T) => string | number | null | undefined
}

/**
 * Excel 打开 CSV 时，看到 `=`/`+`/`-`/`@` 开头的单元格会当成公式执行。导出的内容里有
 * 商户自己填的备注、订单号这类字段，前面补一个单引号让 Excel 当纯文本处理。
 */
function escapeCell(value: string | number | null | undefined): string {
  if (value === null || value === undefined) {
    return ''
  }
  const text = String(value)
  const safe = /^[=+\-@]/.test(text) ? `'${text}` : text

  return `"${safe.replace(/"/g, '""')}"`
}

/**
 * 把行数据转成 CSV 并触发浏览器下载。
 *
 * 开头的 BOM（﻿）不能去掉：没有它，Excel（中文 Windows 下按 GBK 猜编码）打开
 * 会把所有中文显示成乱码，而 UTF-8 本身又是这里唯一合理的编码。
 */
export function downloadCsv<T>(filename: string, columns: CsvColumn<T>[], rows: T[]): void {
  const lines = [
    columns.map((column) => escapeCell(column.header)).join(','),
    ...rows.map((row) => columns.map((column) => escapeCell(column.value(row))).join(',')),
  ]
  const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' })

  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(url)
}

/** 导出文件名统一带上当前时间，多次导出不会互相覆盖 */
export function csvFilename(prefix: string): string {
  const now = new Date()
  const pad = (n: number) => String(n).padStart(2, '0')
  const stamp = `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`

  return `${prefix}-${stamp}.csv`
}
