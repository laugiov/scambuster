import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';

export interface CampaignDetail {
  campaign_id: string;
  status: string;
  severity: number;
  tlp: string;
  first_seen: string;
  profile_yaml: string | null;
  notes: string | null;
  created_at: string;
  rule: {
    rule_id: string;
    ppv: number;
    hits_total: number;
    hits_true_pos: number;
    hits_false_pos: number;
    lead_time_sec: number | null;
    lead_time_hours: number | null;
    enabled: boolean;
    promoted_at: string | null;
  } | null;
}

export function useCampaignDetail(campaignId: string) {
  return useQuery<CampaignDetail>({
    queryKey: ['campaign-detail', campaignId],
    queryFn: async () => {
      const { data } = await client.get<CampaignDetail>(ENDPOINTS.campaign.detail(campaignId));
      return data;
    },
    enabled: !!campaignId,
  });
}

export interface CampaignMessage {
  msg_id: string;
  subject: string | null;
  from: string | null;
  received_at: string;
  body_preview: string;
}

export interface CampaignProfileResult {
  profile_yaml: string;
  cache_hit: boolean;
  attempts: number;
}

export interface HuntResult {
  total_rules: number;
  total_hits: number;
  results: Array<{
    rule_id: string;
    campaign_id: string;
    hits: number;
    ppv: number;
  }>;
}

export interface PromoteResult {
  message: string;
  campaign_id: string;
  rule_id: string;
}

export function useCampaignMessages(campaignId: string) {
  return useQuery<CampaignMessage[]>({
    queryKey: ['campaign-messages', campaignId],
    queryFn: async () => {
      const { data } = await client.get<{ messages: CampaignMessage[] }>(
        ENDPOINTS.campaign.messages(campaignId),
      );
      return data.messages ?? data as unknown as CampaignMessage[];
    },
    enabled: !!campaignId,
  });
}

export function useCampaignProfile(campaignId: string) {
  return useMutation<CampaignProfileResult, Error>({
    mutationFn: async () => {
      const { data } = await client.post<CampaignProfileResult>(
        ENDPOINTS.campaign.profile(campaignId),
      );
      return data;
    },
  });
}

export function usePromoteRule() {
  const queryClient = useQueryClient();
  return useMutation<PromoteResult, Error, string>({
    mutationFn: async (ruleId: string) => {
      const { data } = await client.post<PromoteResult>(
        ENDPOINTS.campaign.promoteRule(ruleId),
      );
      return data;
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['campaign-candidates'] });
    },
  });
}

export function useHunt() {
  const queryClient = useQueryClient();
  return useMutation<HuntResult, Error>({
    mutationFn: async () => {
      const { data } = await client.post<HuntResult>(ENDPOINTS.campaign.hunt);
      return data;
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['campaign-candidates'] });
    },
  });
}
