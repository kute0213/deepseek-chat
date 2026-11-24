import clsx from 'clsx'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useTranslate } from '../../i18n/index.js'
import { IconPanelLeftOutline16, IconSearchOutline16, IconSettingsOutline16, IconUserOutline16, NewChatIcon } from '../base/icons.js'
import { setSiderCollapsed } from '../../services/settings.js'
import { useSite } from '../../services/site.js'
import { useAuth } from '../../services/auth.js'
import { groupSessions } from '../../services/chat/sessionGroups.js'
import { newChat, loadMoreSessions, removeSession, useChatStore } from '../../services/chat/store.js'
import type { SessionSummary } from '../../services/chat/types.js'
import { Button } from '../base/Button.js'
import { IconButton } from '../base/IconButton.js'
import { Modal } from '../base/Modal.js'
import { showToast } from '../base/toastState.js'
import { openSettings } from '../settings/settingsDialogState.js'
import { closeMobileSider, useMobileSider } from './mobileSiderState.js'
import { SessionItem } from './SessionItem.js'
import { SearchDialog } from './SearchDialog.js'
import styles from './Sider.module.css'

/** 展开的侧边栏：名称与收起按钮、开启新对话、按时间分组的会话列表、底部设置；移动端放在抽屉里，收起按钮关闭抽屉 */
export const Sider = ({ mobile = false }: { mobile?: boolean }) => {
  const t = useTranslate()
  const siteName = useSite((state) => state.info?.siteName)
  const username = useAuth((state) => state.user?.username)
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
  const [batchConfirm, setBatchConfirm] = useState(false)
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

  const allSelected = sessions.length > 0 && sessions.every((session) => selected.has(session.id))

  const deleteSelected = async () => {
    setBatchPending(true)
    let failed = 0
    for (const id of selected) {
      if (!(await removeSession(id))) {
        failed += 1
      }
    }
    setBatchPending(false)
    setBatchConfirm(false)
    exitBatch()
    showToast(
      failed > 0
        ? { key: 'batchDeletePartial', type: 'error' }
        : { key: 'batchDeleteDone' }
    )
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
        {username ? (
          <IconButton
            size="m"
            icon={<IconSearchOutline16 />}
            tooltip={t('searchChats')}
            tooltipPlacement="bottom"
            onClick={() => setSearchOpen(true)}
          />
        ) : null}
        <IconButton
          size="m"
          icon={<IconUserOutline16 />}
          tooltip={username ?? t('authLoginRegister')}
          tooltipPlacement="bottom"
          onClick={() => openSettings('account')}
        />
        <IconButton
          size="m"
          icon={<IconPanelLeftOutline16 />}
          tooltip={t('collapseSiderbarButton')}
          tooltipPlacement="bottom"
          onClick={() => (mobile ? closeMobileSider() : setSiderCollapsed(true))}
        />
      </div>
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
      <div className={styles.listWrap}>
        <div ref={listRef} className={styles.list}>
          {groups.map((group) => (
            <div key={group.key} className={styles.section}>
              <div className={styles.sectionHeader}>{group.label}</div>
              {group.sessions.map((session) => (
                <SessionItem
                  key={session.id}
                  session={session}
                  active={session.id === activeId}
                  batch={batching}
                  selected={selected.has(session.id)}
                  onToggle={() => toggleSelected(session.id)}
                  onDelete={() => setDeleting(session)}
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
      {batching ? (
        <div className={styles.batchBar}>
          <button type="button" className={styles.batchLink} onClick={() => {
            setSelected(allSelected ? new Set() : new Set(sessions.map((item) => item.id)))
          }}>
            {allSelected ? t('batchUnselectAll') : t('batchSelectAll')}
          </button>
          <span className={styles.batchCount}>{t('batchSelected', { count: selected.size })}</span>
          <div className={styles.batchActions}>
            <Button
              variant="danger"
              size="s"
              disabled={selected.size === 0 || batchPending}
              onClick={() => setBatchConfirm(true)}
            >
              {t('delete')}
            </Button>
            <button type="button" className={styles.batchLink} onClick={exitBatch}>
              {t('cancel')}
            </button>
          </div>
        </div>
      ) : sessions.length > 0 ? (
        <div className={styles.batchManageRow}>
          <button type="button" className={styles.batchManage} onClick={() => setBatching(true)}>
            {t('batchManage')}
          </button>
        </div>
      ) : null}
      <div className={styles.bottom}>
        <button type="button" className={styles.settings} onClick={() => openSettings('general')}>
          <span className={styles.settingsIcon}>
            <IconSettingsOutline16 />
          </span>
          <span className={styles.settingsLabel}>{t('settings')}</span>
        </button>
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
      {batchConfirm ? (
        <Modal
          title={t('batchDeleteTitle')}
          onClose={() => {
            if (!batchPending) {
              setBatchConfirm(false)
            }
          }}
          footer={
            <>
              <Button
                variant="outlined"
                disabled={batchPending}
                onClick={() => setBatchConfirm(false)}
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
          {t('batchDeleteDescription', { count: selected.size })}
        </Modal>
      ) : null}
      {searchOpen ? <SearchDialog onClose={() => setSearchOpen(false)} /> : null}
    </div>
  )
}

/** 移动端抽屉：浮在页面上方，点空白区域关闭。 */
export const MobileSider = () => {
  const open = useMobileSider((state) => state.open)
  if (!open) {
    return null
  }
  return (
    <>
      <div className={styles.drawerMask} onClick={closeMobileSider} />
      <div className={styles.drawer}>
        <Sider mobile />
      </div>
    </>
  )
}
