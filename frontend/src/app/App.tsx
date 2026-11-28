import { useEffect, useRef } from 'react'
import clsx from 'clsx'
import { useTranslate } from '../i18n/index.js'
import { useSettings } from '../services/settings.js'
import { initAuth, useAuth } from '../services/auth.js'
import { loadSite, useSite } from '../services/site.js'
import { useIsMobile, useMobileViewport, usePreventPageScroll } from './viewport.js'
import {
  bindChatPersistence,
  initChat,
  newChat,
  openSession,
  retrySaveSessions,
  selectSession,
  useChatStore,
} from '../services/chat/store.js'
import { sessionIdFromPath } from '../services/chat/url.js'
import { SearchPanel } from '../components/search/SearchPanel.js'
import { closeSearchPanel } from '../components/search/searchPanelState.js'
import { SessionView } from '../components/session/SessionView.js'
import { MobileHeader } from '../components/session/MobileHeader.js'
import { Welcome } from '../components/session/Welcome.js'
import { SettingsDialog } from '../components/settings/SettingsDialog.js'
import { ShareDialogHost } from '../components/share/ShareDialog.js'
import { openSettings } from '../components/settings/settingsDialogState.js'
import { Toast } from '../components/base/Toast.js'
import { showToast } from '../components/base/toastState.js'
import { onUploadNotice } from '../services/attachments.js'
import { Button } from '../components/base/Button.js'
import { stopReadAloud } from '../services/speech/readAloud.js'
import { CollapsedSider } from '../components/sider/CollapsedSider.js'
import { closeMobileSider } from '../components/sider/mobileSiderState.js'
import { MobileSider, Sider } from '../components/sider/Sider.js'
import styles from './App.module.css'

export const App = () => {
  const t = useTranslate()
  const ready = useAuth((state) => state.ready)
  const user = useAuth((state) => state.user)
  const siteName = useSite((state) => state.info?.siteName)

  useEffect(() => {
    void loadSite()
    void initAuth()
  }, [])

  useEffect(() => {
    if (siteName) {
      document.title = siteName
    }
  }, [siteName])

  if (!ready) {
    return (
      <div className={styles.boot} role="status" aria-live="polite">
        {t('sessionLoading')}
      </div>
    )
  }
  // 未登录也可以直接进入聊天，发送消息时再引导登录
  return <ChatApp key={user?.id ?? 'guest'} />
}

const ChatApp = () => {
  const t = useTranslate()
  const isMobile = useIsMobile()
  const loggedIn = useAuth((state) => state.user !== null)
  const locale = useSettings((state) => state.locale)
  // 移动端侧边栏以独立抽屉管理开合状态
  const siderCollapsed = useSettings((state) => state.siderCollapsed) && !isMobile
  const ready = useChatStore((state) => state.ready)
  const initError = useChatStore((state) => state.initError)
  const saveError = useChatStore((state) => Object.values(state.sessionSaveErrors).some(Boolean))
  const importingHistory = useChatStore((state) => state.importingHistory)
  const session = useChatStore((state) =>
    state.activeId !== null && Object.hasOwn(state.sessionCache, state.activeId)
      ? state.sessionCache[state.activeId]
      : undefined
  )
  const sessionLoadError = useChatStore((state) =>
    state.activeId === null ? false : state.sessionLoadErrors[state.activeId] === true
  )
  const frameRef = useMobileViewport(isMobile)
  const streaming = useChatStore((state) =>
    state.activeId === null ? false : Object.hasOwn(state.streaming, state.activeId)
  )
  const activeId = useChatStore((state) => state.activeId)

  usePreventPageScroll(isMobile)

  useEffect(() => {
    document.documentElement.lang = locale
  }, [locale])

  // 文件数、存储空间达到上限时，附件上只显示「上传失败」，原因用 toast 说明
  useEffect(() => onUploadNotice(showToast), [])
  useEffect(bindChatPersistence, [])

  // 搜索结果面板只属于当前会话
  useEffect(closeSearchPanel, [activeId])
  // 切换会话时停止朗读
  useEffect(stopReadAloud, [activeId])
  // 移动端选中会话、切换布局后收起抽屉
  useEffect(closeMobileSider, [activeId, isMobile])

  useEffect(() => {
    void initChat(loggedIn)
  }, [loggedIn])

  // 分享页未登录时点击「继续对话」会带 ?login=1 回到主应用，直接打开登录面板
  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    if (params.get('login') !== '1') {
      return
    }
    openSettings('account')
    params.delete('login')
    const search = params.toString()
    window.history.replaceState(
      null,
      '',
      window.location.pathname + (search ? `?${search}` : '') + window.location.hash
    )
  }, [])

  // 分享页「继续对话」会带着 ?session= 回到主应用，加载完成后直接打开该会话
  const openedSessionRef = useRef(false)
  useEffect(() => {
    if (!ready || openedSessionRef.current) {
      return
    }
    const target = new URLSearchParams(window.location.search).get('session')
    if (!target) {
      return
    }
    openedSessionRef.current = true
    if (useChatStore.getState().sessions.some((item) => item.id === target)) {
      selectSession(target)
    }
    window.history.replaceState(null, '', window.location.pathname + window.location.hash)
  }, [ready])

  // 地址栏 /{会话ID}：首次进入或浏览器前进/后退时打开对应会话，回到 / 时展示欢迎页
  const restoredFromPathRef = useRef(false)
  useEffect(() => {
    if (!ready || !loggedIn || restoredFromPathRef.current) {
      return
    }
    restoredFromPathRef.current = true
    const id = sessionIdFromPath()
    if (id) {
      void openSession(id)
    }
  }, [ready, loggedIn])

  useEffect(() => {
    const onPopState = () => {
      const id = sessionIdFromPath()
      if (!id) {
        newChat()
        return
      }
      if (loggedIn) {
        void openSession(id)
      }
    }
    window.addEventListener('popstate', onPopState)
    return () => window.removeEventListener('popstate', onPopState)
  }, [loggedIn])

  return (
    <>
      <div ref={frameRef} className={styles.frame}>
        {isMobile ? (
          <MobileSider />
        ) : (
          <div
            className={clsx(styles.siderSlot, siderCollapsed && styles.siderSlotCollapsed)}
            aria-hidden={siderCollapsed}
          >
            <Sider />
          </div>
        )}
        <main className={styles.main}>
          {saveError ? (
            <div className={styles.saveError} role="alert">
              <span>{t('sessionSaveFailed')}</span>
              <Button
                variant="outlined"
                size="s"
                disabled={importingHistory}
                onClick={retrySaveSessions}
              >
                {t('retry')}
              </Button>
            </div>
          ) : null}
          <div className={styles.content}>
            {siderCollapsed ? <CollapsedSider /> : null}
            {!ready || (activeId !== null && !session) ? (
              <div className={styles.loadingView}>
                {isMobile ? <MobileHeader /> : null}
                <div className={styles.loadingState} role="status" aria-live="polite">
                  <span>
                    {(!ready ? initError : sessionLoadError)
                      ? t(!ready ? 'sessionListLoadFailed' : 'sessionLoadFailed')
                      : t('sessionLoading')}
                  </span>
                  {(!ready ? initError : sessionLoadError) ? (
                    <Button
                      variant="outlined"
                      size="m"
                      onClick={() => {
                        if (!ready) {
                          void initChat(loggedIn)
                        } else if (activeId !== null) {
                          selectSession(activeId)
                        }
                      }}
                    >
                      {t('retry')}
                    </Button>
                  ) : null}
                </div>
              </div>
            ) : session ? (
              <SessionView
                session={session}
                streaming={streaming}
                siderCollapsed={siderCollapsed}
              />
            ) : (
              <Welcome />
            )}
          </div>
        </main>
        <SearchPanel />
      </div>
      <ShareDialogHost />
      <SettingsDialog />
      <Toast />
    </>
  )
}
