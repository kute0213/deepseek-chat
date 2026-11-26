import clsx from 'clsx'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useTranslate } from '../../i18n/index.js'
import {
  IconCloseOutline16,
  IconPanelLeftOutline16,
  IconSearchOutline16,
  IconSettingsOutline16,
  IconSliderOutline16,
  NewChatIcon,
} from '../base/icons.js'
import { setSiderCollapsed } from '../../services/settings.js'
import { useSite } from '../../services/site.js'
import { groupSessions } from '../../services/chat/sessionGroups.js'
import {
  loadMoreSessions,
  newChat,
  removeSession,
  selectSession,
  togglePinned,
  useChatStore,
} from '../../services/chat/store.js'
import type { SessionSummary } from '../../services/chat/types.js'
import { Button } from '../base/Button.js'
import { IconButton } from '../base/IconButton.js'
import { Modal } from '../base/Modal.js'
import { showToast } from '../base/toastState.js'
import { openSettings } from '../settings/settingsDialogState.js'
import { startShareSelection } from '../../services/chat/shareSelection.js'
import { closeMobileSider, useMobileSider } from './mobileSiderState.js'
import { SessionItem } from './SessionItem.js'
import { SearchDialog } from './SearchDialog.js'
import styles from './Sider.module.css'

/** 展开的侧边栏：名称与收起 / 搜索按钮、开启新对话、按时间分组的会话列表、底部设置；移动端放在抽屉里 */
export const Sider = ({ mobile = false }: { mobile?: boolean }) => {
  const t = useTranslate()
  const siteName = useSite((state) => state.info?.siteName)
  const sessions = useChatStore((state) => state.sessions)
  const activeId = useChatStore((state) => state.activeId)
  const hasMore = useChatStore((state) => state.nextCursor !== null)
  const loadingMore = useChatStore((state) => state.loadingMore)
  const listRef = useRef<HTMLDivElement>(null)
  const sentinelRef = useRef<HTMLDivElement>(null)
  const [now, setNow] = useState(Date.now)
  const groups = useMemo(() => groupSessions(sessions, now, t), [sessions, now, t])
  const [deleting, setDeleting] = useState<SessionSummary | null>(null)
  const [deletePending, setDeletePending] = useState(false)
  const [searchOpen, setSearchOpen] = useState(false)
  const [batching, setBatching] = useState(false)
  const [selected, setSelected] = useState<Set<string>>(() => new Set())
  const [batchDeleteConfirm, setBatchDeleteConfirm] = useState(false)
  const [batchPending, setBatchPending] = useState(false)

  const exitBatch = () => {
    setBatching(false)
    setSelected(new Set())
  }

  const toggleSelected = (id: string) => {
    setSelected((previous) => {
      const next = new Set(previous)
      if (next.has(id)) {
        next.delete(id)
      } else {
        next.add(id)
      }
      return next
    })
  }

  const selectedSessions = sessions.filter((session) => selected.has(session.id))
  const allPinned = selectedSessions.length > 0 && selectedSessions.every((session) => session.pinned)
  const deferDelete = sessions.filter((session) => selected.has(session.id) && session.id !== activeId)

  const batchPin = () => {
    if (selected.size === 0) {
      return
    }
    for (const session of selectedSessions) {
      togglePinned(session.id, !allPinned)
    }
    exitBatch()
    showToast({ key: allPinned ? 'sessionItemMenuUnpinOption' : 'batchPinAction' })
  }

  const deleteSelected = async () => {
    setBatchPending(true)
    let failed = 0
    for (const session of deferDelete) {
      if (!(await removeSession(session.id))) {
        failed += 1
      }
    }
    setBatchPending(false)
    setBatchDeleteConfirm(false)
    exitBatch()
    showToast(failed > 0 ? { key: 'batchDeletePartial', type: 'error' } : { key: 'batchDeleteDone' })
  }

  useEffect(() => {
    let timer: ReturnType<typeof setTimeout>
    const refresh = () => {
      clearTimeout(timer)
      const time = Date.now()
      setNow(time)
      const midnight = new Date(time).setHours(24, 0, 0, 0)
      timer = setTimeout(refresh, midnight - time)
    }
    const onVisibilityChange = () => {
      if (document.visibilityState === 'visible') {
        refresh()
      }
    }
    refresh()
    document.addEventListener('visibilitychange', onVisibilityChange)
    window.addEventListener('focus', refresh)
    return () => {
      clearTimeout(timer)
      document.removeEventListener('visibilitychange', onVisibilityChange)
      window.removeEventListener('focus', refresh)
    }
  }, [])

  // 列表滚动到底部时自动加载下一页摘要；换页后会话数量变化会重新观察
  useEffect(() => {
    const sentinel = sentinelRef.current
    if (!sentinel || !hasMore) {
      return
    }
    const observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          void loadMoreSessions()
        }
      },
      { root: listRef.current, rootMargin: '160px' }
    )
    observer.observe(sentinel)
    return () => observer.disconnect()
  }, [hasMore, sessions.length])

  return (
    <div className={clsx(styles.sider, mobile && styles.mobile)}>
      <div className={styles.top}>
        <div className={styles.appName}>{siteName ?? t('appName')}</div>
        <div className={styles.topActions}>
          <IconButton
            size="m"
            icon={<IconPanelLeftOutline16 />}
            tooltip={t('collapseSiderbarButton')}
            tooltipPlacement="bottom"
            onClick={() => (mobile ? closeMobileSider() : setSiderCollapsed(true))}
          />
          <IconButton
            size="m"
            icon={<IconSearchOutline16 />}
            tooltip={t('searchChats')}
            tooltipPlacement="bottom"
            onClick={() => setSearchOpen(true)}
          />
        </div>
      </div>
      {batching ? (
        <div className={styles.selectHeader}>
          <span className={styles.selectTitle}>{t('batchSelectTitle')}</span>
          <IconButton
            size="m"
            icon={<IconCloseOutline16 />}
            aria-label={t('batchExit')}
            onClick={exitBatch}
          />
        </div>
      ) : (
        <button
          type="button"
          className={styles.newChat}
          onClick={() => {
            newChat()
            closeMobileSider()
          }}
        >
          <span className={styles.newChatIcon}>
            <NewChatIcon />
          </span>
          {t('newChat')}
        </button>
      )}
      <div className={styles.listWrap}>
        <div ref={listRef} className={clsx(styles.list, batching && styles.listBatch)}>
          {groups.map((group, groupIndex) => (
            <div key={group.key} className={styles.section}>
              <div className={styles.sectionHeader}>
                <span>{group.label}</span>
                {groupIndex === 0 && !batching && sessions.length > 0 ? (
                  <IconButton
                    size="xs"
                    icon={<IconSliderOutline16 />}
                    tooltip={t('batchManage')}
                    tooltipPlacement="bottom"
                    className={styles.manageButton}
                    onClick={() => setBatching(true)}
                  />
                ) : null}
              </div>
              {group.sessions.map((session) => (
                <SessionItem
                  key={session.id}
                  session={session}
                  active={session.id === activeId}
                  batch={batching}
                  selected={selected.has(session.id)}
                  onToggle={() => toggleSelected(session.id)}
                  onDelete={() => setDeleting(session)}
                  onShare={() => {
                    selectSession(session.id)
                    closeMobileSider()
                    startShareSelection(session.id)
                  }}
                  onBatchSelect={() => {
                    setBatching(true)
                    setSelected(new Set([session.id]))
                  }}
                />
              ))}
            </div>
          ))}
          {hasMore ? (
            <div ref={sentinelRef} className={styles.loadMore}>
              {loadingMore ? t('sessionListLoadingMore') : null}
            </div>
          ) : null}
        </div>
      </div>
      <div className={styles.bottom}>
        {batching ? (
          <div className={styles.batchBar}>
            <button
              type="button"
              className={styles.batchAction}
              disabled={selected.size === 0}
              onClick={batchPin}
            >
              {allPinned ? t('batchUnpinAction') : t('batchPinAction')}
            </button>
            <button
              type="button"
              className={clsx(styles.batchAction, styles.batchDanger)}
              disabled={selected.size === 0 || batchPending}
              onClick={() => setBatchDeleteConfirm(true)}
            >
              {t('delete')}
            </button>
          </div>
        ) : (
          <button type="button" className={styles.settings} onClick={() => openSettings('general')}>
            <span className={styles.settingsIcon}>
              <IconSettingsOutline16 />
            </span>
            <span className={styles.settingsLabel}>{t('settings')}</span>
          </button>
        )}
      </div>
      {deleting ? (
        <Modal
          title={t('deleteSessionModalContentTitle')}
          onClose={() => {
            if (!deletePending) {
              setDeleting(null)
            }
          }}
          footer={
            <>
              <Button variant="outlined" disabled={deletePending} onClick={() => setDeleting(null)}>
                {t('cancel')}
              </Button>
              <Button
                variant="danger"
                disabled={deletePending}
                onClick={async () => {
                  setDeletePending(true)
                  try {
                    if (await removeSession(deleting.id)) {
                      setDeleting(null)
                    } else {
                      showToast({ key: 'sessionDeleteFailed', type: 'error' })
                    }
                  } finally {
                    setDeletePending(false)
                  }
                }}
              >
                {t('delete')}
              </Button>
            </>
          }
        >
          {t('deleteSessionWithFilesDescription')}
        </Modal>
      ) : null}
      {batchDeleteConfirm ? (
        <Modal
          title={t('batchDeleteTitle')}
          onClose={() => {
            if (!batchPending) {
              setBatchDeleteConfirm(false)
            }
          }}
          footer={
            <>
              <Button
                variant="outlined"
                disabled={batchPending}
                onClick={() => setBatchDeleteConfirm(false)}
              >
                {t('cancel')}
              </Button>
              <Button
                variant="danger"
                disabled={batchPending}
                onClick={() => void deleteSelected()}
              >
                {t('delete')}
              </Button>
            </>
          }
        >
          {t('batchDeleteDescription', { count: deferDelete.length })}
        </Modal>
      ) : null}
      {searchOpen ? <SearchDialog onClose={() => setSearchOpen(false)} /> : null}
    </div>
  )
}

/** 移动端抽屉：遮罩 + 平滑滑入的侧边栏，展开时带投影 */
export const MobileSider = () => {
  const open = useMobileSider((state) => state.open)
  return (
    <>
      <div
        className={clsx(styles.drawerMask, open && styles.drawerMaskOpen)}
        onClick={closeMobileSider}
        aria-hidden
      />
      <div
        className={clsx(styles.drawer, open && styles.drawerOpen)}
        aria-hidden={!open}
      >
        <Sider mobile />
      </div>
    </>
  )
}
