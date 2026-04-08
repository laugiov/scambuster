import type { ThreatActorSummary } from '@/hooks/useThreatActor';
import { scamTypeLabel, scamTypeColor } from '@/lib/scamTypeLabels';

const SOPHISTICATION_STYLES: Record<string, string> = {
  none: 'bg-surface-highest text-on-surface-variant',
  minimal: 'bg-warning/20 text-warning',
  intermediate: 'bg-orange-500/20 text-orange-400',
  advanced: 'bg-error/20 text-error',
};

export function ThreatActorSummaryCard({ summary }: { summary: ThreatActorSummary }) {
  const sophStyle = SOPHISTICATION_STYLES[summary.maxSophistication] ?? SOPHISTICATION_STYLES.none;

  return (
    <div className="bg-surface-low rounded-lg border border-white/5 px-4 py-3 space-y-2">
      {/* Header line */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <svg className="w-4 h-4 text-error" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
          </svg>
          <span className="text-sm font-semibold text-on-surface">Threat Actor</span>
          <span className="text-xs text-on-surface-dim">
            {summary.conversationCount} conversation{summary.conversationCount > 1 ? 's' : ''}
          </span>
        </div>
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium uppercase tracking-wider ${sophStyle}`}>
          {summary.maxSophistication}
        </span>
      </div>

      {/* Scam types + goals */}
      <div className="flex flex-wrap gap-1.5">
        {summary.scamTypes.map((st) => (
          <span key={st} className={`px-2 py-0.5 text-xs rounded ${scamTypeColor(st)}`}>
            {scamTypeLabel(st)}
          </span>
        ))}
        {summary.allGoals.map((goal) => (
          <span key={goal} className="px-2 py-0.5 bg-error/10 text-error text-xs rounded">
            {goal}
          </span>
        ))}
      </div>

      {/* MITRE techniques */}
      {summary.attackPatterns.length > 0 && (
        <div className="flex items-center gap-2">
          <svg className="w-3.5 h-3.5 text-warning shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
          </svg>
          <span className="text-xs text-on-surface-dim">
            {summary.attackPatterns.join(' / ')}
          </span>
        </div>
      )}
    </div>
  );
}
