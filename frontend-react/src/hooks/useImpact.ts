import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';

interface WeeklyPoint {
  week: string;
  hours: number;
}

interface WastedTime {
  total_hours: number;
  total_conversations: number;
  avg_hours_per_conversation: number;
  weekly_trend: WeeklyPoint[];
}

interface IocTypeEntry {
  type: string;
  count: number;
}

interface IocValue {
  total_iocs: number;
  novel_iocs: number;
  novel_pct: number;
  by_type: IocTypeEntry[];
}

interface CostEfficiency {
  total_cost_usd: number;
  cost_per_ioc_usd: number;
  cost_per_conversation_usd: number;
}

interface TopCampaign {
  campaign_id: string;
  rule_id: string;
  hits: number;
  ppv: number;
  promoted: boolean;
}

interface CampaignData {
  total: number;
  promoted: number;
  top_campaigns: TopCampaign[];
}

export interface ImpactSummary {
  wasted_time: WastedTime;
  ioc_value: IocValue;
  cost_efficiency: CostEfficiency;
  campaigns: CampaignData;
}

export function useImpactSummary(period: string = 'all') {
  return useQuery<ImpactSummary>({
    queryKey: ['impact-summary', period],
    queryFn: async () => {
      const { data } = await client.get<ImpactSummary>(ENDPOINTS.impact.summary, {
        params: { period },
      });
      return data;
    },
    staleTime: 300_000,
  });
}

export type { WeeklyPoint, IocTypeEntry, TopCampaign };
