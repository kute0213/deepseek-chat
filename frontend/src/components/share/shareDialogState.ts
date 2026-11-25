import { create } from 'zustand'

/** 全局分享弹窗：会话列表的「更多 → 分享」与聊天页标题栏共用 */
type ShareDialogState = { sessionId: string | null }

export const useShareDialog = create<ShareDialogState>(() => ({ sessionId: null }))

export const openShare = (sessionId: string) => useShareDialog.setState({ sessionId })

export const closeShare = () => useShareDialog.setState({ sessionId: null })
