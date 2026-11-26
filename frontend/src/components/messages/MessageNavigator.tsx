import clsx from 'clsx'
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { useIsMobile } from '../../app/viewport.js'
import { useTranslate } from '../../i18n/index.js'
import { getVisibleMessages } from '../../services/chat/branches.js'
import {
  ensureSessionOutline,
  openSessionAtMessage,
  useChatStore,
} from '../../services/chat/store.js'
import type { Message, Session } from '../../services/chat/types.js'
import styles from './MessageNavigator.module.css'

/** 收起时轨道上可见的短横数量（与原版一致） */
const VISIBLE_BARS = 7
/** 短横高度与间距，和 CSS 保持一致 */
const BAR_HEIGHT = 4
const BAR_GAP = 12
const RAIL_PADDING = 8

/** 是否允许平滑滚动（尊重系统的减少动态效果） */
const smoothBehavior = (): ScrollBehavior =>
  window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'

type NavigatorItem = { id: string; text: string; files: number }

/** 右侧短横导航里的一条记录：一条用户提问。 */
const previewOf = (message: Message): string =>
  message.role === 'user' ? message.content.trim() : ''

/**
 * 会话右侧的消息导航：短横对应每条用户提问。
 * 收起时轨道固定展示约 7 条，可滚轮平滑滑动；悬浮 / 点击展开完整预览列表。
 * 预览文本由后端一次性返回，翻看任意历史提问都不再分页加载。
 */
export const MessageNavigator = ({ session }: { session: Session }) => {
  const t = useTranslate()
  const isMobile = useIsMobile()
  const outline = useChatStore((state) => state.outlines[session.id])

  useEffect(() => {
    ensureSessionOutline(session.id)
  }, [session.id])

  // 已加载消息用于补全最新几条与文本更新，未加载的部分由大纲提供
  const loadedUsers = useMemo<NavigatorItem[]>(
    () =>
      getVisibleMessages(session)
        .filter((message) => message.role === 'user')
        .map((message) => ({
          id: message.id,
          text: previewOf(message),
          files: message.files.length,
        })),
    [session]
  )
  // 仅当用户消息内容真正变化时才重建完整导航，避免流式回复时反复重算
  const loadedKey = loadedUsers
    .map((item) => `${item.id}:${item.files}:${item.text.length}:${item.text.slice(0, 32)}`)
    .join('|')
  const loadedRef = useRef(loadedUsers)
  loadedRef.current = loadedUsers
  const items = useMemo<NavigatorItem[]>(() => {
    const loaded = loadedRef.current
    const byId = new Map(loaded.map((item) => [item.id, item]))
    const merged = (outline ?? []).map((item) => {
      const local = byId.get(item.id)
      return local
        ? { id: item.id, text: local.text || item.text, files: local.files }
        : { id: item.id, text: item.text, files: item.files }
    })
    const seen = new Set(merged.map((item) => item.id))
    for (const item of loaded) {
      if (!seen.has(item.id)) {
        merged.push(item)
      }
    }
    return merged
  }, [outline, loadedKey])

  const textOf = useCallback(
    (item: NavigatorItem) =>
      item.text ||
      (item.files > 0
        ? t('shareAttachmentCount', { count: item.files })
        : t('emptyMessagePreview')),
    [t]
  )

  const [open, setOpen] = useState(false)
  const [activeId, setActiveId] = useState<string | null>(null)
  const [hoveredId, setHoveredId] = useState<string | null>(null)
  const panelRef = useRef<HTMLDivElement>(null)
  const railRef = useRef<HTMLDivElement>(null)
  const closeTimer = useRef<number>()

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

  // 跟随主列表滚动，标记当前停留在的提问
  useEffect(() => {
    const container = document.querySelector<HTMLElement>('[data-message-scroll]')
    if (!container || items.length === 0) {
      return
    }
    const itemIds = new Set(items.map((item) => item.id))
    let frame = 0
    const update = () => {
      frame = 0
      const top = container.getBoundingClientRect().top
      let current: string | null = null
      const nodes = container.querySelectorAll<HTMLElement>('[data-message-id]')
      for (const el of nodes) {
        if (el.getBoundingClientRect().top - top > 88) {
          break
        }
        const id = el.dataset.messageId
        if (id && itemIds.has(id)) {
          current = id
        }
      }
      setActiveId(current)
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
  }, [items, session.id])

  // 展开时把当前记录（没有则最近一条）滚动到预览列表中间
  useLayoutEffect(() => {
    if (!open) {
      return
    }
    const panel = panelRef.current
    if (!panel) {
      return
    }
    const targetId = hoveredId ?? activeId ?? items.at(-1)?.id
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
  }, [open, hoveredId, activeId, items])

  // 收起状态下也让当前短横保持在轨道可视范围内
  useLayoutEffect(() => {
    const rail = railRef.current
    const id = activeId ?? hoveredId
    if (!rail || !id) {
      return
    }
    const target = rail.querySelector<HTMLElement>(`[data-nav-id="${CSS.escape(id)}"]`)
    if (!target) {
      return
    }
    const railRect = rail.getBoundingClientRect()
    const targetRect = target.getBoundingClientRect()
    if (targetRect.top < railRect.top || targetRect.bottom > railRect.bottom) {
      rail.scrollTop +=
        targetRect.top - railRect.top - railRect.height / 2 + targetRect.height / 2
    }
  }, [activeId, hoveredId])

  // 点击后在已加载的消息里直接定位，未加载则按窗口加载后定位
  const jump = (messageId: string) => {
    const target = document.querySelector<HTMLElement>(
      `[data-message-id="${CSS.escape(messageId)}"]`
    )
    if (target) {
      target.scrollIntoView({ block: 'start' })
    } else {
      void openSessionAtMessage(session.id, messageId)
    }
    setActiveId(messageId)
    setOpen(false)
  }

  const onBarClick = (messageId: string) => {
    if (isMobile && !open) {
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
          onMouseEnter={() => window.clearTimeout(closeTimer.current)}
        >
          <div className={styles.list}>
            {items.map((item) => (
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
                <span className={styles.itemText}>{textOf(item)}</span>
              </button>
            ))}
          </div>
        </div>
      ) : null}
      <div
        className={styles.rail}
        ref={railRef}
        style={{
          maxHeight: `${
            VISIBLE_BARS * BAR_HEIGHT + (VISIBLE_BARS - 1) * BAR_GAP + RAIL_PADDING
          }px`,
        }}
      >
        {items.map((item) => (
          <button
            key={item.id}
            type="button"
            data-nav-id={item.id}
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
            onClick={() => onBarClick(item.id)}
            aria-label={textOf(item).slice(0, 40)}
          />
        ))}
      </div>
    </div>
  )
}
