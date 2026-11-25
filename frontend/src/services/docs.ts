import { requestJson } from './http.js'

/** docs 目录下的一篇 Markdown 文档 */
export type DocItem = {
  name: string
  /** 同一篇文档的中英文共用 base，按语言取对应版本 */
  base: string
  lang: 'zh-CN' | 'en-US'
  title: string
  content: string
}

export const loadDocs = async (): Promise<DocItem[]> => {
  const data = await requestJson<{ docs?: DocItem[] }>('/api/docs', 'GET')
  return data.docs ?? []
}
