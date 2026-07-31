import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';
import type { MailAccountListItem } from '@/types/api';

export function useMailAccounts() {
  return useQuery<MailAccountListItem[]>({
    queryKey: ['mail-accounts'],
    queryFn: async () => {
      const { data } = await client.get<MailAccountListItem[]>(ENDPOINTS.mailAccounts.list);
      return data;
    },
    staleTime: 5 * 60 * 1000,
    refetchOnWindowFocus: false,
  });
}
