import { ApiError, request, requestJson } from '../http.js'
import type { Session, SessionSummary } from './types.js'

/** 附件原件：会话保存后仍可在任意设备重新读取或补传 */
export type StoredFile = {
  id: string
  blob: Blob
  name?: string
  mimeType?: string
  isImage?: boolean
}

/** 同一会话的保存请求串行执行，避免较早的快照晚到后覆盖较新的数据 */
const saveQueues = new Map<string, Promise<void>>()

const enqueueSave = (id: string, task: () => Promise<void>): Promise<void> => {
  const previous = saveQueues.get(id) ?? Promise.resolve()
  const next = previous.then(task, task)
  saveQueues.set(id, next)
  void next.finally(() => {
    if (saveQueues.get(id) === next) {
      saveQueues.delete(id)
    }
  })
  return next
}

/** 原件的上传请求带上元信息，下载与导出时保留原始文件名和类型 */
const fileHeaders = (file: StoredFile): Record<string, string> => ({
  'Content-Type': file.mimeType || 'application/octet-stream',
  'X-File-Name': encodeURIComponent(file.name ?? file.id),
  'X-File-Mime': file.mimeType ?? '',
  'X-File-Image': file.isImage ? '1' : '0',
})

const putFile = async (file: StoredFile): Promise<void> => {
  await request(`/api/files/${encodeURIComponent(file.id)}`, {
    method: 'PUT',
    headers: fileHeaders(file),
    body: file.blob,
  })
}

/** 会话摘要分页：nextCursor 为空表示已到末页 */
export type SessionPage = {
  sessions: SessionSummary[]
  nextCursor: string | null
}

export const loadSessionSummaries = async (
  cursor?: string | null,
  limit = 30
): Promise<SessionPage> => {
  const query = new URLSearchParams({ limit: String(limit) })
  if (cursor) {
    query.set('cursor', cursor)
  }
  const page = await requestJson<SessionPage>(`/api/sessions?${query.toString()}`, 'GET')
  return { sessions: page.sessions ?? [], nextCursor: page.nextCursor ?? null }
}

/** 打开会话时分页返回的消息窗口：hasMore 表示还有更早的消息，nextCursor 用于继续加载。 */
export type SessionMessagesPage = Session & { hasMore?: boolean; nextCursor?: string | null }

export type LoadSessionOptions = { limit?: number; before?: string | null }

export const loadSession = async (
  id: string,
  options?: LoadSessionOptions
): Promise<SessionMessagesPage | undefined> => {
  const params = new URLSearchParams()
  if (options?.limit) {
    params.set('limit', String(options.limit))
  }
  if (options?.before) {
    params.set('before', options.before)
  }
  const query = params.toString()
  try {
    return await requestJson<SessionMessagesPage>(
      `/api/sessions/${encodeURIComponent(id)}${query ? `?${query}` : ''}`,
      'GET'
    )
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) {
      return undefined
    }
    throw error
  }
}

/**
 * 先保存会话（登记附件引用），再上传原件；上传失败时保留会话，重试会再次提交。
 * partial 表示本地只有分页窗口内的消息，服务端会与库内完整记录合并。
 */
export const saveSession = (
  session: Session,
  files: StoredFile[] = [],
  partial = false
): Promise<void> =>
  enqueueSave(session.id, async () => {
    await requestJson<{ ok: boolean }>(
      `/api/sessions/${encodeURIComponent(session.id)}`,
      'PUT',
      partial ? { ...session, partialMessages: true } : session
    )
    for (const file of files) {
      await putFile(file)
    }
  })

/** 未打开的会话也可重命名，服务端只更新标题与更新时间 */
export const renameSession = async (id: string, title: string): Promise<void> => {
  await requestJson<{ ok: boolean }>(`/api/sessions/${encodeURIComponent(id)}`, 'PATCH', { title })
}

/** 删除会话；服务端同时清理不再被任何会话引用的附件 */
export const deleteSession = async (sessionId: string): Promise<void> => {
  await requestJson<{ ok: boolean }>(`/api/sessions/${encodeURIComponent(sessionId)}`, 'DELETE')
}

export const loadFile = async (id: string): Promise<Blob | undefined> => {
  try {
    const response = await request(`/api/files/${encodeURIComponent(id)}`, { method: 'GET' })
    return await response.blob()
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) {
      return undefined
    }
    throw error
  }
}

/** 开始导入前清除上次中断（如关闭页面）遗留的暂存数据 */
export const clearStaging = async (): Promise<void> => {
  await requestJson<{ ok: boolean }>('/api/history/staging', 'DELETE')
}

export const addStagingSession = async (session: Session): Promise<void> => {
  await requestJson<{ ok: boolean }>('/api/history/staging/sessions', 'POST', session)
}

export const saveStagingFiles = async (files: StoredFile[]): Promise<void> => {
  for (const file of files) {
    await request(`/api/history/staging/files/${encodeURIComponent(file.id)}`, {
      method: 'POST',
      headers: fileHeaders(file),
      body: file.blob,
    })
  }
}

/** 图片位于会话 JSON 之后；全部读完后回填具体 MIME，保留归档给出的 isImage 标识 */
export const updateStagingFileTypes = async (types: ReadonlyMap<string, string>): Promise<void> => {
  if (types.size === 0) {
    return
  }
  await requestJson<{ ok: boolean }>(
    '/api/history/staging/file-types',
    'PUT',
    Object.fromEntries(types)
  )
}

/**
 * 导入完成：服务端在单个事务中清空并替换全部会话，逐个重排为雪花 ID 后返回首页摘要与总数。
 */
export const commitStaging = async (): Promise<SessionPage & { total: number }> => {
  const page = await requestJson<SessionPage & { total: number }>(
    '/api/history/staging/commit',
    'POST'
  )
  return {
    sessions: page.sessions ?? [],
    nextCursor: page.nextCursor ?? null,
    total: page.total ?? page.sessions?.length ?? 0,
  }
}

/** 保存失败不打断对话，只在控制台留痕 */
export const reportStorageError = (action: string) => (error: unknown) => {
  // eslint-disable-next-line no-console
  console.error(`[storage] ${action} failed`, error)
}
