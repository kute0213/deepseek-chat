import { create } from 'zustand'

/** 聊天页的分享选择模式：点击分享后进入，消息左侧出现复选框 */
type ShareSelectionState = { sessionId: string | null }

export const useShareSelection = create<ShareSelectionState>(() => ({ sessionId: null }))

export const startShareSelection = (sessionId: string) =>
  useShareSelection.setState({ sessionId })

export const stopShareSelection = () => useShareSelection.setState({ sessionId: null })
