import { formatDate } from '../../utils/format.js'
import * as db from './db.js'
import { replaceHistory, useChatStore } from './store.js'
import type { Session, SessionSummary } from './types.js'

export { BackupError } from './history/errors.js'

// 导入、导出与全部删除串行处理，避免读取归档时数据被清空或两个替换事务交错。
let backupQueue: Promise<unknown> = Promise.resolve()

const enqueueBackup = <T>(run: () => Promise<T>): Promise<T> => {
  const task = backupQueue.then(run)
  backupQueue = task.catch(() => {})
  return task
}

const backupFileName = (date: Date) => `deepseek_data-${formatDate(date)}.zip`

/** 导出为历史会话导出格式的 ZIP，可再次导入。 */
export const exportHistory = (
  onProgress?: (percent: number) => void
): Promise<{ name: string; blob: Blob }> =>
  enqueueBackup(async () => {
    onProgress?.(1)
    // 先向服务端取回全部会话摘要，再逐个读取完整聊天记录，最后在浏览器本地打包 ZIP。
    const summaries: SessionSummary[] = []
    let cursor: string | null = null
    do {
      const page: Awaited<ReturnType<typeof db.loadSessionSummaries>> =
        await db.loadSessionSummaries(cursor)
      summaries.push(...page.sessions)
      cursor = page.nextCursor
    } while (cursor)
    const { sessionCache } = useChatStore.getState()
    const total = summaries.length || 1
    let loaded = 0
    async function* readSessions(): AsyncGenerator<Session> {
      for (const summary of summaries) {
        const session = Object.hasOwn(sessionCache, summary.id)
          ? sessionCache[summary.id]
          : await db.loadSession(summary.id)
        if (!session) {
          throw new Error(`History session disappeared during export: ${summary.id}`)
        }
        loaded += 1
        onProgress?.(5 + Math.round((loaded / total) * 85))
        // 详情读取期间可能重命名，归档标题与开始时的列表保持一致。
        yield { ...session, ...summary }
      }
    }
    const { exportArchive } = await import('./history/exportArchive.js')
    onProgress?.(92)
    const now = new Date()
    const blob = await exportArchive(readSessions())
    onProgress?.(100)
    return { name: backupFileName(now), blob }
  })

/**
 * 确认覆盖后调用：先检查 ZIP 格式与版本，校验失败时保留正在生成的回答；
 * 通过后从 ZIP 流式写入暂存，成功才替换原有会话与图片原件。返回导入的会话数。
 */
export const importHistory = (
  file: File,
  onProgress?: (percent: number) => void
): Promise<number> =>
  enqueueBackup(async () => {
    const { checkArchiveFormat, importArchive } = await import('./history/importArchive.js')
    onProgress?.(2)
    await checkArchiveFormat(file)
    onProgress?.(6)
    const count = await replaceHistory(() =>
      importArchive(file, (ratio) => onProgress?.(6 + Math.round(ratio * 92)))
    )
    onProgress?.(100)
    return count
  })

/** 确认删除后调用：以空历史原子替换全部本地会话与附件，保留 API Key 和其他设置。 */
export const clearHistory = (): Promise<void> =>
  enqueueBackup(async () => {
    await replaceHistory(async () => {})
  })
