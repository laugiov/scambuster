import { useClusterAbuseReport } from '@/hooks/useClusterAbuseReport';
import type { AbuseReportIndicator } from '@/types/threatActor';
import { iocFamilyBadge, sophisticationBadge } from '@/lib/actorColors';

interface AbuseReportPanelProps {
  clusterId: string;
}

function Chip({ children, tone, title }: { children: React.ReactNode; tone?: 'accent' | 'warning' | 'success'; title?: string }) {
  const cls =
    tone === 'accent'
      ? 'border-accent/40 bg-accent/10 text-accent'
      : tone === 'warning'
        ? 'border-warning/40 bg-warning/10 text-warning'
        : tone === 'success'
          ? 'border-success/40 bg-success/10 text-success'
          : 'border-border bg-surface text-on-surface';
  return (
    <span title={title} className={`inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs ${cls}`}>{children}</span>
  );
}

/** Seconds of wasted scammer time → a compact human label ("45m", "2.5h", "1.3d"). */
function formatWastedTime(seconds: number): string {
  if (seconds < 3600) return `${Math.max(0, Math.round(seconds / 60))}m`;
  if (seconds < 86400) return `${(seconds / 3600).toFixed(1)}h`;
  return `${(seconds / 86400).toFixed(1)}d`;
}

function IndicatorRow({ ind }: { ind: AbuseReportIndicator }) {
  return (
    <li
      title="An actionable indicator extracted from the actor's own messages, routed to the standard abuse desk for its type (routine CTI practice — not a claim about a specific entity)."
      className="flex flex-col gap-1 rounded-md border border-border bg-surface px-3 py-2 sm:flex-row sm:items-center sm:gap-3"
    >
      <span className={`inline-flex w-fit shrink-0 items-center rounded border px-1.5 py-0.5 text-[10px] font-semibold uppercase ${iocFamilyBadge(ind.type)}`}>
        {ind.type}
      </span>
      <code className="min-w-0 flex-1 truncate font-mono text-xs text-on-surface" title={ind.value}>
        {ind.value}
      </code>
      <span className="flex items-center gap-1.5 text-xs text-on-surface-muted">
        <svg className="h-3.5 w-3.5 shrink-0 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
          <path strokeLinecap="round" strokeLinejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
        </svg>
        <span className="text-on-surface-variant">{ind.recommended_recipient}</span>
        {ind.conv_count > 0 && <span className="text-on-surface-muted">· {ind.conv_count} conv</span>}
      </span>
    </li>
  );
}

/**
 * "Abuse / Takedown Report" panel on the cluster detail page. On demand
 * (GET /clusters/{id}/abuse-report) it assembles the factual, first-party report
 * and presents it scannably — evidence at a glance, indicators routed to their
 * abuse desks — with the full plain-text body available to download / expand.
 */
export function AbuseReportPanel({ clusterId }: AbuseReportPanelProps) {
  // Auto-generated on page load — the report is DB-only (no LLM), so it's ready
  // to read (and demo) the moment the cluster opens, no click required.
  const { data: report, isLoading, isError } = useClusterAbuseReport(clusterId, true);

  function handleDownload() {
    if (!report) return;
    const blob = new Blob([report.text], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `abuse-report-${clusterId.slice(0, 8)}.txt`;
    a.click();
    URL.revokeObjectURL(url);
  }

  return (
    <section data-testid="abuse-report-panel" className="overflow-hidden rounded-lg border border-border bg-surface-low">
      <div className="flex items-center justify-between gap-3 border-b border-warning/25 bg-warning/10 px-5 py-2.5">
        <h2 className="flex items-center gap-2.5 text-sm font-semibold uppercase tracking-wide text-warning" title="What to do about this actor — a factual, first-party abuse / takedown report; each actionable indicator is routed to the standard desk that can act on it.">
          <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-warning/20 text-warning">
            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
          </span>
          Abuse / Takedown Report
        </h2>
        <div className="flex shrink-0 items-center gap-2">
          {report && (
            <button
              type="button"
              data-testid="abuse-report-download"
              onClick={handleDownload}
              className="flex items-center gap-1.5 rounded-lg bg-surface-high px-3 py-2 text-xs font-medium text-on-surface-variant transition-colors hover:text-accent"
            >
              <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
              </svg>
              Download .txt
            </button>
          )}
        </div>
      </div>

      <div className="px-5 pb-4 pt-3">
      <p className="text-xs text-on-surface-muted">Factual first-party report — each indicator routed to the desk that can action it.</p>

      {isLoading && <p className="mt-3 text-sm text-on-surface-muted">Assembling report…</p>}
      {isError && <p className="mt-3 text-sm text-error">Could not generate the report.</p>}
      {!isLoading && !isError && !report && (
        <p className="mt-3 text-sm text-on-surface-muted">No report available for this cluster.</p>
      )}

      {report && (
        <div className="mt-3 space-y-3">
          {/* Actor + evidence at a glance. */}
          <div className="rounded-md border border-border bg-surface px-3 py-2.5">
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-base font-semibold text-on-surface">{report.actor.name}</span>
              {report.actor.sophistication && (
                <span className={`rounded-full border px-2 py-0.5 text-[11px] font-medium capitalize ${sophisticationBadge(report.actor.sophistication)}`}>
                  {report.actor.sophistication}
                </span>
              )}
            </div>
            <div className="mt-2 flex flex-wrap gap-1.5">
              <Chip>{report.evidence.conversation_count} conversations</Chip>
              <Chip>{report.evidence.inbound_message_count} inbound msgs</Chip>
              <Chip tone="accent">{report.evidence.actionable_indicator_count} actionable IOCs</Chip>
              {report.evidence.criminal_time_wasted_sec > 0 && (
                <Chip tone="success" title="Total time the actor was kept engaged on the honeypot, summed across the cluster's conversations — time they could not spend on real victims.">
                  ⏱ {formatWastedTime(report.evidence.criminal_time_wasted_sec)} wasted
                </Chip>
              )}
              {report.temporal && report.temporal.burst_count > 0 && (
                <Chip tone="warning">⚡ {report.temporal.burst_count} burst days</Chip>
              )}
            </div>
            {report.scam_types.length > 0 && (
              <div className="mt-2 flex flex-wrap gap-1.5">
                {report.scam_types.map((s) => (
                  <span key={s} className="rounded border border-border px-1.5 py-0.5 text-[10px] font-medium uppercase text-on-surface-variant">
                    {s}
                  </span>
                ))}
              </div>
            )}
          </div>

          {/* Actionable indicators, routed. */}
          {report.actionable_indicators.length > 0 && (
            <div>
              <div className="mb-1.5 text-[11px] uppercase tracking-wide text-on-surface-muted">
                Actionable indicators — report each to:
              </div>
              <ul className="space-y-1.5" data-testid="abuse-report-indicators">
                {report.actionable_indicators.map((ind, i) => (
                  <IndicatorRow key={`${ind.type}-${ind.value}-${i}`} ind={ind} />
                ))}
              </ul>
            </div>
          )}

          {/* Full plain-text report — collapsible, downloadable. */}
          <details className="group rounded-md border border-border bg-surface">
            <summary className="cursor-pointer select-none px-3 py-2 text-xs font-medium text-on-surface-variant hover:text-accent">
              Full report text (ready to paste into an abuse complaint)
            </summary>
            <pre
              data-testid="abuse-report-text"
              className="max-h-80 overflow-auto whitespace-pre-wrap border-t border-border px-3 py-2 font-mono text-xs leading-relaxed text-on-surface"
            >
              {report.text}
            </pre>
          </details>

          <p className="text-[11px] italic text-on-surface-muted">{report.disclaimer}</p>
        </div>
      )}
      </div>
    </section>
  );
}
