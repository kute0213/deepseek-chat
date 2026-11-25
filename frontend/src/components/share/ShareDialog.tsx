import clsx from 'clsx'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useTranslate } from '../../i18n/index.js'
import { getVisibleMessages } from '../../services/chat/branches.js'
import { loadSession } from '../../services/chat/db.js'
import { createShare, revokeShare, type ShareSummary } from '../../services/chat/shares.js'
import { useChatStore } from '../../services/chat/store.js'
import type { Message } from '../../services/chat/types.js'
import { copyText } from '../../utils/clipboard.js'
import { Button } from '../base/Button.js'
import { IconCheckOutline16 } from '../base/icons.js'
import { Modal } from '../base/Modal.js'
import { showToast } from '../base/toastState.js'
import { closeShare, useShareDialog } from './shareDialogState.js'
import styles from './ShareDialog.module.css'

const shareUrl = (share: ShareSummary) => `${window.location.origin}${share.url}`

const previewOf = (message: Message) => {
  const text = message.content.trim()
  if (text) {
    return text
  }
  return message.files.length > 0 ? `[${message.files.length} 个附件]` : ''
}

/** 分享会话：必须先勾选一条或多条消息，只生成所选内容的只读快照 */
export const ShareDialog = ({ sessionId, onClose }: { sessionId: string; onClose: () => void }) => {
  const t = useTranslate()
  const cached = useChatStore((state) => state.sessionCache[sessionId])
  const [messages, setMessages] = useState<Message[] | null>(() =>
    cached ? getVisibleMessages(cached) : null
  )
  const [selected, setSelected] = useState<Set<string>>(() => new Set())
  const [share, setShare] = useState<ShareSummary | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(false)
  const creating = useRef(false)

  useEffect(() => {
    if (messages !== null) {
      return
    }
    void loadSession(sessionId)
      .then((session) => setMessages(session ? getVisibleMessages(session) : []))
      .catch(() => setMessages([]))
  }, [messages, sessionId])

  const questions = useMemo(
    () => (messages ?? []).filter((message) => message.role === 'user'),
    [messages]
  )

  const toggle = (id: string) => {
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

  const create = useCallback(async () => {
    if (creating.current || selected.size === 0) {
      return
    }
    creating.current = true
    setBusy(true)
    setError(false)
    try {
      setShare(await createShare(sessionId, Array.from(selected)))
    } catch {
      setError(true)
    } finally {
      creating.current = false
      setBusy(false)
    }
  }, [selected, sessionId])

  const onRevoke = async () => {
    if (!share) {
      return
    }
    try {
      await revokeShare(share.token)
      showToast({ key: 'shareRevoked' })
      onClose()
    } catch {
      showToast({ key: 'shareRevokeFailed', type: 'error' })
    }
  }

  return (
    <Modal
      title={t('shareSelectTitle')}
      closable
      onClose={onClose}
      footer={
        share ? (
          <>
            <Button variant="outlined" onClick={onClose}>
              {t('close')}
            </Button>
            <Button variant="dangerOutlined" onClick={() => void onRevoke()}>
              {t('shareRevoke')}
            </Button>
          </>
        ) : (
          <>
            <Button variant="outlined" onClick={onClose}>
              {t('cancel')}
            </Button>
            <Button
              variant="primary"
              disabled={busy || selected.size === 0}
              onClick={() => void create()}
            >
              {t('shareCreate')}
            </Button>
          </>
        )
      }
    >
      {share ? (
        <>
          <p className={styles.description}>{t('shareDialogDescription')}</p>
          <div className={styles.linkRow}>
            <input className={styles.url} value={shareUrl(share)} readOnly spellCheck={false} />
            <Button
              variant="outlined"
              size="m"
              onClick={async () => {
                if (await copyText(shareUrl(share))) {
                  showToast({ key: 'shareLinkCopied' })
                } else {
                  showToast({ key: 'copyFailed', type: 'error' })
                }
              }}
            >
              {t('shareCopyLink')}
            </Button>
          </div>
          <a className={styles.openLink} href={share.url} target="_blank" rel="noopener noreferrer">
            {t('shareOpenLink')}
          </a>
        </>
      ) : (
        <>
          <p className={styles.description}>{t('shareSelectHint')}</p>
          {error ? (
            <div className={styles.status}>
              <span className={styles.errorText}>{t('shareCreateFailed')}</span>
            </div>
          ) : null}
          {messages === null ? (
            <div className={styles.status} role="status" aria-live="polite">
              <span className={styles.muted}>{t('sessionLoading')}</span>
            </div>
          ) : questions.length === 0 ? (
            <div className={styles.status}>
              <span className={styles.muted}>{t('shareSelectEmpty')}</span>
            </div>
          ) : (
            <div className={styles.selectList}>
              {questions.map((message) => (
                <button
                  key={message.id}
                  type="button"
                  className={clsx(
                    styles.selectItem,
                    selected.has(message.id) && styles.selectItemChecked
                  )}
                  onClick={() => toggle(message.id)}
                >
                  <span
                    className={clsx(
                      styles.checkbox,
                      selected.has(message.id) && styles.checkboxChecked
                    )}
                    aria-hidden
                  >
                    {selected.has(message.id) ? <IconCheckOutline16 /> : null}
                  </span>
                  <span className={styles.selectText}>{previewOf(message)}</span>
                </button>
              ))}
            </div>
          )}
        </>
      )}
    </Modal>
  )
}

/** 全局挂载点：会话列表与聊天页都通过 openShare 打开同一个弹窗 */
export const ShareDialogHost = () => {
  const sessionId = useShareDialog((state) => state.sessionId)
  if (sessionId === null) {
    return null
  }
  return <ShareDialog key={sessionId} sessionId={sessionId} onClose={closeShare} />
}
