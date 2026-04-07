import { useState } from 'react';
import type { ThreatActorProfile } from '@/types/threatActor';

const SOPHISTICATION_STYLES: Record<string, string> = {
  none: 'bg-surface-highest text-on-surface-variant',
  minimal: 'bg-warning/20 text-warning',
  intermediate: 'bg-orange-500/20 text-orange-400',
  advanced: 'bg-error/20 text-error',
};

function formatHours(hours: number): string {
  if (hours < 1) return `${Math.round(hours * 60)}min`;
  if (hours < 24) return `${hours.toFixed(1)}h`;
  return `${(hours / 24).toFixed(1)}d`;
}

function MetaRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-start justify-between py-1.5">
      <span className="text-xs text-on-surface-dim shrink-0">{label}</span>
      <span className="text-xs text-on-surface text-right ml-3">{children}</span>
    </div>
  );
}

export function ThreatActorCard({ profile }: { profile: ThreatActorProfile }) {
  const [expanded, setExpanded] = useState(false);

  const sophStyle = SOPHISTICATION_STYLES[profile.sophistication] ?? SOPHISTICATION_STYLES.none;

  return (
    <div className="bg-surface-low rounded-lg border border-white/5 overflow-hidden">
      {/* Header — always visible */}
      <button
        type="button"
        onClick={() => setExpanded(!expanded)}
        className="w-full flex items-center justify-between px-4 py-3 hover:bg-surface-high/50 transition-colors"
      >
        <div className="flex items-center gap-2">
          <svg className="w-4 h-4 text-error" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
          </svg>
          <span className="text-sm font-semibold text-on-surface">Threat Actor</span>
        </div>
        <div className="flex items-center gap-2">
          <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium uppercase tracking-wider ${sophStyle}`}>
            {profile.sophistication}
          </span>
          <svg
            className={`w-4 h-4 text-on-surface-dim transition-transform ${expanded ? 'rotate-180' : ''}`}
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            strokeWidth={1.5}
          >
            <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
          </svg>
        </div>
      </button>

      {/* Expanded content */}
      {expanded && (
        <div className="px-4 pb-4 space-y-3">
          {/* Goals */}
          <div className="flex flex-wrap gap-1.5">
            {profile.goals.map((goal) => (
              <span key={goal} className="px-2 py-0.5 bg-error/10 text-error text-xs rounded">
                {goal}
              </span>
            ))}
            <span className="px-2 py-0.5 bg-accent-muted/20 text-accent text-xs rounded">
              {profile.primaryMotivation}
            </span>
          </div>

          {/* MITRE ATT&CK */}
          {profile.attackPattern && (
            <div className="flex items-center gap-2 py-1.5 px-3 bg-surface-base rounded">
              <svg className="w-3.5 h-3.5 text-warning shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
              </svg>
              <a
                href={profile.attackPattern.url}
                target="_blank"
                rel="noopener noreferrer"
                className="text-xs text-accent hover:text-accent-hover underline"
              >
                [{profile.attackPattern.techniqueId}] {profile.attackPattern.name}
              </a>
            </div>
          )}

          {/* Description */}
          {profile.description && (
            <p className="text-xs text-on-surface-variant leading-relaxed">
              {profile.description}
            </p>
          )}

          {/* Metrics */}
          <div className="border-t border-white/5 pt-2">
            <MetaRow label="Engagement">{formatHours(profile.engagementHours)} / {profile.engagementTurns} turns</MetaRow>
            <MetaRow label="IOC diversity">{profile.iocTypeCount} types</MetaRow>
            <MetaRow label="Persona used">
              <span className="px-1.5 py-0.5 bg-surface-highest text-on-surface-variant rounded text-xs">
                {profile.personaUsed}
              </span>
            </MetaRow>
          </div>
        </div>
      )}
    </div>
  );
}
