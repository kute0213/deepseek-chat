import { requestJson } from '../http.js'

/** 右侧导航使用的精简条目：只含用户提问的 ID、全局序号与前 50 个字预览 */
export type OutlineItem = {
  id: string
  index: number
  preview: string
}

export type OutlinePage = {
  total: number
  offset: number
  items: OutlineItem[]
}

/** 按窗口读取会话大纲，每次最多 20 条；不传 offset 时返回最早的窗口 */
export const loadOutline = (
  sessionId: string,
  options: { offset?: number; limit?: number; around?: string } = {}
): Promise<OutlinePage> => {
  const params = new URLSearchParams({ limit: String(options.limit ?? 20) })
  if (options.offset !== undefined) {
    params.set('offset', String(options.offset))
  }
  if (options.around) {
    params.set('around', options.around)
  }
  return requestJson<OutlinePage>(
    `/api/sessions/${encodeURIComponent(sessionId)}/outline?${params.toString()}`,
    'GET'
  )
}
