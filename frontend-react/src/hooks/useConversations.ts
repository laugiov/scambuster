import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';
import type { Conversation, Message, Ioc } from '@/types/api';

const PAGE_SIZE = 50;

export function useConversations(page = 1) {
  return useQuery<Conversation[]>({
    queryKey: ['conversations', page],
    queryFn: async () => {
      const { data } = await client.get<Conversation[]>(ENDPOINTS.conversations.list, {
        params: { page, limit: PAGE_SIZE },
      });
      return data;
    },
    staleTime: 30_000,
  });
}

export function useConversationDetail(conversationId: string) {
  return useQuery<Conversation>({
    queryKey: ['conversation', conversationId],
    queryFn: async () => {
      const { data } = await client.get<Conversation>(ENDPOINTS.conversations.detail(conversationId));
      return data;
    },
    enabled: !!conversationId,
    staleTime: 10_000,
  });
}

export function useConversationMessages(conversationId: string) {
  return useQuery<Message[]>({
    queryKey: ['conversation-messages', conversationId],
    queryFn: async () => {
      const { data } = await client.get<Message[]>(ENDPOINTS.conversations.messages(conversationId));
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
      const { data } = await client.get<Ioc[]>(ENDPOINTS.conversations.iocs(conversationId));
      return data;
    },
    enabled: !!conversationId,
    staleTime: 30_000,
  });
}

export { PAGE_SIZE };
