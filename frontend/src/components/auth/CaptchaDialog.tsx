import { useEffect, useState } from 'react'
import { requestJson } from '../../services/http.js'
import { useTranslate } from '../../i18n/index.js'
import { Button } from '../base/Button.js'
import { Modal } from '../base/Modal.js'
import styles from './CaptchaDialog.module.css'

type CaptchaData = { id: string; image: string }

/**
 * 图形验证码弹窗：登录与注册共用。
 * 挂载时生成验证码，提交失败后刷新重试；生成 / 校验 / 销毁统一走服务端 Captcha 服务。
 */
export const CaptchaDialog = ({
  onSubmit,
  onSuccess,
  onClose,
}: {
  /** 携带验证码提交登录或注册；抛错时在弹窗内提示并刷新验证码 */
  onSubmit: (captchaId: string, captchaCode: string) => Promise<void>
  onSuccess: () => void
  onClose: () => void
}) => {
  const t = useTranslate()
  const [captcha, setCaptcha] = useState<CaptchaData | null>(null)
  const [code, setCode] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const loadCaptcha = async () => {
    try {
      const data = await requestJson<CaptchaData>('/api/auth/captcha', 'GET')
      setCaptcha(data)
      setCode('')
    } catch {
      setCaptcha(null)
    }
  }

  useEffect(() => {
    void loadCaptcha()
  }, [])

  const confirm = async () => {
    if (busy) {
      return
    }
    setError(null)
    if (!captcha || code.trim().length !== 4) {
      setError(t('authCaptchaRequired'))
      return
    }
    setBusy(true)
    try {
      await onSubmit(captcha.id, code.trim())
      onSuccess()
    } catch (cause) {
      setError(cause instanceof Error && cause.message ? cause.message : t('authFailed'))
      setBusy(false)
      // 验证码验证后即失效，失败时刷新
      void loadCaptcha()
    }
  }

  return (
    <Modal
      title={t('captchaDialogTitle')}
      onClose={() => {
        if (!busy) {
          onClose()
        }
      }}
      footer={
        <>
          <Button variant="outlined" disabled={busy} onClick={onClose}>
            {t('cancel')}
          </Button>
          <Button disabled={busy} onClick={() => void confirm()}>
            {t(busy ? 'authSubmitting' : 'captchaDialogConfirm')}
          </Button>
        </>
      }
    >
      <div className={styles.description}>{t('captchaDialogDescription')}</div>
      <div className={styles.captchaRow}>
        <input
          className={styles.input}
          placeholder={t('authCaptchaPlaceholder')}
          autoComplete="off"
          spellCheck={false}
          maxLength={4}
          autoFocus
          value={code}
          onChange={(event) => setCode(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === 'Enter' && !event.nativeEvent.isComposing) {
              void confirm()
            }
          }}
        />
        <button
          type="button"
          className={styles.captchaRefresh}
          title={t('authCaptchaRefresh')}
          aria-label={t('authCaptchaRefresh')}
          onClick={() => void loadCaptcha()}
        >
          {captcha ? (
            <img src={captcha.image} alt={t('authCaptchaLabel')} />
          ) : (
            <span>{t('authCaptchaRefresh')}</span>
          )}
        </button>
      </div>
      {error ? (
        <div className={styles.error} role="alert">
          {error}
        </div>
      ) : null}
    </Modal>
  )
}
