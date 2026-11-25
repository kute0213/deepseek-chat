import { requestJson } from '../http.js'

/** 我的分享列表项 */
export type ShareSummary = {
  token: string
  sessionId: string
  title: string
  views: number
  revoked: boolean
  createdAt: number
  expiresAt: number | null
  url: string
}

/** 公开分享页里的只读消息 */
export type ShareMessage = {
  id: string
  role: 'user' | 'assistant'
  createdAt: number
  content?: string
  files?: { name: string; size: number; isImage: boolean }[]
  text?: string
}

export type ShareView = {
  token: string
  title: string
  owner: string
  createdAt: number
  messages: ShareMessage[]
}

export const createShare = (sessionId: string, messageIds: string[]): Promise<ShareSummary> =>
  requestJson<ShareSummary>('/api/shares', 'POST', { sessionId, messageIds })

export const loadMyShares = async (): Promise<ShareSummary[]> => {
  const data = await requestJson<{ shares?: ShareSummary[] }>('/api/shares', 'GET')
  return data.shares ?? []
}

export const revokeShare = async (token: string): Promise<void> => {
  await requestJson<{ ok: boolean }>(`/api/shares/${encodeURIComponent(token)}`, 'DELETE')
}

export const loadShare = (token: string): Promise<ShareView> =>
  requestJson<ShareView>(`/api/share/${encodeURIComponent(token)}`, 'GET')

/** 分享页「继续对话」：复制到当前账号，返回新会话 ID */
export const forkShare = (token: string): Promise<{ sessionId: string }> =>
  requestJson<{ sessionId: string }>(`/api/share/${encodeURIComponent(token)}/fork`, 'POST')
