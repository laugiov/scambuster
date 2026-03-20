import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';
import type { Ioc } from '@/types/api';

export function useAllIocs() {
  return useQuery<Ioc[]>({
    queryKey: ['all-iocs'],
    queryFn: async () => {
      // Fetch conversations first, then aggregate IOCs from all
      const { data: conversations } = await client.get<Array<{ conv_id: string }>>('/communication/conversation');
      const iocPromises = conversations.slice(0, 20).map(async (conv) => {
        try {
          const { data } = await client.get<Ioc[]>(`/communication/conversation/${conv.conv_id}/iocs`);
          return data;
        } catch {
          return [];
        }
      });
      const results = await Promise.all(iocPromises);
      // Flatten and deduplicate by ioc_id
      const seen = new Set<string>();
      const all: Ioc[] = [];
      for (const batch of results) {
        for (const ioc of batch) {
          if (!seen.has(ioc.ioc_id)) {
            seen.add(ioc.ioc_id);
            all.push(ioc);
          }
        }
      }
      return all.sort((a, b) => new Date(b.ts_observed).getTime() - new Date(a.ts_observed).getTime());
    },
    staleTime: 60_000,
  });
}
