import { useState } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useIocDetail, useIocGraph, useIocContext } from '@/hooks/useIocs';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import { IocGraph } from '@/components/ioc/IocGraph';
import { IocTimeline } from '@/components/ioc/IocTimeline';
import { ThreatActorSummaryCard } from '@/components/ioc/ThreatActorSummaryCard';
import { useThreatActorSummary } from '@/hooks/useThreatActor';
import { timeSince } from '@/lib/time';
import { scamTypeLabel, scamTypeColor, humanize } from '@/lib/scamTypeLabels';
import type { IocObservation, IocRelated, IocContextEntry } from '@/types/api';

function formatNonAmbiguousDate(iso: string): string {
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '--';
  const month = d.toLocaleString('en-US', { month: 'short' });
  return `${month} ${d.getDate()}, ${d.getFullYear()}`;
}

type TabId = 'overview' | 'observations' | 'related' | 'context';

import { iocSeverity as computeSeverityInfo } from '@/lib/iocSeverity';

function scoreSeverity(iocType: string, vtScore: number, urlscanScore: number): { label: string; color: string; barColor: string } {
  const sev = computeSeverityInfo(iocType, vtScore, urlscanScore);
  switch (sev.label) {
    case 'HIGH': return { label: 'High', color: 'text-error', barColor: 'bg-error' };
    case 'MEDIUM': return { label: 'Medium', color: 'text-warning', barColor: 'bg-warning' };
    default: return { label: 'Low', color: 'text-on-surface-dim', barColor: 'bg-on-surface-dim' };
  }
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

  // Load threat-actors from conversations where this IOC was observed
  const convIds = (detail?.observations ?? []).map((o) => o.conv_id);
  const threatActorSummary = useThreatActorSummary(convIds);

  if (isLoading) return <Loading message={t('iocDetail.loading')} />;
  if (error || !detail) return <ErrorMessage message={t('iocDetail.notFound')} onRetry={() => void refetch()} />;

  const sev = scoreSeverity(detail.type, detail.score?.vt ?? 0, detail.score?.urlscan ?? 0);

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
        {detail.type.toLowerCase() === 'url' || detail.type.toLowerCase() === 'domain' ? (
          <>
            <h1 className="text-xl font-mono font-bold text-on-surface break-all">{detail.value_norm}</h1>
            <p className="text-xs text-on-surface-dim mt-1 flex items-center gap-2">
              <span className="text-warning">⚠ Active — do not open:</span>
              <span className="font-mono text-on-surface-dim break-all">{detail.value}</span>
            </p>
          </>
        ) : (
          <>
            <h1 className="text-xl font-mono font-bold text-on-surface break-all">{detail.value}</h1>
            <p className="text-sm font-mono text-on-surface-dim break-all">{detail.value_norm}</p>
          </>
        )}
      </header>

      {/* Threat Actor attribution */}
      {threatActorSummary.data && <ThreatActorSummaryCard summary={threatActorSummary.data} />}

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
        <MetaField label={t('iocDetail.firstSeen')} value={formatNonAmbiguousDate(detail.first_seen)} />
        <MetaField label={t('iocDetail.lastSeen')} value={formatNonAmbiguousDate(detail.last_seen)} />
        <MetaField label={t('iocDetail.occurrences')} value={String(detail.occurrences)} />
        <MetaField label={t('iocDetail.tlp')} value={`TLP:${detail.tlp}`} />
      </div>

      {/* Scoring — split into External Sources + ScamBuster Scoring */}
      <section className="bg-surface-low rounded-lg p-5 space-y-4">
        <h3 className="text-xs font-bold text-on-surface-dim uppercase tracking-widest">{t('iocDetail.scoring')}</h3>

        {/* External Sources */}
        <div>
          <span className="text-[0.625rem] text-on-surface-dim uppercase tracking-widest">External Sources</span>
          <div className="grid grid-cols-2 gap-4 mt-2">
            <div>
              <label className="text-xs text-on-surface-dim block mb-1">VirusTotal</label>
              <ScoreBar value={'vt' in detail.score ? (detail.score.vt ?? 0) : 0} max={72} color="bg-error" />
              <span className="text-[0.5rem] text-on-surface-dim">/ 72 engines</span>
            </div>
            <div>
              <label className="text-xs text-on-surface-dim block mb-1">URLScan</label>
              <ScoreBar value={'urlscan' in detail.score ? (detail.score.urlscan ?? 0) : 0} max={100} color="bg-warning" />
            </div>
          </div>
        </div>

        <div className="border-t border-surface-high" />

        {/* ScamBuster Scoring */}
        <div>
          <span className="text-[0.625rem] text-on-surface-dim uppercase tracking-widest">ScamBuster Scoring</span>
          <div className="grid grid-cols-3 gap-4 mt-2">
            <div>
              <label className="text-xs text-on-surface-dim block mb-1">Extraction Confidence</label>
              <ScoreBar value={Math.round(detail.confidence * 100)} max={100} color="bg-success" />
            </div>
            <div>
              <label className="text-xs text-on-surface-dim block mb-1">Decay</label>
              <ScoreBar value={Math.round(detail.decay_factor * 100)} max={100} color={detail.decay_factor > 0.8 ? 'bg-success' : detail.decay_factor > 0.5 ? 'bg-warning' : 'bg-error'} />
            </div>
            <div title="Composite: confidence × decay">
              <label className="text-xs text-on-surface-dim block mb-1">Effective Score</label>
              <ScoreBar value={Math.round(detail.effective_score * 100)} max={100} color="bg-accent" />
            </div>
          </div>
        </div>

        {/* eslint-disable-next-line react-hooks/purity */}
        <ScoringExplain score={detail.score} ageDays={Math.floor((Date.now() - new Date(detail.first_seen).getTime()) / 86400000)} />
      </section>

      {/* Observation Timeline — only show chart if ≥ 3 observations */}
      {detail.observations.length >= 3 ? (
        <IocTimeline observations={detail.observations} />
      ) : detail.observations.length > 0 ? (
        <section className="bg-surface-low rounded-lg p-5">
          <h3 className="text-xs uppercase tracking-widest text-on-surface-dim font-medium mb-3">Observation Timeline</h3>
          <p className="text-sm text-on-surface-variant">
            Observed {detail.occurrences} time{detail.occurrences !== 1 ? 's' : ''} · First seen: {formatNonAmbiguousDate(detail.first_seen)}
          </p>
        </section>
      ) : null}

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
              <td className="px-5 py-3 text-on-surface-dim text-xs" title={timeSince(obs.ts_observed)}>{formatNonAmbiguousDate(obs.ts_observed)}</td>
              <td className="px-5 py-3">
                <Link
                  to={`/conversations/${obs.conv_id}`}
                  className="text-accent hover:underline text-sm"
                >
                  {obs.conv_subject ?? obs.conv_id.slice(0, 8)}
                </Link>
              </td>
              <td className="px-5 py-3">
                <span className={`text-xs px-2 py-0.5 rounded font-medium ${scamTypeColor(obs.conv_scam_type)}`}>
                  {scamTypeLabel(obs.conv_scam_type)}
                </span>
              </td>
              <td className="px-5 py-3 text-xs text-on-surface-dim font-mono">{obs.extraction_method === 'llm' ? 'LLM' : obs.extraction_method === 'regex' ? 'Regex' : obs.extraction_method === 'header' ? 'Header' : obs.extraction_method}</td>
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
            const sev = scoreSeverity(rel.type, rel.score?.vt ?? 0, rel.score?.urlscan ?? 0);
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
                  <span className={`text-xs font-bold ${sev.color}`}>{sev.label}</span>
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
          {s.scam_type && <MetaField label={t('iocContext.scamType')} value={scamTypeLabel(s.scam_type)} />}
          {s.persona_code && <MetaField label={t('iocContext.persona')} value={s.persona_label ?? s.persona_code} />}
          {s.extraction_method && <MetaField label={t('iocContext.extraction')} value={s.extraction_method === 'llm' ? 'LLM' : s.extraction_method === 'regex' ? 'Regex' : s.extraction_method === 'header' ? 'Header' : s.extraction_method} />}
          {s.engagement_hours != null && <MetaField label={t('iocContext.engagement')} value={s.engagement_hours === 0 ? 'Turn 1 · Initial email' : `${s.engagement_hours}h`} />}
        </div>
      </div>

      {/* Enriched sections — reordered: Excerpt → Role → Signals → Metadata */}
      {status === 'enriched' && ctx.semantic && (
        <>
          {/* Context Excerpt — first (most impactful for CTI triage) */}
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

          {/* Semantic Role */}
          <div className="border-t border-surface-high p-5 space-y-4">
            <h3 className="text-xs font-bold text-on-surface-dim uppercase tracking-widest">
              {t('iocContext.semanticRole')}
            </h3>
            {ctx.semantic.role && (
              <div className="flex items-center gap-3">
                <span className={`text-sm font-bold px-3 py-1 rounded ${ROLE_COLORS[ctx.semantic.role] ?? ROLE_COLORS.UNKNOWN}`}>
                  {humanize(ctx.semantic.role)}
                </span>
                {ctx.semantic.enrichment_confidence != null && (
                  <span className={`text-xs ${ctx.semantic.enrichment_confidence >= 0.7 ? 'text-success' : 'text-warning'}`}>
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
                  {ctx.semantic.hesitation_detected ? '● Detected' : '○ Not detected'}
                </span>
              </div>
              <div>
                <label className="text-xs text-on-surface-dim block mb-1">{t('iocContext.languageSwitch')}</label>
                <span className={`text-xs font-medium ${ctx.semantic.language_switch ? 'text-warning' : 'text-on-surface-dim'}`}>
                  {ctx.semantic.language_switch ? '● Detected' : '○ Not detected'}
                </span>
              </div>
            </div>
          </div>
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
        {ctx.computed_at && <span>{t('iocContext.computedAt')}: {formatNonAmbiguousDate(ctx.computed_at)}</span>}
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

function ScoringExplain({ score, ageDays }: { score: { vt?: number; urlscan?: number; explain?: string }; ageDays: number }) {
  const explain = score.explain ?? '';
  const noExternal = (score.vt ?? 0) === 0 && (score.urlscan ?? 0) === 0;

  if (noExternal && ageDays < 7) {
    return (
      <p className="text-sm text-warning bg-warning/10 rounded-lg p-3 mt-2">
        No external detections — recent indicator, scanners may not have indexed it yet.
      </p>
    );
  }
  if (noExternal && ageDays > 30) {
    return (
      <p className="text-sm text-on-surface-dim bg-surface-base rounded-lg p-3 mt-2">
        No external detections after {ageDays} days.
      </p>
    );
  }
  if (explain) {
    return (
      <p className="text-sm text-on-surface-variant bg-surface-base rounded-lg p-3 mt-2">
        {explain}
      </p>
    );
  }
  return null;
}

export default IocDetail;
