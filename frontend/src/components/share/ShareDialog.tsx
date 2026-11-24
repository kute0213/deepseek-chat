import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslate } from '../../i18n/index.js'
import { createShare, revokeShare, type ShareSummary } from '../../services/chat/shares.js'
import { Button } from '../base/Button.js'
import { Modal } from '../base/Modal.js'
import { showToast } from '../base/toastState.js'
import { copyText } from '../../utils/clipboard.js'
import styles from './ShareDialog.module.css'

const shareUrl = (share: ShareSummary) => `${window.location.origin}${share.url}`

/** 分享当前会话：打开即生成快照链接，可复制、打开分享页或撤销 */
export const ShareDialog = ({
  sessionId,
  onClose,
}: {
  sessionId: string
  onClose: () => void
}) => {
  const t = useTranslate()
  const [share, setShare] = useState<ShareSummary | null>(null)
  const [busy, setBusy] = useState(true)
  const [error, setError] = useState(false)
  const [revoking, setRevoking] = useState(false)
  const creating = useRef(false)

  const create = useCallback(async () => {
    if (creating.current) {
      return
    }
    creating.current = true
    setBusy(true)
    setError(false)
    try {
      setShare(await createShare(sessionId))
    } catch {
      setError(true)
    } finally {
      creating.current = false
      setBusy(false)
    }
  }, [sessionId])

  useEffect(() => {
    void create()
  }, [create])

  const onRevoke = async () => {
    if (!share || revoking) {
      return
    }
    setRevoking(true)
    try {
      await revokeShare(share.token)
      showToast({ key: 'shareRevoked' })
      onClose()
    } catch {
      showToast({ key: 'shareRevokeFailed', type: 'error' })
    } finally {
      setRevoking(false)
    }
  }

  return (
    <Modal
      title={t('shareDialogTitle')}
      closable
      onClose={onClose}
      footer={
        <>
          <Button variant="outlined" onClick={onClose}>
            {t('close')}
          </Button>
          {share ? (
            <Button variant="dangerOutlined" disabled={revoking} onClick={() => void onRevoke()}>
              {t('shareRevoke')}
            </Button>
          ) : null}
        </>
      }
    >
      <p className={styles.description}>{t('shareDialogDescription')}</p>
      {error ? (
        <div className={styles.status}>
          <span className={styles.errorText}>{t('shareCreateFailed')}</span>
          <Button variant="outlined" size="s" onClick={() => void create()}>
            {t('retry')}
          </Button>
        </div>
      ) : busy || !share ? (
        <div className={styles.status} role="status" aria-live="polite">
          <span className={styles.muted}>{t('shareCreating')}</span>
        </div>
      ) : (
        <>
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
          <a
            className={styles.openLink}
            href={share.url}
            target="_blank"
            rel="noopener noreferrer"
          >
            {t('shareOpenLink')}
          </a>
        </>
      )}
    </Modal>
  )
}
