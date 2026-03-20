import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';
import type { Ioc } from '@/types/api';

export function useAllIocs() {
  return useQuery<Ioc[]>({
    queryKey: ['all-iocs'],
    queryFn: async () => {
      const { data: conversations } = await client.get<Array<{ conv_id: string }>>(
        ENDPOINTS.conversations.list,
      );

      // Limit to 20 most recent conversations to avoid request storm
      const batch = conversations.slice(0, 20);
      const results = await Promise.all(
        batch.map(async (conv) => {
          try {
            const { data } = await client.get<Ioc[]>(
              ENDPOINTS.conversations.iocs(conv.conv_id),
            );
            return data;
          } catch {
            return [];
          }
        }),
      );

      // Flatten and deduplicate by ioc_id
      const seen = new Set<string>();
      const all: Ioc[] = [];
      for (const iocBatch of results) {
        for (const ioc of iocBatch) {
          if (!seen.has(ioc.ioc_id)) {
            seen.add(ioc.ioc_id);
            all.push(ioc);
          }
        }
      }
      return all.sort((a, b) =>
        new Date(b.ts_observed).getTime() - new Date(a.ts_observed).getTime(),
      );
    },
    staleTime: 60_000,
    refetchOnWindowFocus: false,
  });
}
