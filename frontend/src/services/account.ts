import { requestJson } from './http.js'

/** 单个会话的 Token 用量 */
export type UsageItem = {
  id: string
  title: string
  messages: number
  inputTokens: number
  outputTokens: number
  updatedAt: number
}

export type UsageSummary = {
  inputTokens: number
  outputTokens: number
  totalTokens: number
  sessions: number
  messages: number
  items: UsageItem[]
}

export const loadUsage = (): Promise<UsageSummary> =>
  requestJson<UsageSummary>('/api/account/usage', 'GET')

/** 注销账号：校验当前密码后删除账号与全部数据 */
export const deleteAccount = async (password: string): Promise<void> => {
  await requestJson<{ ok: boolean }>('/api/account/delete', 'POST', { password })
}
