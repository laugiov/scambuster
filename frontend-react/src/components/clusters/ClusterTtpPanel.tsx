import { useTranslation } from 'react-i18next';
import { useClusterTtps } from '@/hooks/useTtps';
import { ttpPhaseColor, ttpPhaseLabel } from '@/lib/ttpLabels';

interface ClusterTtpPanelProps {
  clusterId: string;
}

/**
 * "TTP Profile" panel on the cluster (threat-actor) detail page. Self-fetching
 * from {clusterId} — mirrors PsychProfilePanel: own loading / empty states, and
 * a dashed-border empty panel both when the cluster is unknown (404 → null) and
 * when it simply has no confirmed TTP observations yet.
 *
 * Rows are pre-sorted by observation_count DESC by the backend; adjacent-pair
 * tactic sequences are surfaced as "A → B (×N)".
 */
export function ClusterTtpPanel({ clusterId }: ClusterTtpPanelProps) {
  const { t } = useTranslation();
  const { data: profile, isLoading } = useClusterTtps(clusterId);

  if (isLoading) {
    return null;
  }

  if (!profile || profile.ttps.length === 0) {
    return (
      <section
        data-testid="cluster-ttp-empty"
        className="rounded-lg border border-dashed border-border bg-surface-low px-5 py-4 text-sm text-on-surface-dim"
      >
        {t('ttp.cluster.empty')}
      </section>
    );
  }

  const totalObservations = profile.ttps.reduce((sum, row) => sum + row.observation_count, 0);

  return (
    <section data-testid="cluster-ttp" className="overflow-hidden rounded-lg border border-border bg-surface-low">
      <div className="flex items-center justify-between border-b border-accent/25 bg-accent/10 px-5 py-2.5">
        <h2
          className="flex items-center gap-2.5 text-sm font-semibold uppercase tracking-wide text-accent"
          title={t('ttp.cluster.tooltip')}
        >
          <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-accent/20 text-accent">
            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
            </svg>
          </span>
          {t('ttp.cluster.title')}
        </h2>
        <span className="text-[11px] text-on-surface-dim">
          {t('ttp.cluster.observations', { count: totalObservations })}
        </span>
      </div>

      <div className="space-y-4 px-5 pb-4 pt-3">
        <div className="space-y-1.5">
          {profile.ttps.map((row) => (
            <div
              key={row.ttp_code}
              data-testid="cluster-ttp-row"
              className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-border bg-surface px-3 py-2"
            >
              <span
                className={`inline-flex items-center rounded px-1.5 py-0.5 text-[0.625rem] font-medium ${ttpPhaseColor(row.phase)}`}
                title={ttpPhaseLabel(row.phase)}
              >
                {ttpPhaseLabel(row.phase)}
              </span>
              <span className="text-sm font-medium text-on-surface">{row.ttp_label}</span>
              <span className="font-mono text-[11px] text-on-surface-dim">{row.ttp_code}</span>
              <span className="ml-auto flex items-center gap-3 text-xs text-on-surface-dim">
                <span title={t('ttp.cluster.observationsTooltip')}>
                  {t('ttp.cluster.obs', { count: row.observation_count })}
                </span>
                <span title={t('ttp.cluster.conversationsTooltip')}>
                  {t('ttp.cluster.convs', { count: row.conversation_count })}
                </span>
                <span title={t('ttp.cluster.confidenceTooltip')} className="font-semibold text-on-surface-variant">
                  {Math.round(row.avg_confidence * 100)}%
                </span>
              </span>
            </div>
          ))}
        </div>

        {profile.top_sequences.length > 0 && (
          <div>
            <h3
              className="mb-1.5 text-[0.625rem] uppercase tracking-widest text-on-surface-dim"
              title={t('ttp.cluster.sequencesTooltip')}
            >
              {t('ttp.cluster.sequences')}
            </h3>
            <div className="flex flex-wrap gap-2">
              {profile.top_sequences.map((seq, idx) => (
                <span
                  key={`${seq.sequence.join('>')}-${idx}`}
                  data-testid="cluster-ttp-sequence"
                  className="inline-flex items-center gap-1 rounded-full border border-border bg-surface px-2.5 py-0.5 text-xs text-on-surface-variant"
                >
                  {seq.sequence.join(' → ')}
                  <span className="text-on-surface-dim">(×{seq.count})</span>
                </span>
              ))}
            </div>
          </div>
        )}
      </div>
    </section>
  );
}
