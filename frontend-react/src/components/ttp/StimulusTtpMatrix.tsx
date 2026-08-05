import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useStimulusTtpMatrix } from '@/hooks/useTtps';
import { ttpPhaseColor, ttpPhaseLabel } from '@/lib/ttpLabels';
import { stimulusColor, stimulusLabel } from '@/lib/stimulusLabels';
import type { StimulusTtpMatrix as StimulusTtpMatrixData, MatrixTtpColumn } from '@/types/ttp';

/**
 * Stimulus × TTP matrix: outbound stimulus types (rows) × observed TTPs
 * (columns), each cell the number of revelation messages where the stimulus and
 * the confirmed TTP co-occur. Clones the ClusterTtpMatrix "row|col" Map skeleton
 * (sparse grid, sticky first column, alpha shading) with TTP on the column axis,
 * to match the other matrices. The population is scoped to revelation messages
 * carrying an enriched stimulus context (L10): the honest scope sentence and its
 * size n are stated UNDER the matrix. UNKNOWN carries no signal, so its row is
 * collapsible via a toggle (shown by default — nothing is hidden silently).
 * Loads/empties/errors degrade to a note, never a hard error.
 */
export function StimulusTtpMatrix() {
  const { t } = useTranslation();
  const { data, isLoading, isError } = useStimulusTtpMatrix();
  const [showUnknown, setShowUnknown] = useState(true);

  const grid = useMemo(() => buildGrid(data ?? undefined), [data]);

  const hasUnknown = grid.stimuli.includes('UNKNOWN');
  const rows = showUnknown ? grid.stimuli : grid.stimuli.filter((s) => s !== 'UNKNOWN');

  if (isLoading) return null;

  return (
    <section className="bg-surface-low rounded-lg p-5 space-y-3" data-testid="stimulus-ttp-matrix">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 className="text-sm font-medium text-on-surface">{t('ttpMatrix.stimulusTitle')}</h3>
          <p className="text-xs text-on-surface-dim mt-0.5">{t('ttpMatrix.stimulusSubtitle')}</p>
        </div>
        {hasUnknown && (
          <button
            type="button"
            data-testid="stimulus-ttp-matrix-unknown-toggle"
            aria-pressed={showUnknown}
            onClick={() => setShowUnknown((v) => !v)}
            className="px-3 py-1 text-xs rounded-full transition-colors cursor-pointer bg-surface-high hover:bg-surface-highest text-on-surface-variant"
          >
            {showUnknown ? t('ttpMatrix.collapseUnknown') : t('ttpMatrix.expandUnknown')}
          </button>
        )}
      </div>

      {isError || !data || rows.length === 0 || grid.ttps.length === 0 ? (
        <p className="text-sm text-on-surface-dim italic" data-testid="stimulus-ttp-matrix-empty">
          {t('ttpMatrix.stimulusEmpty')}
        </p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-xs border-collapse" data-testid="stimulus-ttp-matrix-table">
            <thead>
              <tr>
                <th className="text-left p-2 text-on-surface-dim sticky left-0 bg-surface-low z-10">
                  {t('ttpMatrix.stimulusColumn')}
                </th>
                {grid.ttps.map((ttp) => (
                  <th key={ttp.code} className="p-2 font-normal align-bottom">
                    <span
                      className={`inline-flex items-center rounded px-1.5 py-0.5 font-mono text-[0.625rem] font-medium ${ttpPhaseColor(ttp.phase)}`}
                      title={`${ttp.label} · ${ttpPhaseLabel(ttp.phase)}`}
                    >
                      {ttp.code}
                    </span>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((stimulus) => (
                <tr
                  key={stimulus}
                  data-testid="stimulus-ttp-matrix-row"
                  data-stimulus={stimulus}
                  className="border-t border-outline-variant/30"
                >
                  <td className="p-2 sticky left-0 bg-surface-low z-10 whitespace-nowrap">
                    <span className={`inline-flex items-center rounded px-1.5 py-0.5 text-[0.625rem] font-medium ${stimulusColor(stimulus)}`}>
                      {stimulusLabel(stimulus, t)}
                    </span>
                  </td>
                  {grid.ttps.map((ttp) => {
                    const cell = grid.byCell.get(`${stimulus}|${ttp.code}`);
                    return (
                      <StimulusCell
                        key={ttp.code}
                        cell={cell}
                        maxCount={grid.maxCount}
                        title={
                          cell
                            ? `${stimulusLabel(stimulus, t)} · ${ttp.code} · ${t('ttpMatrix.cellMessages', { count: cell.message_count })} · ${t('ttpMatrix.cellConversations', { count: cell.conversation_count })}`
                            : `${stimulusLabel(stimulus, t)} · ${ttp.code} · ${t('ttpMatrix.cellEmpty')}`
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

      {!!data && (
        <p className="text-[11px] text-on-surface-dim italic" data-testid="stimulus-ttp-matrix-population">
          {t('ttpMatrix.populationNote', { count: data.population_messages })}
        </p>
      )}
    </section>
  );
}

interface StimulusCellValue {
  message_count: number;
  conversation_count: number;
}

function StimulusCell({ cell, maxCount, title }: { cell?: StimulusCellValue; maxCount: number; title: string }) {
  if (!cell || cell.message_count <= 0) {
    return (
      <td className="p-2 text-center text-on-surface-dim/30 font-mono" title={title} data-testid="stimulus-ttp-matrix-cell">
        ·
      </td>
    );
  }
  // Shade by the cell's share of the busiest meaningful cell (single teal hue;
  // the column header carries the phase colour). Clamp the ratio so a collapsed-
  // by-default UNKNOWN row, when expanded, can never blow out the ramp.
  const ratio = maxCount > 0 ? Math.min(1, cell.message_count / maxCount) : 0;
  const alpha = 0.14 + 0.55 * ratio;
  return (
    <td
      className="p-2 text-center font-mono text-on-surface font-medium"
      style={{ backgroundColor: `rgba(45, 212, 191, ${alpha.toFixed(3)})` }}
      title={title}
      data-testid="stimulus-ttp-matrix-cell"
    >
      {cell.message_count.toLocaleString()}
    </td>
  );
}

interface GridShape {
  stimuli: string[];
  ttps: MatrixTtpColumn[];
  byCell: Map<string, StimulusCellValue>;
  maxCount: number;
}

function buildGrid(data?: StimulusTtpMatrixData): GridShape {
  if (!data) {
    return { stimuli: [], ttps: [], byCell: new Map(), maxCount: 0 };
  }
  const byCell = new Map<string, StimulusCellValue>();
  let maxCount = 0;
  for (const cell of data.cells) {
    byCell.set(`${cell.stimulus_type}|${cell.ttp_code}`, {
      message_count: cell.message_count,
      conversation_count: cell.conversation_count,
    });
    // UNKNOWN carries no signal and can dominate; keep it out of the shading
    // scale so the meaningful stimuli stay legible whether or not it is shown.
    if (cell.stimulus_type !== 'UNKNOWN' && cell.message_count > maxCount) {
      maxCount = cell.message_count;
    }
  }
  return { stimuli: data.stimuli, ttps: data.ttps, byCell, maxCount };
}

export default StimulusTtpMatrix;
