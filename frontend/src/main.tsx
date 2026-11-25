import { createRoot } from 'react-dom/client'
import { App } from './app/App.js'
import { setupTheme } from './app/theme.js'
import { ensureKatex } from './services/markdown/katex.js'
import { SharePage } from './components/share/SharePage.js'
import './app/theme.css'
import './app/global.css'

ensureKatex()
setupTheme()

/** /?share={token} 与 /share/{token} 都是独立的只读分享页，其余路径进入主应用 */
const query = new URLSearchParams(window.location.search)
const shareToken =
  query.get('share') ?? /^\/share\/([A-Za-z0-9]+)\/?$/.exec(window.location.pathname)?.[1]

createRoot(document.getElementById('root') as HTMLElement).render(
  shareToken ? <SharePage token={shareToken} /> : <App />
)
