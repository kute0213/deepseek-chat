import { create } from 'zustand'

export type MessageFocusTarget = { sessionId: string; messageId: string }

/** 搜索、分享等入口跳转到某条消息时使用的目标；渲染后由消息列表清除。 */
type MessageFocusState = { target: MessageFocusTarget | null }

export const useMessageFocus = create<MessageFocusState>(() => ({ target: null }))

export const focusMessage = (sessionId: string, messageId: string) => {
  useMessageFocus.setState({ target: { sessionId, messageId } })
}

export const clearMessageFocus = () => useMessageFocus.setState({ target: null })
