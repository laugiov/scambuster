import { useState } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useIocDetail, useIocGraph, useIocContext } from '@/hooks/useIocs';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import { IocGraph } from '@/components/ioc/IocGraph';
import { IocTimeline } from '@/components/ioc/IocTimeline';
import { timeSince } from '@/lib/time';
import type { IocObservation, IocRelated, IocContextEntry } from '@/types/api';

type TabId = 'overview' | 'observations' | 'related' | 'context';

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
  const { data: graphData } = useIocGraph(indicatorId ?? '');
  const { data: contextData } = useIocContext(indicatorId ?? '');
  const [activeTab, setActiveTab] = useState<TabId>('overview');

  if (isLoading) return <Loading message={t('iocDetail.loading')} />;
  if (error || !detail) return <ErrorMessage message={t('iocDetail.notFound')} onRetry={() => void refetch()} />;

  const sev = scoreSeverity(('agg' in detail.score) ? (detail.score.agg ?? 0) : 0);

  const contextCount = contextData?.contexts.length ?? 0;

  const tabs: { id: TabId; label: string; count?: number }[] = [
    { id: 'overview', label: t('iocDetail.overview') },
    { id: 'observations', label: t('iocDetail.observations'), count: detail.observations.length },
    { id: 'related', label: t('iocDetail.relatedIocs'), count: detail.related_iocs.length },
    { id: 'context', label: t('iocDetail.context'), count: contextCount > 0 ? contextCount : undefined },
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
      {activeTab === 'related' && <RelatedTab relatedIocs={detail.related_iocs} graphData={graphData} />}
      {activeTab === 'context' && <ContextTab contexts={contextData?.contexts ?? []} />}
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

      {/* Observation Timeline */}
      {detail.observations.length > 0 && (
        <IocTimeline observations={detail.observations} />
      )}

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

function RelatedTab({ relatedIocs, graphData }: { relatedIocs: IocRelated[]; graphData?: import('@/types/api').IocGraph }) {
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
    <div className="space-y-6">
      {/* Co-occurrence graph */}
      {graphData && graphData.nodes.length > 1 && (
        <div className="bg-surface-low rounded-lg p-4">
          <h4 className="text-xs font-bold text-on-surface-dim uppercase tracking-widest mb-3">
            {t('iocDetail.coOccurrenceGraph')}
          </h4>
          <IocGraph data={graphData} />
        </div>
      )}

      {/* Table */}
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
    </div>
  );
}

const ROLE_COLORS: Record<string, string> = {
  PAYMENT_DESTINATION: 'bg-error/20 text-error',
  PAYMENT_REDIRECT_URL: 'bg-error/20 text-error',
  MONEY_MULE_ACCOUNT: 'bg-error/20 text-error',
  PHISHING_CREDENTIAL_URL: 'bg-warning/20 text-warning',
  MALWARE_DOWNLOAD_URL: 'bg-warning/20 text-warning',
  CONTACT_CHANNEL: 'bg-blue-500/20 text-blue-400',
  INFRASTRUCTURE_DOMAIN: 'bg-purple-500/20 text-purple-400',
  VERIFICATION_CODE_URL: 'bg-yellow-500/20 text-yellow-400',
  IDENTITY_DOCUMENT: 'bg-yellow-500/20 text-yellow-400',
  UNKNOWN: 'bg-on-surface-dim/20 text-on-surface-dim',
};

const STATUS_STYLE: Record<string, { bg: string; label: string }> = {
  enriched: { bg: 'bg-success/20 text-success', label: 'enriched' },
  structural: { bg: 'bg-warning/20 text-warning', label: 'structural' },
  pending: { bg: 'bg-on-surface-dim/20 text-on-surface-dim', label: 'pending' },
  failed: { bg: 'bg-error/20 text-error', label: 'failed' },
  skipped: { bg: 'bg-on-surface-dim/20 text-on-surface-dim', label: 'skipped' },
};

function ContextTab({ contexts }: { contexts: IocContextEntry[] }) {
  const { t } = useTranslation();

  if (contexts.length === 0) {
    return (
      <div className="text-center py-12 text-on-surface-dim">
        {t('iocContext.noContext')}
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {contexts.map((ctx) => (
        <ContextCard key={ctx.obs_id} ctx={ctx} />
      ))}
    </div>
  );
}

function ContextCard({ ctx }: { ctx: IocContextEntry }) {
  const { t } = useTranslation();
  const status = ctx.enrichment_status;
  const statusStyle = STATUS_STYLE[status] ?? STATUS_STYLE.pending;

  if (status === 'skipped') {
    return (
      <div className="bg-surface-low rounded-lg p-5">
        <span className={`text-xs px-2 py-0.5 rounded ${statusStyle.bg}`}>{statusStyle.label}</span>
        <p className="text-sm text-on-surface-dim mt-2">{t('iocContext.skipped')}</p>
      </div>
    );
  }

  if (status === 'pending') {
    return (
      <div className="bg-surface-low rounded-lg p-5">
        <span className={`text-xs px-2 py-0.5 rounded ${statusStyle.bg}`}>{statusStyle.label}</span>
        <p className="text-sm text-on-surface-dim mt-2">{t('iocContext.pending')}</p>
      </div>
    );
  }

  const s = ctx.structural;
  const turnPct = s.revelation_turn_ratio != null ? Math.round(s.revelation_turn_ratio * 100) : 0;

  return (
    <div className="bg-surface-low rounded-lg overflow-hidden">
      {/* Revelation Context */}
      <div className="p-5 space-y-4">
        <div className="flex items-center justify-between">
          <h3 className="text-xs font-bold text-on-surface-dim uppercase tracking-widest">
            {t('iocContext.revelationContext')}
          </h3>
          <span className={`text-xs px-2 py-0.5 rounded font-medium ${statusStyle.bg}`}>
            {statusStyle.label}
          </span>
        </div>

        {/* Turn progress */}
        {s.revelation_turn != null && (
          <div>
            <div className="flex items-center justify-between text-xs text-on-surface-dim mb-1">
              <span>
                {t('iocContext.turn')} {s.revelation_turn}
                {s.total_turns != null && s.total_turns > 0 && ` / ${s.total_turns}`}
              </span>
              {s.total_turns != null && s.total_turns > 0 && <span>{turnPct}%</span>}
            </div>
            {s.total_turns != null && s.total_turns > 0 && (
              <div className="w-full h-1.5 bg-surface-highest rounded-full overflow-hidden">
                <div className="h-full rounded-full bg-accent" style={{ width: `${turnPct}%` }} />
              </div>
            )}
          </div>
        )}

        {/* Metadata grid */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
          {s.scam_type && <MetaField label={t('iocContext.scamType')} value={s.scam_type} />}
          {s.persona_code && <MetaField label={t('iocContext.persona')} value={s.persona_label ?? s.persona_code} />}
          {s.extraction_method && <MetaField label={t('iocContext.extraction')} value={s.extraction_method} />}
          {s.engagement_hours != null && <MetaField label={t('iocContext.engagement')} value={`${s.engagement_hours}h`} />}
        </div>
      </div>

      {/* Semantic Role */}
      {status === 'enriched' && ctx.semantic && (
        <>
          <div className="border-t border-surface-high p-5 space-y-4">
            <h3 className="text-xs font-bold text-on-surface-dim uppercase tracking-widest">
              {t('iocContext.semanticRole')}
            </h3>
            {ctx.semantic.role && (
              <div className="flex items-center gap-3">
                <span className={`text-sm font-bold px-3 py-1 rounded ${ROLE_COLORS[ctx.semantic.role] ?? ROLE_COLORS.UNKNOWN}`}>
                  {ctx.semantic.role}
                </span>
                {ctx.semantic.enrichment_confidence != null && (
                  <span className="text-xs text-on-surface-dim">
                    {t('iocContext.confidence')}: {Math.round(ctx.semantic.enrichment_confidence * 100)}%
                  </span>
                )}
              </div>
            )}
          </div>

          {/* Stimulus */}
          {ctx.semantic.stimulus_type && (
            <div className="border-t border-surface-high p-5">
              <h3 className="text-xs font-bold text-on-surface-dim uppercase tracking-widest mb-2">
                {t('iocContext.stimulus')}
              </h3>
              <span className="text-sm font-mono text-accent">{ctx.semantic.stimulus_type}</span>
            </div>
          )}

          {/* Behavioral Signals */}
          <div className="border-t border-surface-high p-5 space-y-3">
            <h3 className="text-xs font-bold text-on-surface-dim uppercase tracking-widest">
              {t('iocContext.behavioralSignals')}
            </h3>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
              {ctx.semantic.urgency_score != null && (
                <div>
                  <label className="text-xs text-on-surface-dim block mb-1">{t('iocContext.urgency')}</label>
                  <div className="flex items-center gap-2">
                    <div className="w-20 h-1.5 bg-surface-highest rounded-full overflow-hidden">
                      <div
                        className="h-full rounded-full bg-error"
                        style={{ width: `${Math.round(ctx.semantic.urgency_score * 100)}%` }}
                      />
                    </div>
                    <span className="text-xs font-bold text-on-surface">
                      {Math.round(ctx.semantic.urgency_score * 100)}%
                    </span>
                  </div>
                </div>
              )}
              <div>
                <label className="text-xs text-on-surface-dim block mb-1">{t('iocContext.hesitation')}</label>
                <span className={`text-xs font-medium ${ctx.semantic.hesitation_detected ? 'text-warning' : 'text-on-surface-dim'}`}>
                  {ctx.semantic.hesitation_detected ? t('iocContext.hesitationDetected') : t('iocContext.hesitationNotDetected')}
                </span>
              </div>
              <div>
                <label className="text-xs text-on-surface-dim block mb-1">{t('iocContext.languageSwitch')}</label>
                <span className={`text-xs font-medium ${ctx.semantic.language_switch ? 'text-warning' : 'text-on-surface-dim'}`}>
                  {ctx.semantic.language_switch ? t('iocContext.detected') : t('iocContext.notDetected')}
                </span>
              </div>
            </div>
          </div>

          {/* Context Excerpt */}
          {ctx.semantic.context_excerpt && (
            <div className="border-t border-surface-high p-5">
              <h3 className="text-xs font-bold text-on-surface-dim uppercase tracking-widest mb-2">
                {t('iocContext.contextExcerpt')}
              </h3>
              <p className="text-sm text-on-surface-variant bg-surface-base rounded-lg p-3 italic">
                &ldquo;{ctx.semantic.context_excerpt}&rdquo;
              </p>
            </div>
          )}
        </>
      )}

      {/* Structural-only message */}
      {status === 'structural' && (
        <div className="border-t border-surface-high p-5">
          <p className="text-xs text-warning">{t('iocContext.structuralOnly')}</p>
        </div>
      )}

      {/* Failed message */}
      {status === 'failed' && (
        <div className="border-t border-surface-high p-5">
          <p className="text-xs text-error">{t('iocContext.failed')}</p>
        </div>
      )}

      {/* Co-revealed IOCs */}
      {s.co_revealed_types.length > 0 && (
        <div className="border-t border-surface-high p-5">
          <h3 className="text-xs font-bold text-on-surface-dim uppercase tracking-widest mb-2">
            {t('iocContext.coRevealed')}
          </h3>
          <div className="flex gap-2 flex-wrap">
            {s.co_revealed_types.map((type) => (
              <span key={type} className="text-xs bg-surface-high px-2 py-0.5 rounded text-on-surface-variant">
                {type}
              </span>
            ))}
            <span className="text-xs text-on-surface-dim">
              ({s.co_revealed_count} {t('iocContext.coRevealedFrom')})
            </span>
          </div>
        </div>
      )}

      {/* Footer: enrichment status + computed_at */}
      <div className="border-t border-surface-high px-5 py-3 flex items-center gap-4 text-xs text-on-surface-dim">
        <span>{t('iocContext.enrichmentStatus')}: {statusStyle.label}</span>
        {ctx.computed_at && <span>{t('iocContext.computedAt')}: {new Date(ctx.computed_at).toLocaleString()}</span>}
      </div>
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
