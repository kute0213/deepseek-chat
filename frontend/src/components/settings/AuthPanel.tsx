import { useState } from 'react'
import { login, register } from '../../services/auth.js'
import { useSite } from '../../services/site.js'
import { useTranslate } from '../../i18n/index.js'
import { CaptchaDialog } from '../auth/CaptchaDialog.js'
import { Button } from '../base/Button.js'
import { showToast } from '../base/toastState.js'
import { closeSettings } from './settingsDialogState.js'
import styles from './AuthPanel.module.css'

type Mode = 'login' | 'register'

/** 设置里的登录 / 注册：游客可直接进入聊天，发送消息时再引导到这里 */
export const AuthPanel = () => {
  const t = useTranslate()
  const site = useSite((state) => state.info)
  const [mode, setMode] = useState<Mode>('login')
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [agreed, setAgreed] = useState(false)
  const [captchaOpen, setCaptchaOpen] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // 站点信息加载失败时也保留注册入口，是否开放由服务端最终校验
  const allowRegister = site?.allowRegister ?? true

  const submit = () => {
    const name = username.trim()
    setError(null)
    if (!name || !password) {
      setError(t('authRequired'))
      return
    }
    if (mode === 'register') {
      if (!allowRegister) {
        setError(t('authRegisterClosed'))
        return
      }
      if (password.length < 8) {
        setError(t('authPasswordTooShort'))
        return
      }
      if (password !== confirm) {
        setError(t('authPasswordMismatch'))
        return
      }
    }
    if (!agreed) {
      setError(t('authAgreementRequired'))
      return
    }
    // 表单校验通过后弹出共用的图形验证码弹窗
    setCaptchaOpen(true)
  }

  const switchMode = (next: Mode) => {
    setMode(next)
    setError(null)
    setConfirm('')
  }

  return (
    <div className={styles.panel}>
      <div className={styles.intro}>{t('authPanelIntro')}</div>
      <div className={styles.tabs} role="tablist">
        <button
          type="button"
          role="tab"
          aria-selected={mode === 'login'}
          className={mode === 'login' ? styles.tabActive : styles.tab}
          onClick={() => switchMode('login')}
        >
          {t('authLogin')}
        </button>
        {allowRegister ? (
          <button
            type="button"
            role="tab"
            aria-selected={mode === 'register'}
            className={mode === 'register' ? styles.tabActive : styles.tab}
            onClick={() => switchMode('register')}
          >
            {t('authRegister')}
          </button>
        ) : null}
      </div>
      {site?.announcement ? <div className={styles.announcement}>{site.announcement}</div> : null}
      <form
        className={styles.form}
        onSubmit={(event) => {
          event.preventDefault()
          submit()
        }}
      >
        <input
          className={styles.input}
          placeholder={t('authUsername')}
          autoComplete="username"
          spellCheck={false}
          value={username}
          onChange={(event) => setUsername(event.target.value)}
        />
        <input
          className={styles.input}
          type="password"
          placeholder={t('authPassword')}
          autoComplete={mode === 'login' ? 'current-password' : 'new-password'}
          value={password}
          onChange={(event) => setPassword(event.target.value)}
        />
        {mode === 'register' ? (
          <input
            className={styles.input}
            type="password"
            placeholder={t('authConfirmPassword')}
            autoComplete="new-password"
            value={confirm}
            onChange={(event) => setConfirm(event.target.value)}
          />
        ) : null}
        <label className={styles.agreement}>
          <input
            type="checkbox"
            checked={agreed}
            onChange={(event) => setAgreed(event.target.checked)}
          />
          <span>
            {t('authAgreementPrefix')}
            <a href="/terms.html" target="_blank" rel="noopener noreferrer">
              {t('authAgreementLink')}
            </a>
          </span>
        </label>
        {error ? (
          <div className={styles.error} role="alert">
            {error}
          </div>
        ) : null}
        <Button type="submit" size="l" className={styles.submit}>
          {mode === 'login' ? t('authLogin') : t('authRegister')}
        </Button>
      </form>
      <div className={styles.footer}>{t('contentCaveat')}</div>
      {captchaOpen ? (
        <CaptchaDialog
          onClose={() => setCaptchaOpen(false)}
          onSubmit={async (captchaId, captchaCode) => {
            const name = username.trim()
            if (mode === 'login') {
              await login(name, password, captchaId, captchaCode)
            } else {
              await register(name, password, captchaId, captchaCode)
            }
          }}
          onSuccess={() => {
            showToast({ key: mode === 'login' ? 'authLoginSuccess' : 'authRegisterSuccess' })
            closeSettings()
          }}
        />
      ) : null}
    </div>
  )
}
