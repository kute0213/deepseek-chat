import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import clsx from 'clsx'
import { useIsMobile } from '../../app/viewport.js'
import {
  buildMessageVersions,
  getVisibleMessages,
  type MessageVersions,
} from '../../services/chat/branches.js'
import {
  loadMessageWindow,
  loadNewerMessages,
  loadOlderMessages,
  selectMessageVersion,
  useChatStore,
} from '../../services/chat/store.js'
import { clearMessageFocus, useMessageFocus } from '../../services/chat/focus.js'
import type { Session } from '../../services/chat/types.js'
import { useTranslate } from '../../i18n/index.js'
import { closeSearchPanel } from '../search/searchPanelState.js'
import { IconCheckOutline16 } from '../base/icons.js'
import { AssistantMessage } from './AssistantMessage.js'
import { AtBottomContext, JumpToBottomContext, ScrollToBottomContext } from './scrollContext.js'
import { UserMessage } from './UserMessage.js'
import type { Message } from '../../services/chat/types.js'

/**
 * 会话共用可重试的附件上传失败入口，覆盖当前提问与历史消息中的附件。
 * 点击后按失败的回答重新补传附件并请求。
 */
const getUploadRetryId = (messages: Message[], streaming: boolean) => {
  const last = messages.at(-1)
  return !streaming &&
    last?.role === 'assistant' &&
    last.status === 'error' &&
    last.error?.kind === 'upload' &&
    last.error.retryable
    ? last.id
    : null
}
import styles from './MessageList.module.css'

/** 距底部小于该值时视为在底部，流式内容增长时跟随 */
const STICKY_THRESHOLD = 40
/** 距离顶部不足该轮数提问时预加载更早的一页（一页 10 条消息 = 5 轮） */
const PRELOAD_ROUNDS = 5
const EMPTY_VERSIONS: MessageVersions = { ids: [], index: -1 }

/**
 * 判断是否已经接近当前消息窗口的顶部：窗口内第 5 条用户提问仍在视口内或下方时，
 * 说明距离顶部不足 5 轮，提前加载更早的一页，避免贴到顶端才请求。
 */
const shouldPreloadOlder = (el: HTMLElement, roles: Map<string, string>): boolean => {
  const top = el.getBoundingClientRect().top
  let rounds = 0
  for (const node of el.querySelectorAll<HTMLElement>('[data-message-id]')) {
    if (roles.get(node.dataset.messageId ?? '') !== 'user') {
      continue
    }
    rounds += 1
    if (rounds === PRELOAD_ROUNDS) {
      return node.getBoundingClientRect().bottom > top
    }
  }
  // 窗口内不足 5 轮：直接补齐
  return true
}

export const MessageList = ({
  session,
  streaming,
  footer,
  shareMode = false,
  shareSelected,
  onToggleShare,
}: {
  session: Session
  streaming: boolean
  footer: React.ReactNode
  shareMode?: boolean
  shareSelected?: Set<string>
  onToggleShare?: (messageId: string) => void
}) => {
  const isMobile = useIsMobile()
  const t = useTranslate()
  const scrollRef = useRef<HTMLDivElement>(null)
  const contentRef = useRef<HTMLDivElement>(null)
  const stickyRef = useRef(true)
  const scrollTopRef = useRef(0)
  const restoreScrollRef = useRef<{ messageId: string; top: number } | null>(null)
  /** 加载更早消息前记录高度，渲染后把滚动位置保持在原内容上 */
  const pendingPrependRef = useRef<{ height: number; top: number } | null>(null)
  /** 上一次渲染后的滚动高度与首条消息，用于识别从其他入口（右侧导航）插入的更早消息 */
  const prevLayoutRef = useRef<{ height: number; firstId: string | undefined } | null>(null)
  /** 记录已尝试自动补齐的窗口，失败时不重复触发 */
  const fillKeyRef = useRef('')
  /** 搜索 / 右侧导航跳转时，记录已拉取过“目标消息所在窗口”，避免重复请求 */
  const jumpWindowKeyRef = useRef('')
  /** 用户是否亲手滚动过；跳转定位的程序化滚动不算，避免跳转即触发向下续载 */
  const userScrollRef = useRef(false)
  const messages = getVisibleMessages(session)
  // 预加载判断需要知道每条消息的角色；用 ref 保证滚动回调里读到最新值
  const roles = useMemo(
    () => new Map(messages.map((message) => [message.id, message.role])),
    [messages]
  )
  const rolesRef = useRef(roles)
  rolesRef.current = roles
  const hasMore = useChatStore((state) => state.messagePages[session.id]?.hasMore ?? false)
  const hasNewer = useChatStore((state) => state.messagePages[session.id]?.hasNewer ?? false)
  const loadingOlder = useChatStore((state) => state.messagePages[session.id]?.loading ?? false)
  const focusTarget = useMessageFocus((state) => state.target)
  const [previousVersions, setPreviousVersions] = useState(() =>
    buildMessageVersions(session, new Map())
  )
  /** 是否停在底部；由滚动与跟随状态更新，驱动回底按钮 */
  const [atBottom, setAtBottom] = useState(true)
  const versions = useMemo(
    () => buildMessageVersions(session, previousVersions),
    [session, previousVersions]
  )
  if (versions !== previousVersions) {
    setPreviousVersions(versions)
  }
  const lastMessageId = messages.at(-1)?.id
  const firstMessageId = messages[0]?.id
  /** 可见首条消息或底层消息窗口变化时，都需要校正滚动位置 */
  const messageWindowKey = `${firstMessageId}:${session.messages.length}`
  const scrollToBottom = useCallback(() => {
    const el = scrollRef.current
    if (el && stickyRef.current) {
      el.scrollTop = el.scrollHeight
      scrollTopRef.current = el.scrollTop
    }
  }, [])
  /** 用户主动点击回底：无论当前是否跟随都跳到底部，并恢复跟随 */
  const jumpToBottom = useCallback(() => {
    stickyRef.current = true
    setAtBottom(true)
    const el = scrollRef.current
    if (el) {
      el.scrollTop = el.scrollHeight
      scrollTopRef.current = el.scrollTop
    }
  }, [])

  // 切换会话时丢弃上一个会话遗留的“保持位置”记录
  useLayoutEffect(() => {
    pendingPrependRef.current = null
    prevLayoutRef.current = null
    userScrollRef.current = false
    setAtBottom(true)
  }, [session.id])

  // 内容、键盘和输入框高度变化时，继续遵循当前跟随状态。
  useLayoutEffect(() => {
    const content = contentRef.current
    const scroll = scrollRef.current
    if (!content || !scroll) {
      return
    }
    const observer = new ResizeObserver(scrollToBottom)
    observer.observe(content)
    observer.observe(scroll)
    return () => observer.disconnect()
  }, [isMobile, scrollToBottom])

  // 切换版本后将选择器留在原位置，避免回答长短变化导致操作区跳出视口。
  useLayoutEffect(() => {
    const anchor = restoreScrollRef.current
    const scroll = scrollRef.current
    if (anchor && scroll) {
      const switcher = scroll.querySelector<HTMLElement>(
        `[data-message-version="${CSS.escape(anchor.messageId)}"]`
      )
      if (switcher) {
        scroll.scrollTop += switcher.getBoundingClientRect().top - anchor.top
      }
      restoreScrollRef.current = null
      return
    }
    stickyRef.current = true
    scrollToBottom()
  }, [session.id, lastMessageId, scrollToBottom])

  // 更早的消息插入到顶部后，把原阅读位置向下平移，避免视口跳动。
  // 除列表自身触发的加载外，右侧导航加载更早消息时也走同一套校正。
  useLayoutEffect(() => {
    const el = scrollRef.current
    if (!el) {
      return
    }
    const pending = pendingPrependRef.current
    const prev = prevLayoutRef.current
    pendingPrependRef.current = null
    prevLayoutRef.current = { height: el.scrollHeight, firstId: firstMessageId }
    if (pending) {
      const delta = el.scrollHeight - pending.height
      if (delta > 0) {
        el.scrollTop = pending.top + delta
        scrollTopRef.current = el.scrollTop
        stickyRef.current = false
      }
      return
    }
    if (!stickyRef.current && prev && prev.firstId !== firstMessageId) {
      const delta = el.scrollHeight - prev.height
      if (delta > 0) {
        el.scrollTop += delta
        scrollTopRef.current = el.scrollTop
      }
    }
  }, [messageWindowKey, firstMessageId])

  // 消息不足一屏时无法通过滚动触发，主动补齐到可滚动为止。
  useLayoutEffect(() => {
    const key = `${session.id}:${firstMessageId}:${hasMore}`
    if (fillKeyRef.current === key) {
      return
    }
    fillKeyRef.current = key
    if (!hasMore || loadingOlder) {
      return
    }
    const el = scrollRef.current
    if (!el || el.scrollHeight > el.clientHeight + STICKY_THRESHOLD) {
      return
    }
    pendingPrependRef.current = { height: el.scrollHeight, top: el.scrollTop }
    void loadOlderMessages(session.id)
  }, [firstMessageId, hasMore, loadingOlder, session.id])

  // 跳转后的中间窗口：只有用户主动向下滚动（含窗口不足一屏、无法滚动时）才继续加载更晚的消息。
  // 不在布局阶段自动触发，避免跳转后把后面的所有对话一次性加载完。
  useEffect(() => {
    const el = scrollRef.current
    if (!el) {
      return
    }
    const onWheel = (event: WheelEvent) => {
      userScrollRef.current = true
      if (event.deltaY <= 0 || !hasNewer || loadingOlder) {
        return
      }
      if (el.scrollHeight - el.scrollTop - el.clientHeight > STICKY_THRESHOLD) {
        return
      }
      stickyRef.current = false
      void loadNewerMessages(session.id)
    }
    // 触摸设备没有滚轮：触摸滚动同样视为用户操作
    const onTouchStart = () => {
      userScrollRef.current = true
    }
    el.addEventListener('wheel', onWheel, { passive: true })
    el.addEventListener('touchstart', onTouchStart, { passive: true })
    return () => {
      el.removeEventListener('wheel', onWheel)
      el.removeEventListener('touchstart', onTouchStart)
    }
  }, [hasNewer, isMobile, loadingOlder, session.id])

  useEffect(closeSearchPanel, [session.id, lastMessageId])

  // 搜索命中或右侧导航跳转到对应消息：当前窗口没有就先加载它所在的窗口，定位后短暂高亮。
  useEffect(() => {
    const el = scrollRef.current
    if (!focusTarget || focusTarget.sessionId !== session.id || !el) {
      return
    }
    const node = el.querySelector<HTMLElement>(
      `[data-message-id="${CSS.escape(focusTarget.messageId)}"]`
    )
    if (!node) {
      // 目标消息不在当前分页窗口内：直接拉取包含它的那一页，而不是逐页向前翻。
      const key = `${session.id}:${focusTarget.messageId}`
      if (jumpWindowKeyRef.current !== key) {
        jumpWindowKeyRef.current = key
        void loadMessageWindow(session.id, focusTarget.messageId).catch(() => {})
      }
      return
    }
    stickyRef.current = false
    setAtBottom(false)
    node.scrollIntoView({ block: 'center' })
    clearMessageFocus()
  }, [firstMessageId, focusTarget, messages.length, session.id])

  const selectVersion = useCallback(
    (messageId: string, anchorTop: number) => {
      if (streaming) {
        return
      }
      restoreScrollRef.current = { messageId, top: anchorTop }
      stickyRef.current = false
      closeSearchPanel()
      selectMessageVersion(session.id, messageId)
    },
    [session.id, streaming]
  )

  // 跨移动端断点时切换滚动层，并保持输入框及当前阅读位置。
  useLayoutEffect(() => {
    const el = scrollRef.current
    if (!el) {
      return
    }
    if (stickyRef.current) {
      scrollToBottom()
    } else {
      el.scrollTop = scrollTopRef.current
    }
    const onScroll = () => {
      const nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < STICKY_THRESHOLD
      setAtBottom(nearBottom)
      // 仅在用户向上滚动时停止跟随底部，布局或内容增高时保持跟随状态。
      if (nearBottom) {
        stickyRef.current = true
      } else if (el.scrollTop < scrollTopRef.current) {
        stickyRef.current = false
      }
      // 距离顶部不足 5 轮提问时提前加载更早的一页，不必等贴到最顶端
      if (hasMore && !loadingOlder && shouldPreloadOlder(el, rolesRef.current)) {
        pendingPrependRef.current = { height: el.scrollHeight, top: el.scrollTop }
        void loadOlderMessages(session.id)
      }
      // 跳转后的中间窗口：用户亲手滚到底部时才继续加载更晚的消息。
      // 跳转定位的程序化滚动不算，加载后也不自动跟随，避免把后面所有对话全部拉下来。
      if (hasNewer && !loadingOlder && nearBottom && userScrollRef.current) {
        stickyRef.current = false
        void loadNewerMessages(session.id)
      }
      scrollTopRef.current = el.scrollTop
    }
    el.addEventListener('scroll', onScroll, { passive: true })
    return () => el.removeEventListener('scroll', onScroll)
  }, [isMobile, scrollToBottom, hasMore, hasNewer, loadingOlder, session.id])

  const lastIndex = messages.length - 1
  const uploadRetryId = getUploadRetryId(messages, streaming)
  return (
    <ScrollToBottomContext.Provider value={scrollToBottom}>
      <AtBottomContext.Provider value={atBottom}>
        <JumpToBottomContext.Provider value={jumpToBottom}>
          <div
            ref={isMobile ? undefined : scrollRef}
            className={styles.container}
            data-message-scroll={isMobile ? undefined : ''}
          >
            <div
              ref={isMobile ? scrollRef : undefined}
              className={styles.scroll}
              data-message-scroll={isMobile ? '' : undefined}
            >
              <div ref={contentRef} className={styles.column}>
                {hasMore ? (
                  <div className={styles.loadingTop} role="status" aria-live="polite">
                    {loadingOlder ? t('messagesLoadingMore') : ''}
                  </div>
                ) : null}
                {messages.map((message, index) => {
                  const content =
                    message.role === 'user' ? (
                      <UserMessage
                        key={message.id}
                        sessionId={session.id}
                        message={message}
                        retryUploadId={uploadRetryId}
                        isFirst={index === 0}
                        isLast={index === lastIndex || index === lastIndex - 1}
                        busy={streaming}
                        versions={versions.get(message.id) ?? EMPTY_VERSIONS}
                        onSelectVersion={selectVersion}
                      />
                    ) : (
                      <AssistantMessage
                        key={message.id}
                        sessionId={session.id}
                        message={message}
                        isLast={index === lastIndex}
                        streaming={streaming && index === lastIndex}
                        busy={streaming}
                        versions={versions.get(message.id) ?? EMPTY_VERSIONS}
                        onSelectVersion={selectVersion}
                      />
                    )
                  if (!shareMode) {
                    return content
                  }
                  const checked = shareSelected?.has(message.id) ?? false
                  return (
                    <div
                      key={message.id}
                      className={clsx(styles.shareRow, checked && styles.shareRowOn)}
                      onClick={(event) => {
                        const element = event.target instanceof Element ? event.target : null
                        if (element?.closest('a, button, input, textarea, select, [role="menu"]')) {
                          return
                        }
                        onToggleShare?.(message.id)
                      }}
                    >
                      <button
                        type="button"
                        className={clsx(styles.shareCheck, checked && styles.shareCheckOn)}
                        aria-pressed={checked}
                        aria-label={t('shareSelectMessage')}
                        onClick={() => onToggleShare?.(message.id)}
                      >
                        {checked ? <IconCheckOutline16 /> : null}
                      </button>
                      <div className={styles.shareContent}>{content}</div>
                    </div>
                  )
                })}
              </div>
            </div>
            {footer}
          </div>
        </JumpToBottomContext.Provider>
      </AtBottomContext.Provider>
    </ScrollToBottomContext.Provider>
  )
}
