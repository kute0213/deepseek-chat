import { useEffect, useState } from 'react'
import { useTranslate } from '../../i18n/index.js'
import { initAuth, useAuth } from '../../services/auth.js'
import { forkShare, loadShare, type ShareMessage, type ShareView } from '../../services/chat/shares.js'
import { loadSite, useSite } from '../../services/site.js'
import { useSettings } from '../../services/settings.js'
import { Button } from '../base/Button.js'
import { showToast } from '../base/toastState.js'
import { Markdown } from '../markdown/Markdown.js'
import styles from './SharePage.module.css'

const formatTime = (ms: number, locale: string) =>
  new Date(ms).toLocaleString(locale, {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  })

const formatSize = (bytes: number) => {
  if (bytes < 1024) {
    return `${bytes} B`
  }
  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(1)} KB`
  }
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

const UserBlock = ({ message }: { message: ShareMessage }) => {
  const t = useTranslate()
  return (
    <div className={styles.userRow}>
      <div className={styles.userBubble}>
        {message.content ? <div className={styles.userText}>{message.content}</div> : null}
        {message.files && message.files.length > 0 ? (
          <div className={styles.files}>
            {message.files.map((file, index) => (
              <span key={index} className={styles.file}>
                {file.isImage ? t('imageLabel') : t('fileLabel')}：{file.name || t('attachmentLabel')}
                <span className={styles.fileSize}>{formatSize(file.size)}</span>
              </span>
            ))}
          </div>
        ) : null}
      </div>
    </div>
  )
}

const AssistantBlock = ({ message }: { message: ShareMessage }) => (
  <div className={styles.assistantRow}>
    <Markdown
      className="ds-assistant-message-main-content"
      content={message.text ?? ''}
      loading={false}
      cacheKey={message.id}
      disableLink={false}
    />
  </div>
)

/** 公开分享页：只读展示会话快照，登录后可复制到自己的账号继续对话 */
export const SharePage = ({ token }: { token: string }) => {
  const t = useTranslate()
  const siteName = useSite((state) => state.info?.siteName)
  const user = useAuth((state) => state.user)
  const locale = useSettings((state) => state.locale)
  const [share, setShare] = useState<ShareView | null>(null)
  const [failed, setFailed] = useState(false)
  const [continuing, setContinuing] = useState(false)

  useEffect(() => {
    void loadSite()
    void initAuth()
    void loadShare(token)
      .then(setShare)
      .catch(() => setFailed(true))
  }, [token])

  useEffect(() => {
    if (share) {
      document.title = `${share.title || t('untitledChat')} - ${siteName ?? t('appName')}`
    } else if (failed) {
      document.title = `${t('shareLoadFailed')} - ${siteName ?? t('appName')}`
    }
  }, [share, failed, siteName, t])

  const onContinue = async () => {
    if (!user) {
      window.location.assign('/?login=1')
      return
    }
    if (continuing) {
      return
    }
    setContinuing(true)
    try {
      const created = await forkShare(token)
      window.location.assign(`/?session=${encodeURIComponent(created.sessionId)}`)
    } catch {
      setContinuing(false)
      showToast({ key: 'shareContinueFailed', type: 'error' })
    }
  }

  return (
    <div className={styles.page}>
      <header className={styles.topbar}>
        <a className={styles.brand} href="/">
          {siteName ?? t('appName')}
        </a>
        <a className={styles.openApp} href="/">
          {t('shareOpenApp')}
        </a>
      </header>
      <main className={styles.main}>
        {failed ? (
          <div className={styles.state} role="alert">
            {t('shareLoadFailed')}
          </div>
        ) : !share ? (
          <div className={styles.state} role="status" aria-live="polite">
            {t('sessionLoading')}
          </div>
        ) : (
          <>
            <div className={styles.headline}>
              <h1 className={styles.title}>{share.title || t('untitledChat')}</h1>
              <div className={styles.meta}>
                {t('sharePageOwner', { name: share.owner })}
                <span className={styles.dot}>·</span>
                {formatTime(share.createdAt, locale)}
              </div>
            </div>
            <div className={styles.messages}>
              {share.messages.map((message) =>
                message.role === 'user' ? (
                  <UserBlock key={message.id} message={message} />
                ) : (
                  <AssistantBlock key={message.id} message={message} />
                )
              )}
            </div>
            <div className={styles.actions}>
              <Button variant="primary" disabled={continuing} onClick={() => void onContinue()}>
                {user ? t('shareContinue') : t('shareLoginToContinue')}
              </Button>
            </div>
          </>
        )}
      </main>
    </div>
  )
}
