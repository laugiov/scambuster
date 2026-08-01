import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { useTtpPhaseTransitions } from '@/hooks/useTtps';
import { PHASE_ORDER, ttpPhaseColor, ttpPhaseLabel } from '@/lib/ttpLabels';
import type { TtpPhaseTransitions } from '@/types/ttp';

/**
 * Kill-chain phase-transition matrix: phases (rows = from, columns = to) in
 * canonical PHASE_ORDER, each cell the number of confirmed cross-message TTP
 * pairs whose endpoints sit in those phases. Clones ClusterTtpMatrix's
 * "row|col" Map table: the payload is sparse (zero cells omitted by the
 * backend), absent cells render dimmed; present cells are shaded by their
 * share of the busiest cell. An unexpected phase returned by the backend
 * becomes an extra row/column instead of being silently dropped.
 * Loads/empties/errors degrade to a note, never a hard error.
 */
export function PhaseTransitionsMatrix() {
  const { t } = useTranslation();
  const { data, isLoading, isError } = useTtpPhaseTransitions();

  const grid = useMemo(() => buildGrid(data ?? undefined), [data]);

  if (isLoading) return null;

  return (
    <section className="bg-surface-low rounded-lg p-5 space-y-3" data-testid="ttp-phase-transitions">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 className="text-sm font-medium text-on-surface">{t('ttpPlaybooks.transitionsTitle')}</h3>
          <p className="text-xs text-on-surface-dim mt-0.5">{t('ttpPlaybooks.transitionsSubtitle')}</p>
        </div>
        {!!data && data.total_pairs > 0 && (
          <span className="text-xs text-on-surface-dim" data-testid="ttp-phase-transitions-total">
            {t('ttpPlaybooks.transitionsTotal', { count: data.total_pairs })}
          </span>
        )}
      </div>

      {isError || !data || data.total_pairs === 0 ? (
        <p className="text-sm text-on-surface-dim italic" data-testid="ttp-phase-transitions-empty">
          {t('ttpPlaybooks.transitionsEmpty')}
        </p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-xs border-collapse" data-testid="ttp-phase-transitions-table">
            <thead>
              <tr>
                <th className="text-left p-2 text-on-surface-dim sticky left-0 bg-surface-low z-10">
                  {t('ttpPlaybooks.transitionsFromColumn')}
                </th>
                {grid.phases.map((phase) => (
                  <th key={phase} className="p-2 font-normal align-bottom">
                    <span
                      className={`inline-flex items-center rounded px-1.5 py-0.5 text-[0.625rem] font-medium ${ttpPhaseColor(phase)}`}
                      title={ttpPhaseLabel(phase)}
                    >
                      {ttpPhaseLabel(phase)}
                    </span>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {grid.phases.map((fromPhase) => (
                <tr key={fromPhase} className="border-t border-outline-variant/30">
                  <td className="p-2 sticky left-0 bg-surface-low z-10 whitespace-nowrap">
                    <span
                      className={`inline-flex items-center rounded px-1.5 py-0.5 text-[0.625rem] font-medium ${ttpPhaseColor(fromPhase)}`}
                    >
                      {ttpPhaseLabel(fromPhase)}
                    </span>
                  </td>
                  {grid.phases.map((toPhase) => {
                    const count = grid.byCell.get(`${fromPhase}|${toPhase}`);
                    return (
                      <TransitionCell
                        key={toPhase}
                        count={count}
                        maxCount={grid.maxCount}
                        title={
                          count
                            ? `${ttpPhaseLabel(fromPhase)} → ${ttpPhaseLabel(toPhase)} · ${t('ttpPlaybooks.transitionsCellTooltip', { count })}`
                            : `${ttpPhaseLabel(fromPhase)} → ${ttpPhaseLabel(toPhase)} · ${t('ttpPlaybooks.transitionsCellEmpty')}`
                        }
                      />
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

function TransitionCell({ count, maxCount, title }: { count?: number; maxCount: number; title: string }) {
  if (!count) {
    return (
      <td
        className="p-2 text-center text-on-surface-dim/30 font-mono"
        title={title}
        data-testid="ttp-phase-transitions-cell"
      >
        ·
      </td>
    );
  }
  // Shade a present cell by its share of the busiest cell (single teal hue,
  // ClusterTtpMatrix formula). Floor the alpha so small counts stay legible.
  const alpha = maxCount > 0 ? 0.14 + 0.55 * (count / maxCount) : 0.14;
  return (
    <td
      className="p-2 text-center font-mono text-on-surface font-medium"
      style={{ backgroundColor: `rgba(45, 212, 191, ${alpha.toFixed(3)})` }}
      title={title}
      data-testid="ttp-phase-transitions-cell"
    >
      {count.toLocaleString()}
    </td>
  );
}

interface GridShape {
  phases: string[];
  byCell: Map<string, number>;
  maxCount: number;
}

function buildGrid(data?: TtpPhaseTransitions): GridShape {
  const phases: string[] = [...PHASE_ORDER];
  const byCell = new Map<string, number>();
  let maxCount = 0;

  for (const cell of data?.transitions ?? []) {
    if (!phases.includes(cell.from_phase)) phases.push(cell.from_phase);
    if (!phases.includes(cell.to_phase)) phases.push(cell.to_phase);
    byCell.set(`${cell.from_phase}|${cell.to_phase}`, cell.count);
    if (cell.count > maxCount) maxCount = cell.count;
  }

  return { phases, byCell, maxCount };
}

export default PhaseTransitionsMatrix;
