import { requestJson } from './http.js'

/** docs 目录下的一篇 Markdown 文档 */
export type DocItem = {
  name: string
  title: string
  content: string
}

export const loadDocs = async (): Promise<DocItem[]> => {
  const data = await requestJson<{ docs?: DocItem[] }>('/api/docs', 'GET')
  return data.docs ?? []
}
