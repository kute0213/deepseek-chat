import { useState } from 'react'
import { login, register } from '../../services/auth.js'
import { useSite } from '../../services/site.js'
import { useTranslate } from '../../i18n/index.js'
import { Button } from '../base/Button.js'
import styles from './AuthScreen.module.css'

type Mode = 'login' | 'register'

/** 未登录时的入口：登录 / 注册，站点名称与公告来自服务端 */
export const AuthScreen = () => {
  const t = useTranslate()
  const site = useSite((state) => state.info)
  const [mode, setMode] = useState<Mode>('login')
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const allowRegister = site?.allowRegister ?? false

  const submit = async () => {
    if (busy) {
      return
    }
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
    setBusy(true)
    try {
      if (mode === 'login') {
        await login(name, password)
      } else {
        await register(name, password)
      }
    } catch (cause) {
      setError(cause instanceof Error && cause.message ? cause.message : t('authFailed'))
      setBusy(false)
    }
  }

  const switchMode = (next: Mode) => {
    setMode(next)
    setError(null)
    setConfirm('')
  }

  return (
    <div className={styles.page}>
      <div className={styles.card}>
        <div className={styles.brand}>{site?.siteName ?? t('appName')}</div>
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
            void submit()
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
          {error ? (
            <div className={styles.error} role="alert">
              {error}
            </div>
          ) : null}
          <Button type="submit" size="l" className={styles.submit} disabled={busy}>
            {busy ? t('authSubmitting') : mode === 'login' ? t('authLogin') : t('authRegister')}
          </Button>
        </form>
        <div className={styles.footer}>{t('contentCaveat')}</div>
      </div>
    </div>
  )
}
