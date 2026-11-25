import { create } from 'zustand'
import { nextSnowflakeId } from '../ids.js'
import { streamMessage } from '../api/client.js'
import { ApiError } from '../http.js'
import type { ChatModel, CreateMessageRequest } from '../api/types.js'
import { extractFileIds, getFileErrorCode, isRetryableApiError } from '../api/errors.js'
import { focusMessage } from './focus.js'
import {
  cancelUpload,
  checkImageFileIds,
  describeUploadError,
  uploadAttachment,
  useUploads,
} from '../attachments.js'
import { useSettings } from '../settings.js'
import { appendBranch, getVisibleMessages, normalizeSession, selectVersion, truncateFrom } from './branches.js'
import * as db from './db.js'
import {
  clearAllDrafts,
  clearDraft,
  clearSentDraft,
  getDraft,
  prepareDraftMessage,
} from './drafts.js'
import { createLatestReminder } from './latestReminder.js'
import {
  applyEvent,
  canContinueReply,
  getContextRounds,
  settleReply,
  toRequestMessages,
} from './reply.js'
import {
  toSessionSummary,
  type AssistantMessage,
  type FileAttachment,
  type Message,
  type Session,
  type SessionSummary,
  type UserMessage,
} from './types.js'

/** deepseek-flash 同时支持深度思考与图片输入 */
const MODEL: ChatModel = 'deepseek-flash'
/** 所有生成请求统一携带的助手身份说明 */
const SYSTEM_PROMPT = '该助手为DeepSeek，由深度求索公司创造。'
/** 保留最近打开的详情；当前会话、生成中及尚未成功保存的会话不淘汰。 */
const SESSION_CACHE_LIMIT = 5
/** 打开会话时先加载的最近消息数，向上滚动再按同样数量加载更早消息。 */
const MESSAGE_PAGE_SIZE = 20
/** 安全审核固定开启，所有请求都走扩展模式 */
const SAFETY_CHECK = { level: 'high' } as const
/** 服务端判定 file_id 失效后，重新补传并重发的最多次数（不含首次请求） */
const FILE_RETRY_LIMIT = 2
/** 退避基数，第 n 次重试等待 2^n 倍；连续失败达到上限就放弃，把原始报错展示出来 */
const FILE_RETRY_BASE_DELAY = 1000

/** 阅读链接始终开启，智能搜索开关控制是否另行声明搜索工具（含边想边搜）。 */
const serverTools = (search: boolean): CreateMessageRequest['tools'] => {
  const tools: CreateMessageRequest['tools'] = [
    { type: 'deepseek_read_url', name: 'deepseek_read_url' },
  ]
  if (search) {
    tools.push({ type: 'deepseek_web_search', name: 'deepseek_web_search' })
  }
  return tools
}

/** 允许自动补标题的请求；手动重命名后取消本轮标题写入，包含主动清空标题的情况。 */
const titleRequests = new Set<AbortController>()

type ChatState = {
  ready: boolean
  initError: boolean
  /** 历史导入或全部删除期间锁定交互，提交成功前仍展示原有记录。 */
  importingHistory: boolean
  /** 仅列表元数据，按 updatedAt 降序；流式消息更新不会改变此数组。 */
  sessions: SessionSummary[]
  /** 下一页摘要的游标；null 表示已全部加载 */
  nextCursor: string | null
  /** 正在加载下一页摘要 */
  loadingMore: boolean
  sessionCache: Record<string, Session>
  /** 每个会话的消息分页状态；hasMore 表示还能向上加载更早的消息 */
  messagePages: Record<string, { hasMore: boolean; cursor: string | null; loading: boolean }>
  sessionLoadErrors: Record<string, boolean>
  sessionSaveErrors: Record<string, boolean>
  /** null 表示新对话（欢迎页） */
  activeId: string | null
  /** 正在生成回复的会话 */
  streaming: Record<string, AbortController>
}

export const useChatStore = create<ChatState>(() => ({
  ready: false,
  initError: false,
  importingHistory: false,
  sessions: [],
  nextCursor: null,
  loadingMore: false,
  sessionCache: {},
  messagePages: {},
  sessionLoadErrors: {},
  sessionSaveErrors: {},
  activeId: null,
  streaming: {},
}))

const { getState: get, setState: set } = useChatStore

const sortSessions = (sessions: SessionSummary[]) =>
  [...sessions].sort((a, b) => {
    const pinnedA = a.pinned === true ? 1 : 0
    const pinnedB = b.pinned === true ? 1 : 0
    return pinnedB - pinnedA || b.updatedAt - a.updatedAt
  })

const getSession = (id: string) => {
  const cache = get().sessionCache
  return Object.hasOwn(cache, id) ? cache[id] : undefined
}

const getStreamController = (id: string) => {
  const streams = get().streaming
  return Object.hasOwn(streams, id) ? streams[id] : undefined
}

const sameSummary = (a: SessionSummary, b: SessionSummary) =>
  a.title === b.title &&
  a.createdAt === b.createdAt &&
  a.updatedAt === b.updatedAt &&
  (a.pinned === true) === (b.pinned === true)

const sessionAccess = new Map<string, number>()
const unsavedSessions = new Set<string>()
/** 保存失败时保留原件，重试成功后才释放。 */
const pendingFiles = new Map<string, db.StoredFile[]>()
const deletingSessions = new Set<string>()
const deletionTasks = new Set<Promise<boolean>>()
const streamFlushes = new Map<string, () => void>()
let accessSequence = 0
let historyEpoch = 0
let initTask: Promise<void> | undefined
let loadMoreTask: Promise<void> | undefined
const sessionLoads = new Map<string, { promise: Promise<void> }>()
const olderLoads = new Map<string, Promise<void>>()

const touchSession = (id: string) => sessionAccess.set(id, ++accessSequence)

const trimSessionCache = () => {
  const state = get()
  const ids = Object.keys(state.sessionCache)
  if (ids.length <= SESSION_CACHE_LIMIT) {
    return
  }
  const removable = ids
    .filter(
      (id) =>
        id !== state.activeId &&
        !Object.hasOwn(state.streaming, id) &&
        !unsavedSessions.has(id) &&
        !deletingSessions.has(id)
    )
    .sort((a, b) => (sessionAccess.get(a) ?? 0) - (sessionAccess.get(b) ?? 0))
  const evicted = new Set(removable.slice(0, ids.length - SESSION_CACHE_LIMIT))
  for (const id of evicted) {
    sessionAccess.delete(id)
  }
  if (evicted.size > 0) {
    const messagePages = { ...state.messagePages }
    for (const id of evicted) {
      delete messagePages[id]
    }
    set({
      sessionCache: Object.fromEntries(
        Object.entries(state.sessionCache).filter(([id]) => !evicted.has(id))
      ),
      messagePages,
    })
  }
}

const updateSession = (id: string, update: (session: Session) => Session) =>
  set((state) => {
    const previous = Object.hasOwn(state.sessionCache, id) ? state.sessionCache[id] : undefined
    if (!previous) {
      return state
    }
    const session = update(previous)
    if (session === previous) {
      return state
    }
    return {
      sessionCache: { ...state.sessionCache, [id]: session },
      sessions: sameSummary(previous, session)
        ? state.sessions
        : sortSessions(
            state.sessions.map((summary) =>
              summary.id === id
                ? { ...toSessionSummary(session), pinned: summary.pinned === true }
                : summary
            )
          ),
    }
  })

const updateReply = (sessionId: string, reply: AssistantMessage) =>
  updateSession(sessionId, (session) => ({
    ...session,
    messages: session.messages.map((message) =>
      message.id === reply.id
        ? { ...reply, parentId: message.parentId, selectedChildId: message.selectedChildId }
        : message
    ),
  }))

const persist = (sessionId: string) => {
  const session = getSession(sessionId)
  if (session) {
    unsavedSessions.add(sessionId)
    if (deletingSessions.has(sessionId)) {
      return
    }
    const epoch = historyEpoch
    const files = pendingFiles.get(sessionId)
    // 只加载了最近一页消息时交给服务端合并，避免覆盖更早的历史
    const partial = get().messagePages[sessionId]?.hasMore === true
    void db
      .saveSession(session, files, partial)
      .then(() => {
        if (epoch !== historyEpoch || !getSession(sessionId)) {
          return
        }
        if (pendingFiles.get(sessionId) === files) {
          pendingFiles.delete(sessionId)
        }
        if (getSession(sessionId) === session) {
          unsavedSessions.delete(sessionId)
          set((state) => ({
            sessionSaveErrors: Object.fromEntries(
              Object.entries(state.sessionSaveErrors).filter(([id]) => id !== sessionId)
            ),
          }))
          trimSessionCache()
        }
      })
      .catch((error: unknown) => {
        db.reportStorageError('save session')(error)
        if (epoch === historyEpoch && getSession(sessionId)) {
          set((state) => ({
            sessionSaveErrors: { ...state.sessionSaveErrors, [sessionId]: true },
          }))
        }
      })
  }
}

export const retrySaveSessions = () => {
  if (!get().importingHistory) {
    unsavedSessions.forEach(persist)
  }
}

/** 离开前先合并尚未绘制的增量；隐藏期间浏览器可能暂停动画帧。 */
export const bindChatPersistence = () => {
  const save = () => {
    if (get().importingHistory) {
      return
    }
    for (const [id, flush] of streamFlushes) {
      flush()
      persist(id)
    }
    for (const id of unsavedSessions) {
      if (!streamFlushes.has(id)) {
        persist(id)
      }
    }
  }
  const onVisibilityChange = () => {
    if (document.visibilityState === 'hidden') {
      save()
    }
  }
  document.addEventListener('visibilitychange', onVisibilityChange)
  window.addEventListener('pagehide', save)
  return () => {
    document.removeEventListener('visibilitychange', onVisibilityChange)
    window.removeEventListener('pagehide', save)
  }
}

/** 按当前开关创建回复：深度思考对应 thinking，智能搜索控制 deepseek_web_search。 */
const createReply = (): AssistantMessage => {
  const { thinking, search } = useSettings.getState()
  return {
    id: nextSnowflakeId(),
    role: 'assistant',
    createdAt: Date.now(),
    model: MODEL,
    thinking,
    search,
    prefix: true,
    status: 'pending',
    blocks: [],
  }
}

/** 仍在生成中的回复（页面关闭或导出时未结束）按「已停止」处理 */
const settleInterrupted = (session: Session): Session => {
  if (
    !session.messages.some(
      (m) => m.role === 'assistant' && (m.status === 'pending' || m.status === 'streaming')
    )
  ) {
    return session
  }
  return {
    ...session,
    messages: session.messages.map((m) =>
      m.role === 'assistant' && (m.status === 'pending' || m.status === 'streaming')
        ? settleReply(m, 'stopped', session.updatedAt)
        : m
    ),
  }
}

/** 登录后加载服务端会话；未登录时直接进入空聊天，所有账号操作再引导登录 */
export const initChat = (loggedIn: boolean): Promise<void> => {
  if (get().ready) {
    return Promise.resolve()
  }
  if (!loggedIn) {
    set({ ready: true, initError: false, sessions: [], nextCursor: null })
    return Promise.resolve()
  }
  if (initTask) {
    return initTask
  }
  const epoch = historyEpoch
  set({ initError: false })
  initTask = (async () => {
    try {
      const page = await db.loadSessionSummaries()
      if (epoch === historyEpoch) {
        set({
          ready: true,
          sessions: sortSessions(page.sessions),
          nextCursor: page.nextCursor,
        })
      }
    } catch (error) {
      if (epoch === historyEpoch) {
        set({ initError: true })
        db.reportStorageError('read local sessions')(error)
      }
    } finally {
      if (epoch === historyEpoch) {
        initTask = undefined
      }
    }
  })()
  return initTask
}

/**
 * 加载下一页会话摘要：只在已初始化且仍有游标时触发，并发调用合并为同一请求。
 * 滚动到底部时由侧边栏自动调用。
 */
export const loadMoreSessions = (): Promise<void> => {
  const state = get()
  if (!state.ready || state.loadingMore || state.nextCursor === null) {
    return Promise.resolve()
  }
  if (loadMoreTask) {
    return loadMoreTask
  }
  const epoch = historyEpoch
  const cursor = state.nextCursor
  set({ loadingMore: true })
  loadMoreTask = (async () => {
    try {
      const page = await db.loadSessionSummaries(cursor)
      if (epoch === historyEpoch) {
        set((current) => {
          const seen = new Set(current.sessions.map((session) => session.id))
          const merged = [...current.sessions]
          for (const summary of page.sessions) {
            if (!seen.has(summary.id)) {
              seen.add(summary.id)
              merged.push(summary)
            }
          }
          return { sessions: sortSessions(merged), nextCursor: page.nextCursor }
        })
      }
    } catch (error) {
      db.reportStorageError('read more sessions')(error)
    } finally {
      loadMoreTask = undefined
      if (epoch === historyEpoch) {
        set({ loadingMore: false })
      }
    }
  })()
  return loadMoreTask
}

/** 退出登录或会话失效时清空内存状态，避免下一个账号看到上一个账号的数据 */
export const resetChat = (keepDrafts = false): void => {
  historyEpoch++
  for (const controller of Object.values(get().streaming)) {
    controller.abort()
  }
  initTask = undefined
  loadMoreTask = undefined
  sessionLoads.clear()
  olderLoads.clear()
  sessionAccess.clear()
  unsavedSessions.clear()
  pendingFiles.clear()
  deletingSessions.clear()
  if (!keepDrafts) {
    clearAllDrafts()
  }
  set({
    ready: false,
    initError: false,
    importingHistory: false,
    sessions: [],
    nextCursor: null,
    loadingMore: false,
    sessionCache: {},
    messagePages: {},
    sessionLoadErrors: {},
    sessionSaveErrors: {},
    activeId: null,
    streaming: {},
  })
}

/** 同一会话合并并发读取；切换只改变 activeId，旧请求不会把界面切回去。 */
const ensureSessionLoaded = (id: string): Promise<void> => {
  if (getSession(id)) {
    return Promise.resolve()
  }
  const pending = sessionLoads.get(id)
  if (pending) {
    return pending.promise
  }
  const epoch = historyEpoch
  set((state) => ({ sessionLoadErrors: { ...state.sessionLoadErrors, [id]: false } }))
  const load = { promise: Promise.resolve() }
  sessionLoads.set(id, load)
  load.promise = (async () => {
    try {
      const stored = await db.loadSession(id, { limit: MESSAGE_PAGE_SIZE })
      if (epoch !== historyEpoch || sessionLoads.get(id) !== load) {
        return
      }
      const summary = get().sessions.find((session) => session.id === id)
      if (!summary) {
        return
      }
      if (!stored) {
        throw new Error(`Session ${id} is missing from local storage`)
      }
      const { hasMore, nextCursor, ...payload } = stored
      const settled = settleInterrupted(normalizeSession(payload))
      // 读取期间可能已在列表重命名，保留最新元数据，不能让旧读取覆盖它。
      const session = sameSummary(settled, summary) ? settled : { ...settled, ...summary }
      touchSession(id)
      set((state) => ({
        sessionCache: { ...state.sessionCache, [id]: session },
        messagePages: {
          ...state.messagePages,
          [id]: { hasMore: hasMore === true, cursor: nextCursor ?? null, loading: false },
        },
      }))
      if (session !== payload) {
        persist(id)
      }
      trimSessionCache()
    } catch (error) {
      if (epoch === historyEpoch && sessionLoads.get(id) === load) {
        set((state) => ({ sessionLoadErrors: { ...state.sessionLoadErrors, [id]: true } }))
        db.reportStorageError('read session')(error)
      }
    } finally {
      if (sessionLoads.get(id) === load) {
        sessionLoads.delete(id)
      }
    }
  })()
  return load.promise
}

/**
 * 向上滚动到顶部时加载更早的一页消息（20 条）；同一会话的并发请求合并为一次。
 * 新消息拼在已有消息之前，根节点改用更早一页的根，保证分支链完整。
 */
export const loadOlderMessages = (sessionId: string): Promise<void> => {
  const state = get()
  const page = state.messagePages[sessionId]
  if (!getSession(sessionId) || !page || !page.hasMore || page.loading || page.cursor === null) {
    return Promise.resolve()
  }
  const pending = olderLoads.get(sessionId)
  if (pending) {
    return pending
  }
  const epoch = historyEpoch
  const cursor = page.cursor
  set((current) => ({
    messagePages: { ...current.messagePages, [sessionId]: { ...page, loading: true } },
  }))
  const holder: { task?: Promise<void> } = {}
  const task = (async () => {
    try {
      const older = await db.loadSession(sessionId, { limit: MESSAGE_PAGE_SIZE, before: cursor })
      if (epoch !== historyEpoch) {
        return
      }
      if (!older) {
        set((current) => {
          const currentPage = current.messagePages[sessionId]
          return currentPage
            ? {
                messagePages: {
                  ...current.messagePages,
                  [sessionId]: { ...currentPage, loading: false },
                },
              }
            : current
        })
        return
      }
      set((current) => {
        const existing = current.sessionCache[sessionId]
        if (!existing) {
          return current
        }
        const seen = new Set(existing.messages.map((message) => message.id))
        const merged = [
          ...older.messages.filter((message) => !seen.has(message.id)),
          ...existing.messages,
        ]
        return {
          sessionCache: {
            ...current.sessionCache,
            [sessionId]: {
              ...existing,
              rootMessageId: older.rootMessageId ?? existing.rootMessageId,
              messages: merged,
            },
          },
          messagePages: {
            ...current.messagePages,
            [sessionId]: {
              hasMore: older.hasMore === true,
              cursor: older.nextCursor ?? null,
              loading: false,
            },
          },
        }
      })
    } catch (error) {
      db.reportStorageError('read older messages')(error)
      if (epoch === historyEpoch) {
        set((current) => {
          const currentPage = current.messagePages[sessionId]
          return currentPage
            ? {
                messagePages: {
                  ...current.messagePages,
                  [sessionId]: { ...currentPage, loading: false },
                },
              }
            : current
        })
      }
    } finally {
      if (olderLoads.get(sessionId) === holder.task) {
        olderLoads.delete(sessionId)
      }
    }
  })()
  holder.task = task
  olderLoads.set(sessionId, task)
  return task
}

/**
 * 发送、编辑或重新生成前取回完整历史：上游接口需要完整上下文，
 * 以服务端顺序为基准合并本地未落盘的新消息与本地修改。
 */
const ensureFullHistory = async (sessionId: string): Promise<void> => {
  const pending = olderLoads.get(sessionId)
  if (pending) {
    await pending
  }
  if (!get().messagePages[sessionId]?.hasMore) {
    return
  }
  const local = getSession(sessionId)
  if (!local) {
    return
  }
  const full = await db.loadSession(sessionId)
  if (!full) {
    return
  }
  set((current) => {
    const existing = current.sessionCache[sessionId] ?? local
    const localById = new Map(existing.messages.map((message) => [message.id, message]))
    const serverIds = new Set(full.messages.map((message) => message.id))
    const merged = full.messages.map((message) => localById.get(message.id) ?? message)
    for (const message of existing.messages) {
      if (!serverIds.has(message.id)) {
        merged.push(message)
      }
    }
    return {
      sessionCache: {
        ...current.sessionCache,
        [sessionId]: {
          ...existing,
          rootMessageId: full.rootMessageId ?? existing.rootMessageId,
          messages: merged,
        },
      },
      messagePages: {
        ...current.messagePages,
        [sessionId]: { hasMore: false, cursor: null, loading: false },
      },
    }
  })
}

/** 完整历史就绪后，用会话的可见路径重建请求前缀，剔除当前问题及其后的待生成回复。 */
const refreshRequestHistory = <T extends { history: Message[]; question: Message }>(
  sessionId: string,
  request: T
): T => {
  const session = getSession(sessionId)
  if (!session) {
    return request
  }
  const path = getVisibleMessages(session)
  const index = path.findIndex((message) => message.id === request.question.id)
  return index < 0 ? request : { ...request, history: path.slice(0, index) }
}

/** 等所有回复的收尾（落盘）完成 */
const waitForIdle = (sessionId?: string) =>
  new Promise<void>((resolve) => {
    const isIdle = (state: ChatState) =>
      sessionId === undefined
        ? Object.keys(state.streaming).length === 0
        : !Object.hasOwn(state.streaming, sessionId)
    if (isIdle(get())) {
      resolve()
      return
    }
    const unsubscribe = useChatStore.subscribe((state) => {
      if (isIdle(state)) {
        unsubscribe()
        resolve()
      }
    })
  })

/**
 * 替换历史对话：先写入暂存，成功后原子替换旧记录；写入为空即删除全部会话与附件。
 * 期间停止生成并禁止修改会话；失败时清除暂存，保留原有记录。
 */
export const replaceHistory = async (write: () => Promise<void>): Promise<number> => {
  if (get().importingHistory) {
    throw new Error('History replacement is already in progress')
  }
  set({ importingHistory: true })
  try {
    for (const controller of Object.values(get().streaming)) {
      controller.abort()
    }
    // 已开始的单条删除先同步到内存，避免本次替换失败后列表残留已被删除的记录。
    await Promise.all(deletionTasks)
    historyEpoch += 1
    initTask = undefined
    sessionLoads.clear()
    await waitForIdle()
    await db.clearStaging()
    let page: Awaited<ReturnType<typeof db.commitStaging>>
    try {
      await write()
      page = await db.commitStaging()
    } catch (error) {
      await db.clearStaging().catch(db.reportStorageError('clear unfinished history replacement'))
      throw error
    }
    sessionAccess.clear()
    unsavedSessions.clear()
    pendingFiles.clear()
    clearAllDrafts()
    set({
      ready: true,
      initError: false,
      sessions: sortSessions(page.sessions),
      nextCursor: page.nextCursor,
      loadingMore: false,
      sessionCache: {},
      messagePages: {},
      sessionLoadErrors: {},
      sessionSaveErrors: {},
      activeId: null,
    })
    return page.total
  } finally {
    set({ importingHistory: false })
    retrySaveSessions()
    const { activeId, ready } = get()
    // 替换失败时恢复被取消的详情读取，原有记录继续可用。
    if (activeId !== null) {
      void ensureSessionLoaded(activeId)
    } else if (!ready) {
      void initChat(true)
    }
  }
}

export const newChat = () => {
  if (!get().importingHistory) {
    set({ activeId: null })
    trimSessionCache()
  }
}

export const selectSession = (id: string) => {
  if (!get().importingHistory && get().sessions.some((session) => session.id === id)) {
    touchSession(id)
    set({ activeId: id })
    void ensureSessionLoaded(id)
    trimSessionCache()
  }
}

/** 搜索结果跳转：加载包含该消息的 20 条窗口，再定位到对应消息，不拉取其后全部内容。 */
export const openSessionAtMessage = async (sessionId: string, messageId: string): Promise<void> => {
  if (get().importingHistory || !get().sessions.some((session) => session.id === sessionId)) {
    return
  }
  const cached = getSession(sessionId)
  if (cached?.messages.some((message) => message.id === messageId)) {
    touchSession(sessionId)
    set({ activeId: sessionId })
    focusMessage(sessionId, messageId)
    return
  }
  const epoch = historyEpoch
  touchSession(sessionId)
  set({ activeId: sessionId })
  try {
    const stored = await db.loadSession(sessionId, { limit: MESSAGE_PAGE_SIZE, around: messageId })
    if (epoch !== historyEpoch || !stored) {
      return
    }
    const { hasMore, nextCursor, ...payload } = stored
    const session = normalizeSession(payload)
    set((state) => ({
      sessionCache: { ...state.sessionCache, [sessionId]: session },
      messagePages: {
        ...state.messagePages,
        [sessionId]: { hasMore: hasMore === true, cursor: nextCursor ?? null, loading: false },
      },
    }))
    focusMessage(sessionId, messageId)
    trimSessionCache()
  } catch (error) {
    db.reportStorageError('read session window')(error)
  }
}

/** 置顶 / 取消置顶：本地立即重排，再同步服务端 */
export const togglePinned = (id: string, pinned: boolean) => {
  if (get().importingHistory) {
    return
  }
  set((state) => ({
    sessions: sortSessions(
      state.sessions.map((session) => (session.id === id ? { ...session, pinned } : session))
    ),
  }))
  void db.setSessionPinned(id, pinned).catch(db.reportStorageError('pin session'))
}

export const renameSession = (id: string, title: string) => {
  if (get().importingHistory || deletingSessions.has(id)) {
    return
  }
  const controller = getStreamController(id)
  if (controller) {
    titleRequests.delete(controller)
  }
  if (getSession(id)) {
    updateSession(id, (session) => ({ ...session, title }))
    persist(id)
  } else {
    set((state) => ({
      sessions: state.sessions.map((session) =>
        session.id === id ? { ...session, title } : session
      ),
    }))
    void db.renameSession(id, title).catch(db.reportStorageError('rename session'))
  }
}

/**
 * 删除会话；本地原件仅在没有其他会话引用时清理。远端文件不删：导入、编辑、重新生成都会复用同一个附件，
 * 同一服务端文件可能被多个会话引用，不维护引用计数。
 */
export const removeSession = (id: string): Promise<boolean> => {
  const task = removeSessionData(id).finally(() => deletionTasks.delete(task))
  deletionTasks.add(task)
  return task
}

const removeSessionData = async (id: string): Promise<boolean> => {
  if (get().importingHistory || deletingSessions.has(id)) {
    return false
  }
  const epoch = historyEpoch
  deletingSessions.add(id)
  try {
    getStreamController(id)?.abort()
    // 等本会话的流收尾，避免删除之后又被收尾保存写回来。
    await waitForIdle(id)
    if (epoch !== historyEpoch) {
      return false
    }
    await db.deleteSession(id)
    if (epoch !== historyEpoch) {
      return true
    }
    sessionLoads.delete(id)
    sessionAccess.delete(id)
    unsavedSessions.delete(id)
    pendingFiles.delete(id)
    clearDraft(id)
    set((state) => ({
      sessions: state.sessions.filter((session) => session.id !== id),
      sessionCache: Object.fromEntries(
        Object.entries(state.sessionCache).filter(([key]) => key !== id)
      ),
      sessionLoadErrors: Object.fromEntries(
        Object.entries(state.sessionLoadErrors).filter(([key]) => key !== id)
      ),
      sessionSaveErrors: Object.fromEntries(
        Object.entries(state.sessionSaveErrors).filter(([key]) => key !== id)
      ),
      messagePages: Object.fromEntries(
        Object.entries(state.messagePages).filter(([key]) => key !== id)
      ),
      activeId: state.activeId === id ? null : state.activeId,
    }))
    return true
  } catch (error) {
    db.reportStorageError('delete session')(error)
    return false
  } finally {
    deletingSessions.delete(id)
    // 删除失败时保留会话，并保存停止生成后的最新状态。
    if (getSession(id)) {
      if (get().importingHistory) {
        unsavedSessions.add(id)
      } else {
        persist(id)
      }
    }
  }
}

export const stopReply = (sessionId: string) => getStreamController(sessionId)?.abort()

/**
 * 提交指定会话的最新草稿；null 表示新建会话。
 * 同步接收成功才清空草稿，附件原件由会话接管；保存失败仍通过保存入口重试。
 */
export const sendMessage = (targetSessionId: string | null) => {
  if (get().importingHistory || !get().ready) {
    return false
  }
  const prepared = prepareDraftMessage(getDraft(targetSessionId), useUploads.getState().tasks)
  if (!prepared) {
    return false
  }
  const { text, files } = prepared
  const now = Date.now()
  let sessionId = targetSessionId
  if (
    sessionId !== null &&
    (deletingSessions.has(sessionId) || getStreamController(sessionId) || !getSession(sessionId))
  ) {
    return false
  }
  if (sessionId === null) {
    const session: Session = {
      id: nextSnowflakeId(),
      title: '',
      createdAt: now,
      updatedAt: now,
      messages: [],
      rootMessageId: null,
    }
    sessionId = session.id
    touchSession(session.id)
    set((state) => ({
      sessions: [toSessionSummary(session), ...state.sessions],
      sessionCache: { ...state.sessionCache, [session.id]: session },
      activeId: session.id,
    }))
  }
  const current = getSession(sessionId)
  if (!current) {
    return false
  }
  const history = getVisibleMessages(current)
  const question: UserMessage = {
    id: nextSnowflakeId(),
    role: 'user',
    content: text,
    files: files.map(({ id, serverId, name, size, isImage, mimeType }) => ({
      id,
      serverId,
      // 草稿附件已上传成功，每张图片都在上传前检查过长边，超限的已缩小。
      imageSizeChecked: isImage,
      name,
      size,
      isImage,
      mimeType,
    })),
    createdAt: now,
  }
  const reply = createReply()
  updateSession(sessionId, (session) => ({
    ...appendBranch(session, history.at(-1)?.id ?? null, [question, reply]),
    updatedAt: now,
  }))
  if (files.length > 0) {
    pendingFiles.set(sessionId, [
      ...(pendingFiles.get(sessionId) ?? []),
      ...files.map(({ id, blob, name, mimeType, isImage }) => ({
        id,
        blob,
        name,
        mimeType,
        isImage,
      })),
    ])
  }
  persist(sessionId)
  clearSentDraft(targetSessionId)
  void streamReply(sessionId, reply, { history, question })
  return true
}

/** 重新生成当前路径上的回答：在原问题下新增版本，不覆盖旧回答和后续对话。 */
export const regenerateReply = (sessionId: string, messageId?: string) => {
  if (get().importingHistory || deletingSessions.has(sessionId)) {
    return
  }
  const session = getSession(sessionId)
  if (!session || getStreamController(sessionId)) {
    return
  }
  const path = getVisibleMessages(session)
  const index =
    messageId === undefined ? path.length - 1 : path.findIndex((m) => m.id === messageId)
  const question = path[index - 1]
  if (question?.role !== 'user' || path[index]?.role !== 'assistant') {
    return
  }
  const history = path.slice(0, index - 1)
  const reply = createReply()
  updateSession(sessionId, (s) => ({
    ...appendBranch(s, question.id, [reply]),
    updatedAt: Date.now(),
  }))
  persist(sessionId)
  void streamReply(sessionId, reply, { history, question })
}

/** 编辑提问后新增同父版本，保留原文件；旧问题的所有回答与后续对话仍可切回。 */
export const editMessage = (sessionId: string, messageId: string, content: string): boolean => {
  if (get().importingHistory || deletingSessions.has(sessionId)) {
    return false
  }
  const session = getSession(sessionId)
  if (!session || getStreamController(sessionId)) {
    return false
  }
  const path = getVisibleMessages(session)
  const index = path.findIndex((message) => message.id === messageId)
  const previous = path[index]
  const text = content.trim()
  if (previous?.role !== 'user' || (!text && previous.files.length === 0)) {
    return false
  }
  const question: UserMessage = {
    id: nextSnowflakeId(),
    role: 'user',
    content: text,
    files: previous.files,
    createdAt: Date.now(),
  }
  const reply = createReply()
  updateSession(sessionId, (s) => ({
    ...appendBranch(s, path[index - 1]?.id ?? null, [question, reply]),
    updatedAt: Date.now(),
  }))
  persist(sessionId)
  void streamReply(sessionId, reply, { history: path.slice(0, index), question })
  return true
}

/**
 * 撤销某一轮对话：删除该轮提问、回答以及之后的全部消息，并同步到服务端。
 * 生成中或正在导入时不允许操作；先补齐全部分页，避免保存时被服务端合并回旧消息。
 */
/**
 * 撤销某一轮对话：可传入该轮的用户提问或回答，删除该轮及之后的全部消息。
 * 生成中或正在导入时不允许操作；先补齐全部分页，避免保存时被服务端合并回旧消息。
 */
export const undoRound = async (sessionId: string, messageId: string): Promise<boolean> => {
  if (get().importingHistory || deletingSessions.has(sessionId)) {
    return false
  }
  if (!getSession(sessionId) || getStreamController(sessionId)) {
    return false
  }
  let guard = 0
  while (get().messagePages[sessionId]?.hasMore && guard < 50) {
    guard += 1
    await loadOlderMessages(sessionId)
  }
  const session = getSession(sessionId)
  if (!session) {
    return false
  }
  const path = getVisibleMessages(session)
  const index = path.findIndex((message) => message.id === messageId)
  if (index < 0) {
    return false
  }
  const target = path[index]
  const question = target.role === 'user' ? target : path[index - 1]
  if (!question || question.role !== 'user') {
    return false
  }
  updateSession(sessionId, (current) => ({
    ...truncateFrom(current, question.id),
    updatedAt: Date.now(),
  }))
  persist(sessionId)
  return true
}

export const selectMessageVersion = (sessionId: string, messageId: string) => {
  if (get().importingHistory || deletingSessions.has(sessionId)) {
    return
  }
  if (getStreamController(sessionId)) {
    return
  }
  updateSession(sessionId, (session) => selectVersion(session, messageId))
  persist(sessionId)
}

/** 继续最后一条未完成的回答：保留原消息与开关，仅将新流的内容追加到原块之后。 */
export const continueReply = (sessionId: string, messageId: string) => {
  if (get().importingHistory || deletingSessions.has(sessionId)) {
    return
  }
  const session = getSession(sessionId)
  if (!session || getStreamController(sessionId)) {
    return
  }
  const path = getVisibleMessages(session)
  const question = path.at(-2)
  const previous = path.at(-1)
  if (
    question?.role !== 'user' ||
    previous?.role !== 'assistant' ||
    previous.id !== messageId ||
    !canContinueReply(previous)
  ) {
    return
  }
  const { thinking, search } = useSettings.getState()
  const reply: AssistantMessage = {
    ...previous,
    // 未记录请求配置的回复，续写时使用默认模型和当前开关。
    model: previous.model ?? MODEL,
    thinking: previous.thinking ?? thinking,
    search: previous.search ?? search,
    status: 'pending',
    stopReason: undefined,
    stopDetails: undefined,
    error: undefined,
  }
  updateReply(sessionId, reply)
  updateSession(sessionId, (s) => ({ ...s, updatedAt: Date.now() }))
  persist(sessionId)
  void streamReply(sessionId, reply, { history: path.slice(0, -2), question, prefix: previous })
}

/** 附件补传失败后重试：保留原回答位置，重新补传并请求 */
export const retryUpload = (sessionId: string, messageId: string) => {
  if (get().importingHistory || deletingSessions.has(sessionId)) {
    return
  }
  const session = getSession(sessionId)
  if (!session || getStreamController(sessionId)) {
    return
  }
  const path = getVisibleMessages(session)
  const index = path.findIndex((message) => message.id === messageId)
  const question = path[index - 1]
  const previous = path[index]
  if (
    question?.role !== 'user' ||
    previous?.role !== 'assistant' ||
    previous.error?.kind !== 'upload'
  ) {
    return
  }
  const reply: AssistantMessage = { ...previous, status: 'pending', error: undefined }
  updateReply(sessionId, reply)
  persist(sessionId)
  void streamReply(sessionId, reply, { history: path.slice(0, index - 1), question })
}

type Request = { history: Message[]; question: UserMessage; prefix?: AssistantMessage }

/**
 * 将 serverId 写回所有引用同一附件的消息。传入 null 会清除超限旧图的 ID，触发补传。
 * 尺寸标记随有效 ID 保存，因为本地保留的是原图，刷新后可以直接复用已检查的上传副本。
 */
const saveServerIds = (sessionId: string, serverIds: ReadonlyMap<string, string | null>) => {
  if (serverIds.size === 0) {
    return
  }
  updateSession(sessionId, (session) => ({
    ...session,
    messages: session.messages.map((message) =>
      message.role === 'user' && message.files.some((file) => serverIds.has(file.id))
        ? {
            ...message,
            files: message.files.map((file) => {
              const serverId = serverIds.get(file.id)
              return serverId === undefined
                ? file
                : { ...file, serverId, imageSizeChecked: file.isImage && serverId !== null }
            }),
          }
        : message
    ),
  }))
  persist(sessionId)
}

/**
 * 附件的服务端 id：已有直接用；正在上传的等待同一任务；否则读取本地原件补传。
 * 本地也没有原件（导入的文档附件只有元数据）时返回 null，该附件只展示、不进入请求。
 */
const ensureServerId = async (file: FileAttachment): Promise<string | null> => {
  if (file.serverId) {
    return file.serverId
  }
  const task = useUploads.getState().tasks[file.id]
  if (task?.status === 'done') {
    return task.serverId
  }
  const blob = await db.loadFile(file.id)
  if (!blob) {
    return null
  }
  return uploadAttachment(file.id, blob, file.name, file.isImage)
}

/** 用会话里的最新消息替换请求快照：重传前会重置附件的 serverId，快照必须跟着更新 */
const refreshRequest = (sessionId: string, request: Request): Request => {
  const session = getSession(sessionId)
  if (!session) {
    return request
  }
  const byId = new Map(session.messages.map((message) => [message.id, message]))
  const pick = <T extends Message>(message: T): T =>
    (byId.get(message.id) as T | undefined) ?? message
  return {
    history: request.history.map(pick),
    question: pick(request.question),
    prefix: request.prefix,
  }
}

/**
 * 发送前补齐附件的 serverId。
 * - 本轮提问的附件并行补齐，任一失败则整体失败，已成功的照常写回，重试时复用成功结果；
 * - 历史轮次（仅进入上下文的）中没有 serverId 的附件，多为导入的图片：从最新的消息往前逐张补传，
 *   失败静默跳过，该附件不进入本次请求，下次发送时再试。
 */
const prepareFiles = async (sessionId: string, request: Request, signal: AbortSignal) => {
  const epoch = historyEpoch
  // 服务端判定 file_id 失效时会清掉会话里的 serverId，先用最新消息替换请求快照，
  // 否则快照里仍是旧 id，补传会直接复用它。
  request = refreshRequest(sessionId, request)
  const serverIds = new Map<string, string>()
  // 停止生成立即生效；上传本身不取消（输入框与其他消息可能共用同一任务），完成后照常写回 serverId
  const aborted = new Promise<never>((_, reject) => {
    signal.addEventListener('abort', () => reject(signal.reason), { once: true })
  })
  // 旧图片超限时先清除 ID，交给下方已有的附件补传流程。
  const files = [
    ...request.question.files,
    ...getContextRounds(request.history).flatMap(([user]) => user.files),
  ]
  const checked = await Promise.race([checkImageFileIds(files, signal), aborted])
  if (checked.size > 0) {
    saveServerIds(sessionId, checked)
    request = refreshRequest(sessionId, request)
  }
  const uploads = Promise.allSettled(
    request.question.files.map(async (file) => {
      const serverId = await ensureServerId(file)
      if (serverId && serverId !== file.serverId) {
        serverIds.set(file.id, serverId)
      }
    })
  )
  void uploads.then(() => {
    if (epoch === historyEpoch) {
      saveServerIds(sessionId, serverIds)
    }
  })
  const results = await Promise.race([uploads, aborted])
  const failed = results.find((result) => result.status === 'rejected')
  if (failed) {
    throw failed.reason
  }

  const pending = new Map<string, FileAttachment>()
  for (const [user] of getContextRounds(request.history).toReversed()) {
    for (const file of user.files) {
      if (!file.serverId && !serverIds.has(file.id) && !pending.has(file.id)) {
        pending.set(file.id, file)
      }
    }
  }
  for (const file of pending.values()) {
    signal.throwIfAborted()
    try {
      const serverId = await Promise.race([ensureServerId(file), aborted])
      if (serverId) {
        serverIds.set(file.id, serverId)
        saveServerIds(sessionId, new Map([[file.id, serverId]]))
      }
    } catch {
      signal.throwIfAborted()
    }
  }

  // 用补齐后的会话重新取历史与提问，保证请求里都是 serverId
  const session = getSession(sessionId)
  const resolve = <T extends Message>(message: T): T =>
    (session?.messages.find((m) => m.id === message.id) as T | undefined) ?? message
  return {
    history: request.history.map(resolve),
    question: resolve(request.question),
    prefix: request.prefix,
  }
}

/** 本次请求实际会用到的服务端 file_id */
const collectServerFileIds = (request: Request): Set<string> => {
  const ids = new Set<string>()
  const add = (files: FileAttachment[]) => {
    for (const file of files) {
      if (file.serverId) {
        ids.add(file.serverId)
      }
    }
  }
  add(request.question.files)
  for (const [user] of getContextRounds(request.history)) {
    add(user.files)
  }
  return ids
}

/**
 * 服务端判定 file_id 失效时，清掉对应附件的 serverId 并丢弃旧的上传任务，
 * 下一次补齐会重新读取本地原件上传。报错里点名了 file_id 就只重置这些，
 * 没点名（如 invalid file_id）则重置本次请求用到的全部附件。
 */
const reuploadInvalidatedFiles = (sessionId: string, request: Request, error: unknown): boolean => {
  if (!(error instanceof ApiError) || !getFileErrorCode(error)) {
    return false
  }
  const mentioned = extractFileIds(error.message)
  // 重试时原始快照里还是上一轮已被清掉的 serverId，先换成会话里的最新消息
  const targets =
    mentioned.length > 0
      ? new Set(mentioned)
      : collectServerFileIds(refreshRequest(sessionId, request))
  const session = getSession(sessionId)
  if (targets.size === 0 || !session) {
    return false
  }
  const localIds: string[] = []
  const messages = session.messages.map((message) => {
    if (message.role !== 'user') {
      return message
    }
    let changed = false
    const files = message.files.map((file) => {
      if (!file.serverId || !targets.has(file.serverId)) {
        return file
      }
      localIds.push(file.id)
      changed = true
      return { ...file, serverId: null }
    })
    return changed ? { ...message, files } : message
  })
  if (localIds.length === 0) {
    return false
  }
  updateSession(sessionId, (current) => ({ ...current, messages }))
  // 上传任务里可能还留着同一个失效的 serverId，清掉后 ensureServerId 才会重新上传
  localIds.forEach(cancelUpload)
  persist(sessionId)
  return true
}

/** 重传前的退避等待；停止生成时立即返回，由调用方按已停止收尾 */
const waitForRetry = (attempt: number, signal: AbortSignal) =>
  new Promise<void>((resolve) => {
    function onAbort() {
      clearTimeout(timer)
      resolve()
    }
    const timer = setTimeout(
      () => {
        signal.removeEventListener('abort', onAbort)
        resolve()
      },
      FILE_RETRY_BASE_DELAY * 2 ** attempt
    )
    signal.addEventListener('abort', onAbort, { once: true })
  })

/**
 * 请求并消费流：先补齐附件的 serverId，再发请求；流式增量按动画帧刷新内存，完成、失败或停止后落盘。
 * 补传失败时回复记为 upload 错误，可重试。
 * 服务端判定 file_id 失效时重新补传后重发，退避重试且次数有上限，超过则按原始报错收尾。
 */
const streamReply = async (sessionId: string, initial: AssistantMessage, request: Request) => {
  const controller = new AbortController()
  if (!getSession(sessionId)?.title) {
    titleRequests.add(controller)
  }
  set((state) => ({ streaming: { ...state.streaming, [sessionId]: controller } }))
  let reply = initial
  let frame = 0
  const flush = () => {
    cancelAnimationFrame(frame)
    frame = 0
    updateReply(sessionId, reply)
  }
  streamFlushes.set(sessionId, flush)

  try {
    // 分页打开时先补齐完整历史，保证上游请求的上下文不被截断
    try {
      await ensureFullHistory(sessionId)
    } catch (error) {
      db.reportStorageError('read full session')(error)
    }
    request = refreshRequestHistory(sessionId, request)
    for (let attempt = 0; ; attempt += 1) {
      /** 附件补齐前出错属于上传失败，请求还没有发出 */
      let preparing = true
      let receivedContent = false
      try {
        const prepared = await prepareFiles(sessionId, request, controller.signal)
        preparing = false
        // 附件就绪后获取本轮信息；续写沿用原回答快照，重新生成的快照独立保存。
        const latestReminder = prepared.prefix
          ? prepared.prefix.latestReminder
          : createLatestReminder(useSettings.getState())
        reply = { ...reply, latestReminder }
        updateReply(sessionId, reply)
        persist(sessionId)
        const messages = toRequestMessages(
          prepared.history,
          prepared.question,
          prepared.prefix,
          initial.thinking ?? false,
          latestReminder
        )
        const events = streamMessage(
          {
            model: initial.model ?? MODEL,
            system: SYSTEM_PROMPT,
            messages,
            thinking: { type: initial.thinking ? 'enabled' : 'disabled' },
            safety_check: SAFETY_CHECK,
            tools: serverTools(initial.search ?? false),
            ...(titleRequests.has(controller) ? { title: { enabled: true } } : {}),
          },
          controller.signal
        )
        const blockIndexes = new Map<number, number>()
        for await (const event of events) {
          receivedContent ||= event.type === 'content_block_start'
          if (
            event.type === 'message_delta' &&
            (event.delta.stop_reason === 'end_turn' || event.delta.stop_reason === 'max_tokens') &&
            event.delta.title?.trim() &&
            titleRequests.has(controller) &&
            !controller.signal.aborted &&
            !get().importingHistory
          ) {
            const title = event.delta.title.trim()
            updateSession(sessionId, (session) => (session.title ? session : { ...session, title }))
            titleRequests.delete(controller)
          }
          reply = applyEvent(reply, event, Date.now(), blockIndexes)
          if (document.visibilityState === 'hidden') {
            flush()
          } else {
            frame ||= requestAnimationFrame(flush)
          }
        }
        reply = settleReply(reply, 'done', Date.now())
        break
      } catch (error) {
        if (controller.signal.aborted) {
          reply = settleReply(reply, 'stopped', Date.now())
          break
        }
        if (preparing) {
          reply = settleReply(reply, 'error', Date.now(), {
            kind: 'upload',
            message: '',
            localMessage: describeUploadError(error),
            retryable: isRetryableApiError(error),
          })
          break
        }
        // 附件失效：重新补传后重发。收到过内容块（含丢弃的客户端工具）后不自动重发。
        if (
          attempt < FILE_RETRY_LIMIT &&
          !receivedContent &&
          reuploadInvalidatedFiles(sessionId, request, error)
        ) {
          await waitForRetry(attempt, controller.signal)
          if (controller.signal.aborted) {
            reply = settleReply(reply, 'stopped', Date.now())
            break
          }
          continue
        }
        if (error instanceof ApiError && error.kind === 'interrupted' && reply.stopReason) {
          // 已收到 message_delta 的结束原因，只是缺了结尾的 message_stop
          reply = settleReply(reply, 'done', Date.now())
        } else if (error instanceof ApiError) {
          reply = settleReply(reply, 'error', Date.now(), {
            kind: error.kind,
            message: error.message,
            status: error.status,
            localMessage: error.localMessage,
          })
        } else {
          reply = settleReply(reply, 'error', Date.now(), {
            kind: 'protocol',
            message: error instanceof Error ? error.message : String(error),
          })
        }
        break
      }
    }
  } finally {
    titleRequests.delete(controller)
    streamFlushes.delete(sessionId)
    cancelAnimationFrame(frame)
    updateReply(sessionId, reply)
    set((state) => ({
      streaming: Object.fromEntries(
        Object.entries(state.streaming).filter(([id]) => id !== sessionId)
      ),
    }))
    persist(sessionId)
  }
}
