import clsx from 'clsx'
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { useIsMobile } from '../../app/viewport.js'
import { useTranslate } from '../../i18n/index.js'
import { getVisibleMessages } from '../../services/chat/branches.js'
import { loadOlderMessages, useChatStore } from '../../services/chat/store.js'
import type { Message, Session } from '../../services/chat/types.js'
import { IconChevronDownOutline14 } from '../base/icons.js'
import styles from './MessageNavigator.module.css'

/** 右侧导航每页显示的记录数 */
const PAGE_SIZE = 7

/** 是否允许平滑滚动（尊重系统的减少动态效果） */
const smoothBehavior = (): ScrollBehavior =>
  window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'

/** 右侧短横导航里的一条记录：一条用户提问。 */
const previewText = (
  message: Message,
  attachmentLabel: (count: number) => string,
  emptyLabel: string
): string => {
  if (message.role !== 'user') {
    return ''
  }
  const text = message.content.trim()
  if (text) {
    return text
  }
  return message.files.length > 0 ? attachmentLabel(message.files.length) : emptyLabel
}

/**
 * 会话右侧的消息导航：短横对应每条用户提问，悬浮展开预览列表，点击直接跳转；
 * 每页 10 条，可用上下箭头翻页；展开时自动把当前记录滚动到预览列表中间。
 */
export const MessageNavigator = ({ session }: { session: Session }) => {
  const t = useTranslate()
  const isMobile = useIsMobile()
  const preview = (message: Message) =>
    previewText(message, (count) => t('shareAttachmentCount', { count }), t('emptyMessagePreview'))
  const hasMore = useChatStore((state) => state.messagePages[session.id]?.hasMore ?? false)
  const loading = useChatStore((state) => state.messagePages[session.id]?.loading ?? false)
  const items = useMemo(
    () => getVisibleMessages(session).filter((message) => message.role === 'user'),
    [session]
  )
  const [open, setOpen] = useState(false)
  const [activeId, setActiveId] = useState<string | null>(null)
  const [hoveredId, setHoveredId] = useState<string | null>(null)
  /** 距离最后一页的页数：即便更早消息加载进来，也默认停留在最新一页 */
  const [pageFromEnd, setPageFromEnd] = useState(0)
  const panelRef = useRef<HTMLDivElement>(null)
  const closeTimer = useRef<number>()
  const pendingPanelRef = useRef<{ height: number; top: number } | null>(null)

  const pages = Math.max(1, Math.ceil(items.length / PAGE_SIZE))
  const pageIndex = Math.max(0, pages - 1 - Math.min(pageFromEnd, pages - 1))
  const pageItems = useMemo(
    () => items.slice(pageIndex * PAGE_SIZE, pageIndex * PAGE_SIZE + PAGE_SIZE),
    [items, pageIndex]
  )

  const openPanel = useCallback(() => {
    window.clearTimeout(closeTimer.current)
    setOpen(true)
  }, [])

  const scheduleClose = useCallback(() => {
    window.clearTimeout(closeTimer.current)
    closeTimer.current = window.setTimeout(() => {
      setOpen(false)
      setHoveredId(null)
    }, 120)
  }, [])

  useEffect(() => () => window.clearTimeout(closeTimer.current), [])

  // 跟随主列表滚动，标记当前停留在的提问；若不在当前页则自动翻到对应页
  useEffect(() => {
    const container = document.querySelector<HTMLElement>('[data-message-scroll]')
    if (!container || items.length === 0) {
      return
    }
    let frame = 0
    const update = () => {
      frame = 0
      const top = container.getBoundingClientRect().top
      let current: string | null = null
      let currentIndex = -1
      for (let index = 0; index < items.length; index += 1) {
        const item = items[index]
        const el = container.querySelector<HTMLElement>(
          `[data-message-id="${CSS.escape(item.id)}"]`
        )
        if (!el) {
          continue
        }
        if (el.getBoundingClientRect().top - top <= 88) {
          current = item.id
          currentIndex = index
        } else {
          break
        }
      }
      setActiveId(current)
      if (currentIndex >= 0) {
        const target = Math.floor(currentIndex / PAGE_SIZE)
        setPageFromEnd((previous) => {
          const next = pages - 1 - target
          return next === previous ? previous : Math.max(0, next)
        })
      }
    }
    const onScroll = () => {
      if (!frame) {
        frame = requestAnimationFrame(update)
      }
    }
    update()
    container.addEventListener('scroll', onScroll, { passive: true })
    return () => {
      container.removeEventListener('scroll', onScroll)
      cancelAnimationFrame(frame)
    }
  }, [items, pages, session.id])

  // 展开时把当前记录（没有则最近一条）滚动到预览列表中间
  useLayoutEffect(() => {
    if (!open) {
      return
    }
    const panel = panelRef.current
    if (!panel) {
      return
    }
    const targetId = hoveredId ?? activeId ?? pageItems.at(-1)?.id
    const target = targetId
      ? panel.querySelector<HTMLElement>(`[data-nav-id="${CSS.escape(targetId)}"]`)
      : null
    if (!target) {
      return
    }
    const panelRect = panel.getBoundingClientRect()
    const targetRect = target.getBoundingClientRect()
    const top =
      panel.scrollTop +
      (targetRect.top - panelRect.top) -
      panel.clientHeight / 2 +
      target.clientHeight / 2
    panel.scrollTo({ top: Math.max(0, top), behavior: smoothBehavior() })
  }, [open, hoveredId, activeId, pageIndex, pageItems])

  // 预览列表顶部插入更早记录后保持滚动位置
  useLayoutEffect(() => {
    const panel = panelRef.current
    const pending = pendingPanelRef.current
    if (!panel || !pending) {
      return
    }
    pendingPanelRef.current = null
    const delta = panel.scrollHeight - pending.height
    if (delta > 0) {
      panel.scrollTop = pending.top + delta
    }
  }, [items.length, hasMore])

  // 预览列表滚动到顶部时加载更早的一页，并显示加载状态
  const onPanelScroll = () => {
    const panel = panelRef.current
    if (!panel || !hasMore || loading || panel.scrollTop > 8) {
      return
    }
    pendingPanelRef.current = { height: panel.scrollHeight, top: panel.scrollTop }
    void loadOlderMessages(session.id)
  }

  // 点击后直接跳转，不做平滑滚动；触屏端先展开预览面板
  const jump = (messageId: string) => {
    const target = document.querySelector<HTMLElement>(
      `[data-message-id="${CSS.escape(messageId)}"]`
    )
    target?.scrollIntoView({ block: 'start' })
    setActiveId(messageId)
    setOpen(false)
  }

  const onRailClick = (messageId: string) => {
    if (isMobile) {
      setHoveredId(messageId)
      openPanel()
      return
    }
    jump(messageId)
  }

  if (items.length === 0) {
    return null
  }

  return (
    <div
      className={clsx(styles.root, isMobile && styles.mobile)}
      onMouseEnter={isMobile ? undefined : openPanel}
      onMouseLeave={isMobile ? undefined : scheduleClose}
      aria-label={t('messageNavigator')}
    >
      {open ? (
        <div
          className={styles.panel}
          ref={panelRef}
          onScroll={onPanelScroll}
          onMouseEnter={() => {
            window.clearTimeout(closeTimer.current)
          }}
        >
          {hasMore ? (
            <div className={styles.panelLoading} role="status" aria-live="polite">
              {loading ? t('messagesLoadingMore') : ''}
            </div>
          ) : null}
          <div className={styles.list}>
            {pageItems.map((item) => (
              <button
                key={item.id}
                type="button"
                data-nav-id={item.id}
                className={clsx(
                  styles.item,
                  activeId === item.id && styles.itemActive,
                  hoveredId === item.id && styles.itemHovered
                )}
                onMouseEnter={() => setHoveredId(item.id)}
                onFocus={() => setHoveredId(item.id)}
                onClick={() => jump(item.id)}
              >
                <span className={styles.itemText}>{preview(item)}</span>
              </button>
            ))}
          </div>
        </div>
      ) : null}
      <div className={styles.rail}>
        {pages > 1 ? (
          <button
            type="button"
            className={clsx(styles.pageButton, styles.pageButtonUp)}
            disabled={pageFromEnd >= pages - 1}
            aria-label={t('messageNavigatorPrev')}
            onClick={() => setPageFromEnd((value) => Math.min(pages - 1, value + 1))}
          >
            <IconChevronDownOutline14 />
          </button>
        ) : null}
        {pageItems.map((item) => (
          <button
            key={item.id}
            type="button"
            className={clsx(
              styles.bar,
              activeId === item.id && styles.barActive,
              hoveredId === item.id && styles.barHovered
            )}
            onMouseEnter={() => {
              setHoveredId(item.id)
              openPanel()
            }}
            onFocus={() => {
              setHoveredId(item.id)
              openPanel()
            }}
            onClick={() => onRailClick(item.id)}
            aria-label={preview(item).slice(0, 40)}
          />
        ))}
        {pages > 1 ? (
          <button
            type="button"
            className={styles.pageButton}
            disabled={pageFromEnd <= 0}
            aria-label={t('messageNavigatorNext')}
            onClick={() => setPageFromEnd((value) => Math.max(0, value - 1))}
          >
            <IconChevronDownOutline14 />
          </button>
        ) : null}
      </div>
    </div>
  )
}
