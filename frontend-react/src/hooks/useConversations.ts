import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';
import type { Conversation, Message, Ioc } from '@/types/api';

export function useConversations() {
  return useQuery<Conversation[]>({
    queryKey: ['conversations'],
    queryFn: async () => {
      const { data } = await client.get<Conversation[]>(ENDPOINTS.conversations.list);
      return data;
    },
    staleTime: 30_000,
  });
}

export function useConversationMessages(conversationId: string) {
  return useQuery<Message[]>({
    queryKey: ['conversation-messages', conversationId],
    queryFn: async () => {
      const { data } = await client.get<Message[]>(ENDPOINTS.conversations.messages, {
        params: { conversation_id: conversationId },
      });
      return data;
    },
    enabled: !!conversationId,
    staleTime: 10_000,
  });
}

export function useConversationIocs(conversationId: string) {
  return useQuery<Ioc[]>({
    queryKey: ['conversation-iocs', conversationId],
    queryFn: async () => {
      const { data } = await client.get<Ioc[]>(ENDPOINTS.iocs.list, {
        params: { conversation_id: conversationId },
      });
      return data;
    },
    enabled: !!conversationId,
    staleTime: 30_000,
  });
}
