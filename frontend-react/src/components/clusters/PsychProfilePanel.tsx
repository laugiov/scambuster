import { useClusterPsychProfile } from '@/hooks/useClusterPsychProfile';

interface PsychProfilePanelProps {
  clusterId: string;
}

const LEVER_BLURB: Record<string, string> = {
  Authority: 'leans on rank, titles or institutions',
  Urgency: 'pushes tight deadlines and last-chance framing',
  Scarcity: 'emphasises rarity and limited availability',
  Secrecy: 'demands the exchange stay private',
  Reciprocity: 'offers a "favour" to create obligation',
  Liking: 'uses flattery and fake intimacy',
  SocialProof: 'cites others who already complied',
  None: 'no dominant influence principle detected',
};

// Each Cialdini lever gets its own hue, so an actor's manipulation style is legible
// by colour (Urgency = red alarm, Authority = blue, Scarcity = amber, Secrecy = violet…).
const LEVER_SOLID: Record<string, string> = {
  Authority: 'bg-info text-white shadow-info/40',
  Urgency: 'bg-error text-white shadow-error/40',
  Scarcity: 'bg-warning text-surface-base shadow-warning/40',
  Secrecy: 'bg-violet-500 text-white shadow-violet-500/40',
  Reciprocity: 'bg-success text-surface-base shadow-success/40',
  Liking: 'bg-pink-500 text-white shadow-pink-500/40',
  SocialProof: 'bg-accent text-on-accent shadow-accent/40',
  None: 'bg-surface-high text-on-surface',
};
const LEVER_TINT: Record<string, string> = {
  Authority: 'border-info/50 bg-info/10 text-info',
  Urgency: 'border-error/50 bg-error/10 text-error',
  Scarcity: 'border-warning/50 bg-warning/10 text-warning',
  Secrecy: 'border-violet-500/50 bg-violet-500/10 text-violet-400',
  Reciprocity: 'border-success/50 bg-success/10 text-success',
  Liking: 'border-pink-500/50 bg-pink-500/10 text-pink-400',
  SocialProof: 'border-accent/50 bg-accent/10 text-accent',
  None: 'border-border bg-surface text-on-surface-dim',
};

function Chip({ label }: { label: string }) {
  return (
    <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium ${LEVER_TINT[label] ?? 'border-accent/30 bg-accent/5 text-accent'}`}>
      {label}
    </span>
  );
}

/** A behavioural signal tile; `tone` gives it a category colour, `hint` a hover explainer. */
function Signal({ label, value, tone, hint }: { label: string; value: React.ReactNode; tone?: 'accent' | 'warning' | 'info'; hint?: string }) {
  const border =
    tone === 'warning' ? 'border-warning/40 bg-warning/10' : tone === 'info' ? 'border-info/40 bg-info/10' : tone === 'accent' ? 'border-accent/40 bg-accent/10' : 'border-border bg-surface';
  const val = tone === 'warning' ? 'text-warning' : tone === 'info' ? 'text-info' : tone === 'accent' ? 'text-accent' : 'text-on-surface';
  return (
    <div className={`rounded-md border px-3 py-2 ${border}`} title={hint}>
      <div className="flex items-center gap-1 text-[10px] uppercase tracking-wide text-on-surface-dim">
        {label}
        {hint && <span className="text-on-surface-dim/60" aria-hidden="true">ⓘ</span>}
      </div>
      <div className={`mt-0.5 text-sm font-semibold ${val}`}>{value}</div>
    </div>
  );
}

/**
 * "Psychological Profile" panel on the cluster (threat-actor) detail page.
 * The persisted per-actor fingerprint (dominant Cialdini lever + behavioural
 * narrative), generated offline by app:actor:compute-psych-profiles.
 */
export function PsychProfilePanel({ clusterId }: PsychProfilePanelProps) {
  const { data: profile, isLoading } = useClusterPsychProfile(clusterId);

  if (isLoading) {
    return null;
  }

  if (!profile) {
    return (
      <section
        data-testid="psych-profile-empty"
        className="rounded-lg border border-dashed border-border bg-surface-low px-5 py-4 text-sm text-on-surface-dim"
      >
        Psychological profile not generated yet for this actor.
      </section>
    );
  }

  const leverBlurb = LEVER_BLURB[profile.dominant_lever] ?? '';
  const urgency = profile.avg_urgency;
  // Each signal tile keeps a permanent category colour (so a calm actor is still
  // legible, not four gray boxes); urgency escalates to amber only when it's high.
  const urgencyTone = urgency >= 0.6 ? 'warning' : 'accent';

  return (
    <section data-testid="psych-profile" className="overflow-hidden rounded-lg border border-border bg-surface-low">
      <div className="flex items-center justify-between border-b border-accent/25 bg-accent/10 px-5 py-2.5">
        <h2 className="flex items-center gap-2.5 text-sm font-semibold uppercase tracking-wide text-accent" title="How this actor manipulates — the persisted psychological fingerprint (Cialdini influence levers), aggregated across all their messages.">
          <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-accent/20 text-accent">
            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
            </svg>
          </span>
          Psychological Profile
        </h2>
        <span className="text-[11px] text-on-surface-dim">
          {profile.generated_by_model} · {new Date(profile.generated_at).toLocaleDateString('en-GB')}
        </span>
      </div>
      <div className="px-5 pb-4 pt-3">

      {/* Hero: the dominant lever, big and accented — the headline of "how they manipulate". */}
      <div className="mt-3 flex flex-wrap items-center gap-2">
        <span
          data-testid="psych-dominant-lever"
          title="The actor's dominant Cialdini influence principle — the single best summary of how they pressure a victim."
          className={`inline-flex items-center rounded-full px-4 py-1.5 text-base font-semibold shadow-sm ${LEVER_SOLID[profile.dominant_lever] ?? 'bg-accent text-on-accent shadow-accent/30'}`}
        >
          {profile.dominant_lever}
        </span>
        {leverBlurb && <span className="text-xs text-on-surface-variant">{leverBlurb}</span>}
        {profile.secondary_levers.length > 0 && (
          <span className="ml-1 flex flex-wrap gap-1.5">
            {profile.secondary_levers.map((lever) => (
              <Chip key={lever} label={lever} />
            ))}
          </span>
        )}
      </div>

      {profile.behavioural_summary && (
        <p className="mt-3 text-sm leading-relaxed text-on-surface">{profile.behavioural_summary}</p>
      )}

      <dl className="mt-3 grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
        <div className="flex gap-2" title="How the actor's pressure evolves across turns: rapid (boiler-room), gradual, stable (patient/organised) or erratic.">
          <dt className="text-on-surface-dim">Escalation:</dt>
          <dd className="font-medium text-on-surface">{profile.escalation_pattern}</dd>
        </div>
        {profile.victim_targeting && (
          <div className="flex gap-2" title="Who this actor preys on — who to warn, and which function is exposed.">
            <dt className="text-on-surface-dim">Targets:</dt>
            <dd className="font-medium text-on-surface">{profile.victim_targeting}</dd>
          </div>
        )}
      </dl>

      {/* Behavioural signals — coloured only when they carry a signal (urgency high, hesitation/switch present). */}
      <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
        <Signal label="Stimulus" value={profile.dominant_stimulus ?? '--'} tone="accent" hint="The dominant psychological stimulus the actor leans on, measured across their messages." />
        <Signal label="Avg urgency" value={urgency.toFixed(2)} tone={urgencyTone} hint="Mean urgency (0–1) across the actor's messages. High urgency with zero hesitation = a confident, scripted operator." />
        <Signal label="Hesitations" value={profile.hesitation_events} tone="info" hint="Number of hesitation moments detected — a scammer wavering can signal a less rehearsed, opportunistic actor." />
        <Signal label="Lang switches" value={profile.language_switches} tone="info" hint="Number of language switches across the conversation — a multilingual operation or copy-pasted templates." />
      </div>
      </div>
    </section>
  );
}
