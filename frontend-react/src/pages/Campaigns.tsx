import { useTranslation } from 'react-i18next';
import { useCampaignCandidates } from '@/hooks/useStix';
import { StatCard } from '@/components/ui/StatCard';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';

function formatDate(iso: string): string {
  return new Date(iso).toLocaleString('en-GB', {
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit',
  });
}

export function Campaigns() {
  const { t } = useTranslation();
  const { data: candidates, isLoading, error, refetch } = useCampaignCandidates();

  if (isLoading) return <Loading message={t('campaigns.loading')} />;
  if (error) return <ErrorMessage message={t('campaigns.failedLoad')} onRetry={() => void refetch()} />;

  const safeCandidates = candidates ?? [];

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-on-surface">{t('campaigns.title')}</h1>
        <p className="text-xs text-on-surface-dim mt-1">{t('campaigns.subtitle')}</p>
      </header>

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <StatCard label={t('campaigns.detectedCampaigns')} value={safeCandidates.length} />
        <StatCard
          label={t('campaigns.avgPpv')}
          value={safeCandidates.length > 0
            ? (safeCandidates.reduce((s, c) => s + c.ppv, 0) / safeCandidates.length * 100).toFixed(1) + '%'
            : '--'}
        />
        <StatCard
          label={t('campaigns.totalHits')}
          value={safeCandidates.reduce((s, c) => s + c.hits_total, 0)}
        />
      </div>

      <div className="bg-surface-low rounded-lg overflow-hidden">
        <table className="w-full">
          <thead>
            <tr className="text-xs text-on-surface-dim uppercase tracking-widest">
              <th className="text-left px-5 py-3 font-medium">{t('campaigns.campaignId')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('campaigns.ruleId')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('campaigns.ppv')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('campaigns.hits')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('campaigns.leadTime')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('campaigns.created')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('common.status.open')}</th>
            </tr>
          </thead>
          <tbody className="text-sm">
            {safeCandidates.map((c) => {
              const ppvPct = (c.ppv * 100).toFixed(1);
              const ppvColor = c.ppv >= 0.85 ? 'text-success' : c.ppv >= 0.5 ? 'text-warning' : 'text-error';
              return (
                <tr key={c.campaign_id} className="hover:bg-surface-high/50 transition-colors">
                  <td className="px-5 py-3 font-mono text-xs text-accent">{c.campaign_id.slice(0, 8)}</td>
                  <td className="px-5 py-3 font-mono text-xs text-on-surface-variant">{c.rule_id.slice(0, 8)}</td>
                  <td className="px-5 py-3">
                    <span className={`font-mono text-xs font-bold ${ppvColor}`}>{ppvPct}%</span>
                  </td>
                  <td className="px-5 py-3 font-mono text-xs text-on-surface-variant">{c.hits_total}</td>
                  <td className="px-5 py-3 text-on-surface-variant text-xs">{c.lead_time_hours}h</td>
                  <td className="px-5 py-3 text-on-surface-dim text-xs">{formatDate(c.created_at)}</td>
                  <td className="px-5 py-3">
                    <span className="text-xs px-2 py-0.5 rounded font-medium bg-success/20 text-success">
                      {t('common.status.promotable')}
                    </span>
                  </td>
                </tr>
              );
            })}
            {safeCandidates.length === 0 && (
              <tr>
                <td colSpan={7} className="px-5 py-12 text-center text-on-surface-dim">
                  {t('campaigns.noCampaigns')}
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

export default Campaigns;
