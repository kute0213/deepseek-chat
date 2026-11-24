import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { useIsMobile } from '../../app/viewport.js'
import {
  buildMessageVersions,
  getVisibleMessages,
  type MessageVersions,
} from '../../services/chat/branches.js'
import { loadOlderMessages, selectMessageVersion, useChatStore } from '../../services/chat/store.js'
import { clearMessageFocus, useMessageFocus } from '../../services/chat/focus.js'
import type { Session } from '../../services/chat/types.js'
import { useTranslate } from '../../i18n/index.js'
import { closeSearchPanel } from '../search/searchPanelState.js'
import { AssistantMessage } from './AssistantMessage.js'
import { ScrollToBottomContext } from './scrollContext.js'
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
/** 距顶部小于该值时加载更早的一页消息 */
const TOP_THRESHOLD = 40
const EMPTY_VERSIONS: MessageVersions = { ids: [], index: -1 }

export const MessageList = ({
  session,
  streaming,
  footer,
}: {
  session: Session
  streaming: boolean
  footer: React.ReactNode
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
  const messages = getVisibleMessages(session)
  const hasMore = useChatStore((state) => state.messagePages[session.id]?.hasMore ?? false)
  const loadingOlder = useChatStore((state) => state.messagePages[session.id]?.loading ?? false)
  const focusTarget = useMessageFocus((state) => state.target)
  const [previousVersions, setPreviousVersions] = useState(() =>
    buildMessageVersions(session, new Map())
  )
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

  // 切换会话时丢弃上一个会话遗留的“保持位置”记录
  useLayoutEffect(() => {
    pendingPrependRef.current = null
    prevLayoutRef.current = null
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

  useEffect(closeSearchPanel, [session.id, lastMessageId])

  // 搜索命中后跳转到对应消息：窗口里没有就先继续加载更早的消息，定位后短暂高亮。
  useEffect(() => {
    const el = scrollRef.current
    if (!focusTarget || focusTarget.sessionId !== session.id || !el) {
      return
    }
    const node = el.querySelector<HTMLElement>(
      `[data-message-id="${CSS.escape(focusTarget.messageId)}"]`
    )
    if (!node) {
      if (hasMore && !loadingOlder) {
        pendingPrependRef.current = { height: el.scrollHeight, top: el.scrollTop }
        void loadOlderMessages(session.id)
      }
      return
    }
    stickyRef.current = false
    node.scrollIntoView({ block: 'center', behavior: 'smooth' })
    node.classList.add(styles.flash)
    window.setTimeout(() => node.classList.remove(styles.flash), 1800)
    clearMessageFocus()
  }, [focusTarget, session.id, hasMore, loadingOlder, messages.length])

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
      // 仅在用户向上滚动时停止跟随底部，布局或内容增高时保持跟随状态。
      if (nearBottom) {
        stickyRef.current = true
      } else if (el.scrollTop < scrollTopRef.current) {
        stickyRef.current = false
      }
      // 滑到顶部后加载更早的一页；到达顶部即触发，不必再继续上滑
      if (hasMore && !loadingOlder && el.scrollTop <= TOP_THRESHOLD) {
        pendingPrependRef.current = { height: el.scrollHeight, top: el.scrollTop }
        void loadOlderMessages(session.id)
      }
      scrollTopRef.current = el.scrollTop
    }
    el.addEventListener('scroll', onScroll, { passive: true })
    return () => el.removeEventListener('scroll', onScroll)
  }, [isMobile, scrollToBottom, hasMore, loadingOlder, session.id])

  const lastIndex = messages.length - 1
  const uploadRetryId = getUploadRetryId(messages, streaming)
  return (
    <ScrollToBottomContext.Provider value={scrollToBottom}>
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
            {messages.map((message, index) =>
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
            )}
          </div>
        </div>
        {footer}
      </div>
    </ScrollToBottomContext.Provider>
  )
}
