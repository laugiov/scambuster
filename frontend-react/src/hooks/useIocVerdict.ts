import { useMutation, useQueryClient } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';

export type IocVerdict = 'confirmed' | 'false_positive';

export interface SubmitVerdictInput {
  indicatorId: string;
  verdict: IocVerdict;
  note?: string;
}

/**
 * Submit an analyst verdict on an IOC (POST /iocs/{id}/feedback).
 *
 * `confirmed` releases a financial IOC from the export hold; `false_positive`
 * removes the IOC from every export. Each call is audit-logged server-side,
 * which is why bulk confirmation loops this endpoint per IOC instead of a
 * batch call.
 */
export function useSubmitIocVerdict() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ indicatorId, verdict, note }: SubmitVerdictInput) => {
      const { data } = await client.post<{ indicator_id: string; verdict: IocVerdict }>(
        ENDPOINTS.iocs.feedback(indicatorId),
        note ? { verdict, note } : { verdict },
      );
      return data;
    },
    onSuccess: (_data, { indicatorId }) => {
      void queryClient.invalidateQueries({ queryKey: ['all-iocs'] });
      void queryClient.invalidateQueries({ queryKey: ['ioc-detail', indicatorId] });
    },
  });
}
