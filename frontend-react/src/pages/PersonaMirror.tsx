import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { usePersonaMatrix } from '@/hooks/usePersonaMatrix';
import { usePersonaMirrors } from '@/hooks/usePersonaMirrors';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import { scamTypeLabel } from '@/lib/scamTypeLabels';
import type { PersonaMatrixCell } from '@/types/api';

const MIN_SESSIONS_FOR_WINNER = 3;

/**
 * Spec 104 P3 — Cognitive Mirror panel.
 *
 * For each scam type, shows the current winning persona (computed
 * from the matrix data, gated by the same min-session threshold the
 * Personas KPI uses) and the LLM-generated editorial framing:
 *   - hunted victim profile (who this scam preys on)
 *   - cognitive lever (the emotional manipulation mechanism)
 *   - mirror explanation (why the persona matches)
 *
 * Honesty:
 * - The framing text is LLM-generated, NOT a measured behavioural
 *   signal. The global footer caveat says so explicitly, and every
 *   cell shows its generation date + model.
 * - Scam types with no qualifying persona display a "still
 *   exploring" message instead of a fabricated pairing.
 * - Scam types where the LLM batch hasn't been run for the winning
 *   persona show "generation pending" — recoverable by running
 *   `app:persona:compute-mirrors`.
 */
export default function PersonaMirror() {
  const { t } = useTranslation();
  const { data: matrix, isLoading: matrixLoading, error: matrixError, refetch } = usePersonaMatrix();

  // Compute the winning persona per scam type, with the same gate
  // the Personas KPI uses.
  const winnersByScamType = useMemo(() => computeWinners(matrix ?? []), [matrix]);
  const scamTypes = useMemo(() => Object.keys(winnersByScamType).sort(), [winnersByScamType]);

  const [selectedScamType, setSelectedScamType] = useState<string | null>(null);
  const activeScamType = selectedScamType ?? scamTypes[0] ?? null;
  const winner = activeScamType ? winnersByScamType[activeScamType] : null;

  // Fetch mirror data for the winner's persona.
  const { data: mirrors } = usePersonaMirrors(winner?.persona_code);
  const cell = mirrors?.find((m) => m.scam_type_code === activeScamType);

  if (matrixLoading) return <Loading message={t('personaMirror.loading')} />;
  if (matrixError) return <ErrorMessage message={t('personaMirror.failedLoad')} onRetry={() => void refetch()} />;

  if (scamTypes.length === 0) {
    return (
      <div className="space-y-6">
        <header>
          <h1 className="text-xl font-semibold text-on-surface">{t('personaMirror.title')}</h1>
          <p className="text-xs text-on-surface-dim mt-1">{t('personaMirror.subtitle')}</p>
        </header>
        <p className="text-sm text-on-surface-dim italic">{t('personaMirror.noData')}</p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-on-surface">{t('personaMirror.title')}</h1>
        <p className="text-xs text-on-surface-dim mt-1">{t('personaMirror.subtitle')}</p>
      </header>

      <div className="bg-surface-low rounded-lg p-5 space-y-4">
        <div className="flex items-center justify-between gap-3">
          <h2 className="text-xs font-bold text-on-surface-dim uppercase tracking-widest">
            {t('personaMirror.selectScamType')}
          </h2>
          <select
            value={activeScamType ?? ''}
            onChange={(e) => setSelectedScamType(e.target.value)}
            className="text-xs bg-surface-base text-on-surface rounded px-2 py-1 border-none cursor-pointer"
            style={{ colorScheme: 'dark' }}
            data-testid="persona-mirror-select"
          >
            {scamTypes.map((st) => {
              const w = winnersByScamType[st];
              return (
                <option key={st} value={st} className="bg-neutral-800 text-neutral-200">
                  {w.scam_type_label}{w.persona_code ? '' : ' (no winner yet)'}
                </option>
              );
            })}
          </select>
        </div>

        {!winner?.persona_code ? (
          <p
            className="text-sm text-amber-300/90 italic"
            data-testid="persona-mirror-no-winner"
          >
            {t('personaMirror.noWinnerYet', { scamType: winner?.scam_type_label ?? activeScamType ?? '' })}
          </p>
        ) : cell ? (
          <MirrorCell
            scamTypeLabel={winner.scam_type_label}
            personaLabel={winner.persona_label}
            sessions={winner.sessions}
            reward={winner.reward_avg}
            entry={cell}
            t={t}
          />
        ) : (
          <p
            className="text-sm text-on-surface-dim italic"
            data-testid="persona-mirror-pending"
          >
            {t('personaMirror.generationPending', { persona: winner.persona_label })}
          </p>
        )}
      </div>

      <p className="text-[11px] text-on-surface-dim italic">
        {t('personaMirror.caveat')}
      </p>
    </div>
  );
}

function MirrorCell({
  scamTypeLabel,
  personaLabel,
  sessions,
  reward,
  entry,
  t,
}: {
  scamTypeLabel: string;
  personaLabel: string;
  sessions: number;
  reward: number | null;
  entry: import('@/types/api').PersonaMirrorEntry;
  t: ReturnType<typeof useTranslation>['t'];
}) {
  return (
    <div className="space-y-4" data-testid="persona-mirror-cell">
      <div className="flex flex-col md:flex-row md:items-baseline md:gap-3">
        <p className="text-base text-on-surface">
          <span className="text-on-surface-dim text-xs uppercase tracking-widest">
            {t('personaMirror.scamType')}:
          </span>{' '}
          <span className="font-semibold">{scamTypeLabel}</span>
        </p>
        <p className="text-base text-on-surface mt-1 md:mt-0">
          <span className="text-on-surface-dim text-xs uppercase tracking-widest">
            {t('personaMirror.winningPersona')}:
          </span>{' '}
          <span className="font-semibold text-emerald-300">{personaLabel}</span>
          <span className="ml-2 text-xs text-on-surface-dim font-mono">
            ({sessions} sessions · reward {reward !== null ? reward.toFixed(2) : '—'})
          </span>
        </p>
      </div>

      <Field label={t('personaMirror.huntedVictim')} value={entry.hunted_victim_profile} />
      <Field label={t('personaMirror.cognitiveLever')} value={entry.cognitive_lever} highlight />
      <Field label={t('personaMirror.whyMirror')} value={entry.mirror_explanation} />

      <p className="text-[10px] text-on-surface-dim/70 font-mono">
        {t('personaMirror.generationFooter', {
          date: entry.generated_at.split(' ')[0],
          model: entry.generated_by_model,
          version: entry.prompt_version,
        })}
      </p>
    </div>
  );
}

function Field({ label, value, highlight = false }: { label: string; value: string; highlight?: boolean }) {
  return (
    <div>
      <p className="text-xs text-on-surface-dim uppercase tracking-widest mb-1">{label}</p>
      <p className={`text-sm ${highlight ? 'text-amber-300 font-semibold' : 'text-on-surface'}`}>
        {value}
      </p>
    </div>
  );
}

interface WinnerInfo {
  scam_type_code: string;
  scam_type_label: string;
  persona_code: string | null;
  persona_label: string;
  sessions: number;
  reward_avg: number | null;
}

function computeWinners(rows: PersonaMatrixCell[]): Record<string, WinnerInfo> {
  const byScamType = new Map<string, PersonaMatrixCell[]>();
  for (const r of rows) {
    const arr = byScamType.get(r.scam_type_code) ?? [];
    arr.push(r);
    byScamType.set(r.scam_type_code, arr);
  }

  const out: Record<string, WinnerInfo> = {};

  for (const [scamCode, cells] of byScamType.entries()) {
    const qualifying = cells.filter(
      (c) => c.sessions >= MIN_SESSIONS_FOR_WINNER && c.reward_avg !== null,
    );
    // Prefer the locale-aware frontend helper over the raw DB label
    // so the mirror screen matches the rest of the UI (matrix headers,
    // convergence chart selector, money-shot badges).
    const scamLabel = scamTypeLabel(scamCode);

    if (qualifying.length === 0) {
      out[scamCode] = {
        scam_type_code: scamCode,
        scam_type_label: scamLabel,
        persona_code: null,
        persona_label: '',
        sessions: 0,
        reward_avg: null,
      };
      continue;
    }

    const sorted = [...qualifying].sort((a, b) => (b.reward_avg ?? 0) - (a.reward_avg ?? 0));
    const top = sorted[0];
    out[scamCode] = {
      scam_type_code: scamCode,
      scam_type_label: scamLabel,
      persona_code: top.persona_code,
      persona_label: top.persona_label,
      sessions: top.sessions,
      reward_avg: top.reward_avg,
    };
  }

  return out;
}
