import { useTranslation } from 'react-i18next';
import { useConversationLifecycle } from '@/hooks/useConversationLifecycle';
import { useRateLimits } from '@/hooks/useRateLimits';
import { StatCard } from '@/components/ui/StatCard';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import type { ConversationTimeoutRow } from '@/types/api';

function formatRelativeTime(isoDate: string): string {
  const diff = Date.now() - new Date(isoDate).getTime();
  const hours = Math.floor(diff / 3_600_000);
  if (hours < 24) return `${hours}h ago`;
  return `${Math.floor(hours / 24)}d ago`;
}

function TimeoutTable({ rows, t }: { rows: ConversationTimeoutRow[]; t: (key: string) => string }) {
  if (rows.length === 0) {
    return <p className="text-on-surface-dim text-sm py-4 text-center">{t('monitoring.noTimeouts')}</p>;
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-outline-variant text-left">
            <th className="pb-2 font-medium text-on-surface-variant">{t('monitoring.convId')}</th>
            <th className="pb-2 font-medium text-on-surface-variant">{t('monitoring.scamType')}</th>
            <th className="pb-2 font-medium text-on-surface-variant">{t('monitoring.persona')}</th>
            <th className="pb-2 font-medium text-on-surface-variant">{t('monitoring.lastActivity')}</th>
            <th className="pb-2 font-medium text-on-surface-variant">{t('monitoring.timeoutIn')}</th>
            <th className="pb-2 font-medium text-on-surface-variant">{t('monitoring.policyTimeout')}</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.conv_id} className="border-b border-outline-variant/50">
              <td className="py-2 font-mono text-xs text-accent">{row.conv_id.slice(0, 8)}</td>
              <td className="py-2">
                <span className="px-2 py-0.5 rounded-full text-xs bg-surface-high text-on-surface">
                  {row.scam_type}
                </span>
              </td>
              <td className="py-2 text-on-surface-variant">{row.persona || '--'}</td>
              <td className="py-2 text-on-surface-variant">{formatRelativeTime(row.last_activity)}</td>
              <td className="py-2">
                <span className={`font-medium ${row.hours_remaining < 6 ? 'text-error' : row.hours_remaining < 12 ? 'text-warning' : 'text-on-surface'}`}>
                  {row.hours_remaining.toFixed(0)}h
                </span>
              </td>
              <td className="py-2 text-on-surface-dim">{row.timeout_hours}h</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export function ConversationMonitoring() {
  const { t } = useTranslation();
  const { data, isLoading, error } = useConversationLifecycle();

  if (isLoading) return <Loading message={t('common.loading')} />;
  if (error || !data) return <ErrorMessage message={t('common.error')} />;

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-on-surface">{t('monitoring.title')}</h1>
        <p className="text-xs text-on-surface-dim mt-1">{t('monitoring.subtitle')}</p>
      </header>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard label={t('monitoring.active')} value={data.active} />
        <StatCard label={t('monitoring.aboutToTimeout')} value={data.about_to_timeout} subtitleColor={data.about_to_timeout > 0 ? 'text-warning' : undefined} />
        <StatCard label={t('monitoring.completedToday')} value={data.completed_today} />
        <StatCard label={t('monitoring.reopenedToday')} value={data.reopened_today} />
      </div>

      <div className="bg-surface-low rounded-lg p-6">
        <h2 className="text-base font-medium text-on-surface mb-4">{t('monitoring.timeoutTable')}</h2>
        <TimeoutTable rows={data.about_to_timeout_list ?? []} t={t} />
      </div>

      {data.by_scam_type && Object.keys(data.by_scam_type).length > 0 && (
        <div className="bg-surface-low rounded-lg p-6">
          <h2 className="text-base font-medium text-on-surface mb-4">{t('monitoring.byScamType')}</h2>
          <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
            {Object.entries(data.by_scam_type).map(([code, info]) => (
              <div key={code} className="bg-surface rounded-md p-3">
                <p className="text-xs font-medium text-on-surface-variant mb-1">{code}</p>
                <p className="text-lg font-semibold text-on-surface">{info.active}</p>
                <p className="text-xs text-on-surface-dim">
                  {info.about_to_timeout > 0 && (
                    <span className="text-warning">{info.about_to_timeout} timeout</span>
                  )}
                  {info.about_to_timeout === 0 && `${info.policy_timeout_hours}h policy`}
                </p>
              </div>
            ))}
          </div>
        </div>
      )}
      <RateLimitsSection />
    </div>
  );
}

function RateLimitsSection() {
  const { t } = useTranslation();
  const { data, isLoading } = useRateLimits();

  if (isLoading || !data) return null;

  const totalHits = data.rate_limited_today.reduce((sum, r) => sum + r.count, 0);

  return (
    <div className="bg-surface-low rounded-lg p-6">
      <h2 className="text-base font-medium text-on-surface mb-4">{t('monitoring.rateLimits')}</h2>
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div className="bg-surface rounded-md p-3">
          <p className="text-xs text-on-surface-dim">{t('monitoring.llmCallsLimit')}</p>
          <p className="text-lg font-semibold text-on-surface">{data.llm_calls_limit}</p>
        </div>
        <div className="bg-surface rounded-md p-3">
          <p className="text-xs text-on-surface-dim">{t('monitoring.activeConvLimit')}</p>
          <p className="text-lg font-semibold text-on-surface">{data.active_conversations_limit}</p>
        </div>
        <div className="bg-surface rounded-md p-3">
          <p className="text-xs text-on-surface-dim">{t('monitoring.rateLimitedToday')}</p>
          <p className={`text-lg font-semibold ${totalHits > 0 ? 'text-warning' : 'text-on-surface'}`}>{totalHits}</p>
        </div>
        <div className="bg-surface rounded-md p-3">
          <p className="text-xs text-on-surface-dim">{t('monitoring.quarantinedSenders')}</p>
          <p className={`text-lg font-semibold ${data.quarantined_senders_today > 0 ? 'text-error' : 'text-on-surface'}`}>{data.quarantined_senders_today}</p>
        </div>
      </div>
    </div>
  );
}

export default ConversationMonitoring;
