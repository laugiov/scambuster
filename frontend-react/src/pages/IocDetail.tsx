import { useState } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useIocDetail } from '@/hooks/useIocs';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import { timeSince } from '@/lib/time';
import type { IocObservation, IocRelated } from '@/types/api';

type TabId = 'overview' | 'observations' | 'related';

function scoreSeverity(score: number): { label: string; color: string; barColor: string } {
  if (score >= 70) return { label: 'High', color: 'text-error', barColor: 'bg-error' };
  if (score >= 40) return { label: 'Medium', color: 'text-warning', barColor: 'bg-warning' };
  return { label: 'Low', color: 'text-on-surface-dim', barColor: 'bg-on-surface-dim' };
}

function tlpColor(tlp: string): string {
  switch (tlp.toUpperCase()) {
    case 'RED': return 'bg-error text-white';
    case 'AMBER': return 'bg-warning text-surface-base';
    case 'GREEN': return 'bg-success text-white';
    default: return 'bg-on-surface-dim text-white';
  }
}

export function IocDetail() {
  const { indicatorId } = useParams<{ indicatorId: string }>();
  const navigate = useNavigate();
  const { t } = useTranslation();
  const { data: detail, isLoading, error, refetch } = useIocDetail(indicatorId ?? '');
  const [activeTab, setActiveTab] = useState<TabId>('overview');

  if (isLoading) return <Loading message={t('iocDetail.loading')} />;
  if (error || !detail) return <ErrorMessage message={t('iocDetail.notFound')} onRetry={() => void refetch()} />;

  const sev = scoreSeverity(('agg' in detail.score) ? (detail.score.agg ?? 0) : 0);

  const tabs: { id: TabId; label: string; count?: number }[] = [
    { id: 'overview', label: t('iocDetail.overview') },
    { id: 'observations', label: t('iocDetail.observations'), count: detail.observations.length },
    { id: 'related', label: t('iocDetail.relatedIocs'), count: detail.related_iocs.length },
  ];

  return (
    <div className="space-y-6">
      {/* Back button */}
      <button
        onClick={() => navigate('/ioc-explorer')}
        className="flex items-center gap-1 text-sm text-on-surface-dim hover:text-accent transition-colors"
      >
        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
          <path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" />
        </svg>
        {t('iocDetail.backToExplorer')}
      </button>

      {/* Header */}
      <header className="space-y-3">
        <div className="flex items-center gap-3 flex-wrap">
          <span className="text-xs uppercase px-2 py-0.5 bg-surface-high text-on-surface-variant rounded font-medium">
            {detail.type}
          </span>
          <span className={`text-xs px-2 py-0.5 rounded font-medium ${sev.color} bg-surface-high`}>
            {sev.label}
          </span>
          <span className={`text-xs px-2 py-0.5 rounded font-bold ${tlpColor(detail.tlp)}`}>
            TLP:{detail.tlp}
          </span>
          {detail.category && detail.category !== 'Unknown' && (
            <span className="text-xs px-2 py-0.5 bg-accent-muted/20 text-accent rounded">
              {detail.category}
            </span>
          )}
        </div>
        <h1 className="text-xl font-mono font-bold text-on-surface break-all">{detail.value}</h1>
        <p className="text-sm font-mono text-on-surface-dim break-all">{detail.value_norm}</p>
      </header>

      {/* Tabs */}
      <nav className="flex gap-1 border-b border-surface-high">
        {tabs.map((tab) => (
          <button
            key={tab.id}
            onClick={() => setActiveTab(tab.id)}
            className={`px-4 py-2 text-sm font-medium transition-colors border-b-2 -mb-px ${
              activeTab === tab.id
                ? 'border-accent text-accent'
                : 'border-transparent text-on-surface-dim hover:text-on-surface'
            }`}
          >
            {tab.label}
            {tab.count !== undefined && (
              <span className="ml-1.5 text-xs bg-surface-high px-1.5 py-0.5 rounded-full">
                {tab.count}
              </span>
            )}
          </button>
        ))}
      </nav>

      {/* Tab content */}
      {activeTab === 'overview' && <OverviewTab detail={detail} />}
      {activeTab === 'observations' && <ObservationsTab observations={detail.observations} />}
      {activeTab === 'related' && <RelatedTab relatedIocs={detail.related_iocs} />}
    </div>
  );
}

function ScoreBar({ value, max, color }: { value: number; max: number; color: string }) {
  const pct = Math.min(Math.max((value / max) * 100, 0), 100);
  return (
    <div className="flex items-center gap-2">
      <div className="w-24 h-1.5 bg-surface-highest rounded-full overflow-hidden">
        <div className={`h-full rounded-full ${color}`} style={{ width: `${pct}%` }} />
      </div>
      <span className="text-xs font-bold text-on-surface">{value}</span>
    </div>
  );
}

function OverviewTab({ detail }: { detail: import('@/types/api').IocDetail }) {
  const { t } = useTranslation();

  return (
    <div className="space-y-6">
      {/* Metadata grid */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <MetaField label={t('iocDetail.firstSeen')} value={new Date(detail.first_seen).toLocaleDateString('en-GB')} />
        <MetaField label={t('iocDetail.lastSeen')} value={new Date(detail.last_seen).toLocaleDateString('en-GB')} />
        <MetaField label={t('iocDetail.occurrences')} value={String(detail.occurrences)} />
        <MetaField label={t('iocDetail.tlp')} value={`TLP:${detail.tlp}`} />
      </div>

      {/* Scoring */}
      <section className="bg-surface-low rounded-lg p-5 space-y-4">
        <h3 className="text-xs font-bold text-on-surface-dim uppercase tracking-widest">{t('iocDetail.scoring')}</h3>
        <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
          <div>
            <label className="text-xs text-on-surface-dim block mb-1">{t('conversationDetail.vtScore')}</label>
            <ScoreBar value={'vt' in detail.score ? (detail.score.vt ?? 0) : 0} max={100} color="bg-error" />
          </div>
          <div>
            <label className="text-xs text-on-surface-dim block mb-1">{t('conversationDetail.urlScan')}</label>
            <ScoreBar value={'urlscan' in detail.score ? (detail.score.urlscan ?? 0) : 0} max={100} color="bg-warning" />
          </div>
          <div>
            <label className="text-xs text-on-surface-dim block mb-1">{t('iocExplorer.score')}</label>
            <ScoreBar value={'agg' in detail.score ? (detail.score.agg ?? 0) : 0} max={100} color="bg-accent" />
          </div>
          <div>
            <label className="text-xs text-on-surface-dim block mb-1">{t('iocExplorer.confidence')}</label>
            <ScoreBar value={Math.round(detail.confidence * 100)} max={100} color="bg-success" />
          </div>
          <div>
            <label className="text-xs text-on-surface-dim block mb-1">{t('iocExplorer.decayFactor')}</label>
            <ScoreBar value={Math.round(detail.decay_factor * 100)} max={100} color="bg-on-surface-dim" />
          </div>
          <div>
            <label className="text-xs text-on-surface-dim block mb-1">{t('iocExplorer.effectiveScore')}</label>
            <ScoreBar value={Math.round(detail.effective_score * 100)} max={100} color="bg-accent" />
          </div>
        </div>
        {'explain' in detail.score && detail.score.explain && (
          <p className="text-sm text-on-surface-variant bg-surface-base rounded-lg p-3 mt-2">
            {detail.score.explain}
          </p>
        )}
      </section>

      {/* MISP + STIX */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {detail.misp && (
          <section className="bg-surface-low rounded-lg p-5 space-y-2">
            <h3 className="text-xs font-bold text-on-surface-dim uppercase tracking-widest">{t('iocDetail.mispMapping')}</h3>
            <div className="grid grid-cols-2 gap-2">
              <MetaField label={t('conversationDetail.category')} value={detail.misp.category} />
              <MetaField label={t('iocExplorer.type')} value={detail.misp.type} />
            </div>
            <div className="flex items-center gap-2 mt-1">
              <span className="text-xs text-on-surface-dim">to_ids:</span>
              <span className={`text-xs font-bold ${detail.misp.to_ids ? 'text-success' : 'text-on-surface-dim'}`}>
                {detail.misp.to_ids ? 'true' : 'false'}
              </span>
            </div>
          </section>
        )}
        {detail.stix && (
          <section className="bg-surface-low rounded-lg p-5 space-y-2">
            <h3 className="text-xs font-bold text-on-surface-dim uppercase tracking-widest">{t('iocDetail.stixPattern')}</h3>
            <pre className="p-3 bg-surface-base rounded-lg font-mono text-xs text-accent/70 overflow-auto">
              {detail.stix.pattern}
            </pre>
            <p className="text-xs text-on-surface-dim">SCO Type: {detail.stix.sco_type}</p>
          </section>
        )}
      </div>
    </div>
  );
}

function ObservationsTab({ observations }: { observations: IocObservation[] }) {
  const { t } = useTranslation();

  if (observations.length === 0) {
    return (
      <div className="text-center py-12 text-on-surface-dim">
        {t('iocDetail.noObservations')}
      </div>
    );
  }

  return (
    <div className="bg-surface-low rounded-lg overflow-hidden">
      <table className="w-full text-left">
        <thead>
          <tr className="text-xs text-on-surface-dim uppercase tracking-widest">
            <th className="px-5 py-3 font-medium">{t('iocDetail.date')}</th>
            <th className="px-5 py-3 font-medium">{t('iocDetail.conversation')}</th>
            <th className="px-5 py-3 font-medium">{t('iocDetail.scamType')}</th>
            <th className="px-5 py-3 font-medium">{t('iocDetail.extractionMethod')}</th>
            <th className="px-5 py-3 font-medium">{t('iocDetail.status')}</th>
          </tr>
        </thead>
        <tbody className="text-sm">
          {observations.map((obs) => (
            <tr key={obs.obs_id} className="hover:bg-surface-high/50 transition-colors">
              <td className="px-5 py-3 text-on-surface-dim text-xs">{timeSince(obs.ts_observed)}</td>
              <td className="px-5 py-3">
                <Link
                  to={`/conversations/${obs.conv_id}`}
                  className="text-accent hover:underline text-sm"
                >
                  {obs.conv_subject ?? obs.conv_id.slice(0, 8)}
                </Link>
              </td>
              <td className="px-5 py-3">
                <span className="text-xs uppercase bg-surface-high px-2 py-0.5 rounded text-on-surface-variant">
                  {obs.conv_scam_type}
                </span>
              </td>
              <td className="px-5 py-3 text-xs text-on-surface-dim font-mono">{obs.extraction_method}</td>
              <td className="px-5 py-3">
                <span className={`text-xs px-2 py-0.5 rounded ${
                  obs.conv_status === 'open' ? 'bg-success/20 text-success' :
                  obs.conv_status === 'closed' ? 'bg-on-surface-dim/20 text-on-surface-dim' :
                  'bg-warning/20 text-warning'
                }`}>
                  {obs.conv_status}
                </span>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function RelatedTab({ relatedIocs }: { relatedIocs: IocRelated[] }) {
  const { t } = useTranslation();
  const navigate = useNavigate();

  if (relatedIocs.length === 0) {
    return (
      <div className="text-center py-12 text-on-surface-dim">
        {t('iocDetail.noRelated')}
      </div>
    );
  }

  return (
    <div className="bg-surface-low rounded-lg overflow-hidden">
      <table className="w-full text-left">
        <thead>
          <tr className="text-xs text-on-surface-dim uppercase tracking-widest">
            <th className="px-5 py-3 font-medium">{t('iocExplorer.type')}</th>
            <th className="px-5 py-3 font-medium">{t('iocExplorer.value')}</th>
            <th className="px-5 py-3 font-medium">{t('iocDetail.coOccurrence')}</th>
            <th className="px-5 py-3 font-medium">{t('iocExplorer.score')}</th>
          </tr>
        </thead>
        <tbody className="text-sm">
          {relatedIocs.map((rel) => {
            const aggScore = 'agg' in rel.score ? (rel.score.agg ?? 0) : 0;
            const sev = scoreSeverity(aggScore);
            return (
              <tr
                key={rel.indicator_id}
                onClick={() => navigate(`/ioc-explorer/${rel.indicator_id}`)}
                className="hover:bg-surface-high/50 transition-colors cursor-pointer"
                role="button"
                tabIndex={0}
                onKeyDown={(e) => { if (e.key === 'Enter') navigate(`/ioc-explorer/${rel.indicator_id}`); }}
              >
                <td className="px-5 py-3">
                  <span className="text-xs uppercase text-on-surface-variant">{rel.type}</span>
                </td>
                <td className="px-5 py-3 font-mono text-on-surface truncate max-w-[300px]">{rel.value_norm}</td>
                <td className="px-5 py-3">
                  <span className="text-sm font-bold text-accent">{rel.co_occurrence_count}</span>
                  <span className="text-xs text-on-surface-dim ml-1">{t('iocDetail.conversations')}</span>
                </td>
                <td className="px-5 py-3">
                  <span className={`text-xs font-bold ${sev.color}`}>{aggScore}</span>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

function MetaField({ label, value }: { label: string; value: string }) {
  return (
    <div className="space-y-0.5">
      <label className="text-xs font-bold text-on-surface-dim uppercase tracking-widest block">{label}</label>
      <p className="text-sm font-medium text-on-surface">{value}</p>
    </div>
  );
}

export default IocDetail;
