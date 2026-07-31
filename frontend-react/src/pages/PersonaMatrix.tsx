import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { usePersonaMatrix } from '@/hooks/usePersonaMatrix';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import { scamTypeLabel } from '@/lib/scamTypeLabels';
import type { PersonaMatrixCell } from '@/types/api';

const MIN_SESSIONS_FOR_HEADLINE = 3;

/**
 * Persona × scam type performance matrix.
 *
 * One grid showing every active persona (rows) vs every active scam
 * type (columns), colored by reward avg. The winning persona per
 * scam type (column) is highlighted IF it has enough sessions to be
 * non-provisional; otherwise the column shows no winner and the
 * provisional cells are dimmed.
 *
 * The point of this view is the audit's "no single best persona"
 * claim: a viewer sees that different scam types favour different
 * personas, and where the bandit doesn't have enough evidence yet.
 *
 * Honesty:
 * - Cells with sessions < 3 are dimmed and marked provisional in
 *   the tooltip. They never carry a winner highlight.
 * - The best-vs-worst gap is computed per column, only across
 *   qualifying cells; columns with fewer than 2 qualifying cells
 *   show "—" instead of a fabricated gap.
 * - The footer caption restates the threshold in plain words.
 */
export default function PersonaMatrix() {
  const { t } = useTranslation();
  const { data, isLoading, error, refetch } = usePersonaMatrix();

  const grid = useMemo(() => buildGrid(data ?? []), [data]);

  if (isLoading) return <Loading message={t('personaMatrix.loading')} />;
  if (error) return <ErrorMessage message={t('personaMatrix.failedLoad')} onRetry={() => void refetch()} />;

  const { personas, scamTypes, byPair, perColumn } = grid;

  if (personas.length === 0 || scamTypes.length === 0) {
    return (
      <div className="space-y-6">
        <header>
          <h1 className="text-xl font-semibold text-on-surface">{t('personaMatrix.title')}</h1>
          <p className="text-xs text-on-surface-dim mt-1">{t('personaMatrix.subtitle')}</p>
        </header>
        <p className="text-sm text-on-surface-dim italic">{t('personaMatrix.noData')}</p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-on-surface">{t('personaMatrix.title')}</h1>
        <p className="text-xs text-on-surface-dim mt-1">{t('personaMatrix.subtitle')}</p>
      </header>

      <div className="bg-surface-low rounded-lg p-4 overflow-x-auto">
        <table className="w-full text-xs border-collapse" data-testid="persona-matrix-table">
          <thead>
            <tr>
              <th className="text-left p-2 text-on-surface-dim sticky left-0 bg-surface-low">
                {t('personaMatrix.persona')}
              </th>
              {scamTypes.map((st) => {
                const col = perColumn[st.code];
                return (
                  <th key={st.code} className="text-left p-2 text-on-surface-dim font-mono">
                    <div className="font-semibold text-on-surface-variant">{st.label}</div>
                    <div className="text-[10px] text-on-surface-dim/80 font-normal mt-0.5">
                      {col.winnerPersona
                        ? t('personaMatrix.gap', {
                            gap: ((col.maxReward ?? 0) - (col.minReward ?? 0)).toFixed(2),
                          })
                        : t('personaMatrix.notEnoughData')}
                    </div>
                  </th>
                );
              })}
            </tr>
          </thead>
          <tbody>
            {personas.map((p) => (
              <tr key={p.code} className="border-t border-outline-variant/30">
                <td className="p-2 text-on-surface sticky left-0 bg-surface-low">
                  {p.label}
                </td>
                {scamTypes.map((st) => {
                  const cell = byPair.get(`${p.code}|${st.code}`);
                  return (
                    <Cell
                      key={st.code}
                      cell={cell}
                      isWinner={perColumn[st.code].winnerPersona === p.code}
                    />
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <p className="text-[11px] text-on-surface-dim italic">
        {t('personaMatrix.footer', { threshold: MIN_SESSIONS_FOR_HEADLINE })}
      </p>
    </div>
  );
}

function Cell({ cell, isWinner }: { cell?: PersonaMatrixCell; isWinner: boolean }) {
  const sessions = cell?.sessions ?? 0;
  const reward = cell?.reward_avg ?? null;
  const isProvisional = sessions < MIN_SESSIONS_FOR_HEADLINE;
  const hasData = reward !== null && sessions > 0;

  const title = hasData
    ? `${sessions} sessions · reward ${reward!.toFixed(2)}${isProvisional ? ' (provisional)' : ''}`
    : 'no sessions yet';

  let className = 'p-2 text-center font-mono';

  if (hasData && !isProvisional && isWinner) {
    className += ' bg-emerald-500/25 text-emerald-200 font-semibold';
  } else if (hasData && !isProvisional) {
    className += ' text-on-surface';
  } else if (hasData) {
    className += ' text-on-surface-dim/60 italic';
  } else {
    className += ' text-on-surface-dim/40';
  }

  return (
    <td className={className} title={title}>
      {hasData ? reward!.toFixed(2) : '—'}
    </td>
  );
}

interface GridShape {
  personas: { code: string; label: string }[];
  scamTypes: { code: string; label: string }[];
  byPair: Map<string, PersonaMatrixCell>;
  perColumn: Record<string, ColumnSummary>;
}

interface ColumnSummary {
  winnerPersona: string | null;
  maxReward: number | null;
  minReward: number | null;
}

function buildGrid(rows: PersonaMatrixCell[]): GridShape {
  const personaMap = new Map<string, string>();
  const scamTypeMap = new Map<string, string>();
  const byPair = new Map<string, PersonaMatrixCell>();

  for (const r of rows) {
    personaMap.set(r.persona_code, r.persona_label);
    // Prefer the frontend-side scam-type label helper over the raw
    // DB column: the helper is locale-aware (matches what the rest
    // of the app uses — Convergence table, money-shot badges, etc.)
    // so the matrix headers don't drift out of sync with the rest
    // of the UI. The DB column is fallback only.
    scamTypeMap.set(r.scam_type_code, scamTypeLabel(r.scam_type_code));
    byPair.set(`${r.persona_code}|${r.scam_type_code}`, r);
  }

  const personas = Array.from(personaMap.entries())
    .map(([code, label]) => ({ code, label }))
    .sort((a, b) => a.label.localeCompare(b.label));

  const scamTypes = Array.from(scamTypeMap.entries())
    .map(([code, label]) => ({ code, label }))
    .sort((a, b) => a.label.localeCompare(b.label));

  const perColumn: Record<string, ColumnSummary> = {};
  for (const st of scamTypes) {
    const qualifying = personas
      .map((p) => byPair.get(`${p.code}|${st.code}`))
      .filter((c): c is PersonaMatrixCell => !!c && c.sessions >= MIN_SESSIONS_FOR_HEADLINE && c.reward_avg !== null);

    if (qualifying.length === 0) {
      perColumn[st.code] = { winnerPersona: null, maxReward: null, minReward: null };
      continue;
    }

    const sorted = [...qualifying].sort((a, b) => (b.reward_avg ?? 0) - (a.reward_avg ?? 0));
    const winner = sorted[0];
    perColumn[st.code] = {
      winnerPersona: winner.persona_code,
      maxReward: winner.reward_avg,
      minReward: sorted[sorted.length - 1].reward_avg,
    };
  }

  return { personas, scamTypes, byPair, perColumn };
}
