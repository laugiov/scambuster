import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useConversations } from '@/hooks/useConversations';
import { Badge, statusToBadgeVariant } from '@/components/ui/Badge';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import { useMetaConfig, personaDisplayName } from '@/hooks/useMetaConfig';
import { useAutonomyStats } from '@/hooks/useStats';
import { timeSince } from '@/lib/time';

export function Conversations() {
  const { t } = useTranslation();
  const { data: conversations, isLoading, error, refetch } = useConversations();
  const { data: config } = useMetaConfig();
  const { data: stats } = useAutonomyStats();

  if (isLoading) return <Loading message={t('conversations.loading')} />;
  if (error) return <ErrorMessage message={t('conversations.failedLoad')} onRetry={() => void refetch()} />;

  const sorted = [...(conversations ?? [])].sort(
    (a, b) => new Date(b.ts_last ?? b.updated_at ?? 0).getTime() - new Date(a.ts_last ?? a.updated_at ?? 0).getTime()
  );

  // Use autonomy stats for accurate totals (API paginates at 20)
  const totalCount = stats?.conversations.total ?? sorted.length;
  const activeCount = stats?.conversations.active ?? sorted.filter((c) => c.status === 'open').length;
  const closedCount = stats?.conversations.closed ?? sorted.filter((c) => c.status === 'closed').length;

  return (
    <div className="space-y-6">
      <header className="flex items-center justify-between">
        <h1 className="text-xl font-semibold text-on-surface">{t('conversations.title')}</h1>
        <div className="flex items-center gap-4 text-xs text-on-surface-dim">
          <span>{t('conversations.total', { count: totalCount })}</span>
          <span className="text-success">{t('conversations.activeLower', { count: activeCount })}</span>
          <span>{t('conversations.closed', { count: closedCount })}</span>
        </div>
      </header>

      <div className="bg-surface-low rounded-lg overflow-hidden">
        <table className="w-full">
          <thead>
            <tr className="text-xs text-on-surface-dim uppercase tracking-widest">
              <th className="text-left px-5 py-3 font-medium">{t('conversations.sourceId')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('conversations.scamType')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('conversations.persona')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('conversations.risk')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('conversations.messages')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('conversations.lastActivity')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('common.status.open')}</th>
            </tr>
          </thead>
          <tbody className="text-sm">
            {sorted.map((conv) => (
              <tr key={conv.conv_id} className="hover:bg-surface-high/50 transition-colors">
                <td className="px-5 py-3">
                  <Link
                    to={`/conversations/${conv.conv_id}`}
                    className="text-accent hover:text-accent-hover font-mono text-xs transition-colors"
                  >
                    {conv.conv_id.slice(0, 8)}
                  </Link>
                </td>
                <td className="px-5 py-3 text-on-surface-variant">{conv.scam_type ?? '--'}</td>
                <td className="px-5 py-3 text-on-surface-variant">
                  {conv.persona ? personaDisplayName(config, conv.persona) : '--'}
                </td>
                <td className="px-5 py-3">
                  <RiskIndicator score={conv.score_risk} />
                </td>
                <td className="px-5 py-3 text-on-surface-variant font-mono text-xs">
                  {conv.turns ?? conv.message_count ?? '--'}
                </td>
                <td className="px-5 py-3 text-on-surface-dim text-xs">
                  {conv.ts_last ? timeSince(conv.ts_last) : conv.updated_at ? timeSince(conv.updated_at) : '--'}
                </td>
                <td className="px-5 py-3">
                  <Badge label={conv.status} variant={statusToBadgeVariant(conv.status)} />
                </td>
              </tr>
            ))}
            {sorted.length === 0 && (
              <tr>
                <td colSpan={7} className="px-5 py-12 text-center text-on-surface-dim">
                  {t('conversations.noConversations')}
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function RiskIndicator({ score }: { score: number }) {
  const color = score >= 70 ? 'text-error' : score >= 40 ? 'text-warning' : 'text-success';
  return <span className={`font-mono text-xs font-medium ${color}`}>{score}</span>;
}
export default Conversations;
