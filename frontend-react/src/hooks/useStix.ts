import { useQuery, useMutation } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';

interface CampaignCandidate {
  campaign_id: string;
  rule_id: string;
  ppv: number;
  hits_total: number;
  lead_time_sec: number;
  lead_time_hours: number;
  created_at: string;
}

interface StixExportResult {
  message: string;
  file_path: string;
  bundle_id: string;
}

export function useCampaignCandidates() {
  return useQuery<CampaignCandidate[]>({
    queryKey: ['campaign-candidates'],
    queryFn: async () => {
      const { data } = await client.get<{ candidates: CampaignCandidate[] }>(
        ENDPOINTS.campaign.candidates,
      );
      return data.candidates;
    },
  });
}

export function useStixExport() {
  return useMutation<StixExportResult, Error, string>({
    mutationFn: async (campaignId: string) => {
      const { data } = await client.post<StixExportResult>(
        ENDPOINTS.campaign.exportStix(campaignId),
      );
      return data;
    },
  });
}
