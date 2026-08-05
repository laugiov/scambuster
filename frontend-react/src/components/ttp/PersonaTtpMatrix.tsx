import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { usePersonaTtpMatrix } from '@/hooks/useTtps';
import { ttpPhaseColor, ttpPhaseLabel } from '@/lib/ttpLabels';
import type { PersonaTtpMatrix as PersonaTtpMatrixData, MatrixTtpColumn } from '@/types/ttp';

/**
 * Persona × TTP matrix: personas (rows) × observed TTPs (columns), each cell the
 * number of the persona's conversations exhibiting the TTP. Clones the
 * ClusterTtpMatrix "row|col" Map skeleton (sparse grid, sticky first column,
 * alpha shading, truncated note) and adds the PersonaMatrix honesty rules: the
 * cell value is the fair per-conversation count (observation counts inflate on
 * chatty conversations, stated in a footnote), and a persona row whose
 * TTP-carrying conversation total sits below MIN_CONVERSATIONS_FOR_HEADLINE is
 * dimmed as provisional and never carries the count shading. Null-persona
 * conversations are excluded from the grid and reported in a footnote. Loads/
 * empties/errors degrade to a note, never a hard error.
 */

// Mirrors PersonaMatrix.tsx MIN_SESSIONS_FOR_HEADLINE (3): below this a row is
// too thin to read as a headline, so it is dimmed and never highlighted.
const MIN_CONVERSATIONS_FOR_HEADLINE = 3;

export function PersonaTtpMatrix() {
  const { t } = useTranslation();
  const { data, isLoading, isError } = usePersonaTtpMatrix();

  const grid = useMemo(() => buildGrid(data ?? undefined), [data]);

  if (isLoading) return null;

  return (
    <section className="bg-surface-low rounded-lg p-5 space-y-3" data-testid="persona-ttp-matrix">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 className="text-sm font-medium text-on-surface">{t('ttpMatrix.personaTitle')}</h3>
          <p className="text-xs text-on-surface-dim mt-0.5">{t('ttpMatrix.personaSubtitle')}</p>
        </div>
      </div>

      {isError || !data || grid.personas.length === 0 || grid.ttps.length === 0 ? (
        <p className="text-sm text-on-surface-dim italic" data-testid="persona-ttp-matrix-empty">
          {t('ttpMatrix.personaEmpty')}
        </p>
      ) : (
        <>
          <div className="overflow-x-auto">
            <table className="w-full text-xs border-collapse" data-testid="persona-ttp-matrix-table">
              <thead>
                <tr>
                  <th className="text-left p-2 text-on-surface-dim sticky left-0 bg-surface-low z-10">
                    {t('ttpMatrix.personaColumn')}
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
                {grid.personas.map((persona) => {
                  const provisional = persona.conversation_total < MIN_CONVERSATIONS_FOR_HEADLINE;
                  return (
                    <tr
                      key={persona.code}
                      data-testid="persona-ttp-matrix-row"
                      data-provisional={provisional ? 'true' : 'false'}
                      className="border-t border-outline-variant/30"
                    >
                      <td
                        className={`p-2 sticky left-0 bg-surface-low z-10 whitespace-nowrap ${provisional ? 'text-on-surface-dim/60 italic' : 'text-on-surface'}`}
                        title={t('ttpMatrix.personaConvTotal', { count: persona.conversation_total })}
                      >
                        <span>{persona.label}</span>
                        <span className="ml-1.5 text-[10px] text-on-surface-dim font-mono">
                          {persona.conversation_total.toLocaleString()}
                        </span>
                        {provisional && (
                          <span className="ml-1.5 text-[10px] text-on-surface-dim/70">
                            {t('ttpMatrix.provisionalTag')}
                          </span>
                        )}
                      </td>
                      {grid.ttps.map((ttp) => {
                        const cell = grid.byCell.get(`${persona.code}|${ttp.code}`);
                        return (
                          <PersonaCell
                            key={ttp.code}
                            cell={cell}
                            maxCount={grid.maxCount}
                            provisional={provisional}
                            title={
                              cell
                                ? `${persona.label} · ${ttp.code} · ${t('ttpMatrix.cellConversations', { count: cell.conversation_count })} · ${t('ttpMatrix.cellObservations', { count: cell.observation_count })}`
                                : `${persona.label} · ${ttp.code} · ${t('ttpMatrix.cellEmpty')}`
                            }
                          />
                        );
                      })}
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          <p className="text-[11px] text-on-surface-dim italic" data-testid="persona-ttp-matrix-normalizer">
            {t('ttpMatrix.normalizerNote')}
          </p>
          <p className="text-[11px] text-on-surface-dim italic" data-testid="persona-ttp-matrix-threshold">
            {t('ttpMatrix.thresholdNote', { threshold: MIN_CONVERSATIONS_FOR_HEADLINE })}
          </p>
          {data.null_persona_conversations > 0 && (
            <p className="text-[11px] text-on-surface-dim italic" data-testid="persona-ttp-matrix-null-note">
              {t('ttpMatrix.nullPersonaNote', { count: data.null_persona_conversations })}
            </p>
          )}
          {data.truncated && (
            <p className="text-[11px] text-on-surface-dim italic" data-testid="persona-ttp-matrix-truncated">
              {t('ttpMatrix.personaTruncated', { shown: grid.personas.length, total: data.total_personas })}
            </p>
          )}
        </>
      )}
    </section>
  );
}

interface PersonaCellValue {
  conversation_count: number;
  observation_count: number;
}

function PersonaCell({
  cell,
  maxCount,
  provisional,
  title,
}: {
  cell?: PersonaCellValue;
  maxCount: number;
  provisional: boolean;
  title: string;
}) {
  if (!cell || cell.conversation_count <= 0) {
    return (
      <td className="p-2 text-center text-on-surface-dim/30 font-mono" title={title} data-testid="persona-ttp-matrix-cell">
        ·
      </td>
    );
  }
  // Provisional rows are never highlighted (PersonaMatrix honesty): show the
  // count dimmed, no shading — the reader must not read a headline into them.
  if (provisional) {
    return (
      <td
        className="p-2 text-center font-mono text-on-surface-dim/60 italic"
        title={title}
        data-testid="persona-ttp-matrix-cell"
      >
        {cell.conversation_count.toLocaleString()}
      </td>
    );
  }
  // Shade a headline cell by its share of the busiest cell (single teal hue;
  // the column header already carries the phase colour). Floor the alpha so the
  // smallest counts stay legible against the surface.
  const alpha = maxCount > 0 ? 0.14 + 0.55 * (cell.conversation_count / maxCount) : 0.14;
  return (
    <td
      className="p-2 text-center font-mono text-on-surface font-medium"
      style={{ backgroundColor: `rgba(45, 212, 191, ${alpha.toFixed(3)})` }}
      title={title}
      data-testid="persona-ttp-matrix-cell"
    >
      {cell.conversation_count.toLocaleString()}
    </td>
  );
}

interface GridShape {
  personas: PersonaTtpMatrixData['personas'];
  ttps: MatrixTtpColumn[];
  byCell: Map<string, PersonaCellValue>;
  maxCount: number;
}

function buildGrid(data?: PersonaTtpMatrixData): GridShape {
  if (!data) {
    return { personas: [], ttps: [], byCell: new Map(), maxCount: 0 };
  }
  const provisionalCodes = new Set(
    data.personas.filter((p) => p.conversation_total < MIN_CONVERSATIONS_FOR_HEADLINE).map((p) => p.code),
  );
  const byCell = new Map<string, PersonaCellValue>();
  let maxCount = 0;
  for (const cell of data.cells) {
    byCell.set(`${cell.persona_code}|${cell.ttp_code}`, {
      conversation_count: cell.conversation_count,
      observation_count: cell.observation_count,
    });
    // The shading scale is driven by headline (non-provisional) rows only, so a
    // thin provisional row can never blow out the colour ramp.
    if (!provisionalCodes.has(cell.persona_code) && cell.conversation_count > maxCount) {
      maxCount = cell.conversation_count;
    }
  }
  return { personas: data.personas, ttps: data.ttps, byCell, maxCount };
}

export default PersonaTtpMatrix;
