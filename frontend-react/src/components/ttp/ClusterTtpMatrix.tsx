import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useClusterTtpMatrix, useTtpTaxonomy } from '@/hooks/useTtps';
import { ttpPhaseColor, ttpPhaseLabel } from '@/lib/ttpLabels';
import { orderBySimilarity } from '@/lib/playbookSimilarity';
import type { ClusterTtpMatrix as ClusterTtpMatrixData } from '@/types/ttp';

/**
 * Shared-playbook matrix: threat-actor clusters (rows) × observed TTPs (columns).
 * Clones PersonaMatrix's "row|col" Map table: the grid is sparse (zero cells
 * omitted by the backend), so an absent (cluster, ttp) pair is blank and dimmed.
 *
 * Two analyst controls sit on top of the sparse/sticky/truncated skeleton:
 *  - Normalization (raw counts | per-conversation): raw shows the confirmed
 *    observation count shaded by the busiest cell (the original behaviour, kept
 *    as the default so existing readers are not surprised); per-conversation
 *    shows each cell's share of the cluster's TTP-carrying conversations
 *    (conversation_count / conversation_total) as a percentage, shaded on that
 *    0..1 share so a chatty cluster cannot blow out the ramp.
 *  - Row ordering (by size | by playbook similarity): size keeps the backend's
 *    widest-playbook-first order; similarity reorders rows client-side (cosine on
 *    the normalized row vectors + greedy nearest-neighbor chaining) so clusters
 *    running similar playbooks sit adjacent.
 *
 * Column headers show the abbreviated TTP label (full code + definition on
 * hover) with the code kept visible in mono, so analysts who read by code are
 * not lost. Loads/empties/errors degrade to a note, never a hard error.
 */

type NormalizationMode = 'raw' | 'share';
type SortMode = 'size' | 'similarity';

export function ClusterTtpMatrix() {
  const { t } = useTranslation();
  const { data, isLoading, isError } = useClusterTtpMatrix();
  // Definitions are not carried by the matrix payload (only code/label/phase), so
  // the header hover text joins the cached taxonomy query for the definition.
  const { data: taxonomy } = useTtpTaxonomy();
  const [normalization, setNormalization] = useState<NormalizationMode>('raw');
  const [sort, setSort] = useState<SortMode>('size');

  const grid = useMemo(() => buildGrid(data), [data]);

  const definitionByCode = useMemo(() => {
    const map = new Map<string, string>();
    for (const row of taxonomy?.ttps ?? []) map.set(row.ttp_code, row.definition);
    return map;
  }, [taxonomy]);

  const orderedClusters = useMemo(() => {
    if (sort !== 'similarity') return grid.clusters;
    const ids = orderBySimilarity(grid.clusters, data?.cells ?? []);
    const byId = new Map(grid.clusters.map((c) => [c.cluster_id, c]));
    return ids.map((id) => byId.get(id)).filter((c): c is ClusterTtpMatrixData['clusters'][number] => !!c);
  }, [sort, grid.clusters, data]);

  const hasTable = !isError && !!data && grid.clusters.length > 0 && grid.ttps.length > 0;

  if (isLoading) return null;

  return (
    <section className="bg-surface-low rounded-lg p-5 space-y-3" data-testid="cluster-ttp-matrix">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h3 className="text-sm font-medium text-on-surface">{t('ttpPivot.matrixTitle')}</h3>
          <p className="text-xs text-on-surface-dim mt-0.5">{t('ttpPivot.matrixSubtitle')}</p>
        </div>
        {hasTable && (
          <div className="flex flex-wrap items-center gap-2">
            <div
              className="inline-flex rounded-full bg-surface-high p-0.5 text-xs"
              role="group"
              aria-label={t('ttpPivot.normalizeGroup')}
            >
              <SegmentedButton
                testid="cluster-ttp-matrix-norm-raw"
                active={normalization === 'raw'}
                onClick={() => setNormalization('raw')}
                label={t('ttpPivot.normalizeRaw')}
              />
              <SegmentedButton
                testid="cluster-ttp-matrix-norm-share"
                active={normalization === 'share'}
                onClick={() => setNormalization('share')}
                label={t('ttpPivot.normalizeShare')}
              />
            </div>
            <div
              className="inline-flex rounded-full bg-surface-high p-0.5 text-xs"
              role="group"
              aria-label={t('ttpPivot.sortGroup')}
            >
              <SegmentedButton
                testid="cluster-ttp-matrix-sort-size"
                active={sort === 'size'}
                onClick={() => setSort('size')}
                label={t('ttpPivot.sortSize')}
              />
              <SegmentedButton
                testid="cluster-ttp-matrix-sort-similarity"
                active={sort === 'similarity'}
                onClick={() => setSort('similarity')}
                label={t('ttpPivot.sortSimilarity')}
              />
            </div>
          </div>
        )}
      </div>

      {!hasTable ? (
        <p className="text-sm text-on-surface-dim italic" data-testid="cluster-ttp-matrix-empty">
          {t('ttpPivot.matrixEmpty')}
        </p>
      ) : (
        <>
          <div className="overflow-x-auto">
            <table className="w-full text-xs border-collapse" data-testid="cluster-ttp-matrix-table">
              <thead>
                <tr>
                  <th className="text-left p-2 text-on-surface-dim sticky left-0 bg-surface-low z-10">
                    {t('ttpPivot.matrixClusterColumn')}
                  </th>
                  {grid.ttps.map((ttp) => {
                    const definition = definitionByCode.get(ttp.ttp_code) ?? '';
                    const title = `${ttp.ttp_code} · ${ttp.ttp_label} · ${ttpPhaseLabel(ttp.phase)}${definition ? ` — ${definition}` : ''}`;
                    return (
                      <th key={ttp.ttp_code} className="p-2 font-normal align-bottom" title={title}>
                        <div className="flex flex-col items-center gap-0.5">
                          <span
                            className={`block max-w-28 truncate rounded px-1.5 py-0.5 text-[0.625rem] font-medium ${ttpPhaseColor(ttp.phase)}`}
                          >
                            {ttp.ttp_label || ttp.ttp_code}
                          </span>
                          <span className="font-mono text-[0.5rem] text-on-surface-dim">{ttp.ttp_code}</span>
                        </div>
                      </th>
                    );
                  })}
                </tr>
              </thead>
              <tbody>
                {orderedClusters.map((cluster) => (
                  <tr key={cluster.cluster_id} data-testid="cluster-ttp-matrix-row" className="border-t border-outline-variant/30">
                    <td
                      className="p-2 text-on-surface sticky left-0 bg-surface-low z-10 whitespace-nowrap"
                      title={t('ttpPivot.matrixClusterTotal', { count: cluster.observation_total })}
                    >
                      <span className="text-on-surface">{cluster.label}</span>
                      <span className="ml-1.5 text-[10px] text-on-surface-dim font-mono">
                        {cluster.observation_total.toLocaleString()}
                      </span>
                    </td>
                    {grid.ttps.map((ttp) => {
                      const cell = grid.byCell.get(`${cluster.cluster_id}|${ttp.ttp_code}`);
                      let title: string;
                      if (cell) {
                        const percent =
                          cluster.conversation_total > 0
                            ? Math.round((100 * cell.conversation_count) / cluster.conversation_total)
                            : 0;
                        title = [
                          cluster.label,
                          ttp.ttp_code,
                          t('ttpPivot.matrixCellTooltip', { count: cell.count }),
                          t('ttpPivot.matrixCellConversations', {
                            shown: cell.conversation_count,
                            total: cluster.conversation_total,
                          }),
                          t('ttpPivot.matrixCellShare', { percent }),
                        ].join(' · ');
                      } else {
                        title = `${cluster.label} · ${ttp.ttp_code} · ${t('ttpPivot.matrixCellEmpty')}`;
                      }
                      return (
                        <MatrixCell
                          key={ttp.ttp_code}
                          cell={cell}
                          convTotal={cluster.conversation_total}
                          mode={normalization}
                          maxCount={grid.maxCount}
                          title={title}
                        />
                      );
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <p className="text-[11px] text-on-surface-dim italic" data-testid="cluster-ttp-matrix-normalizer">
            {normalization === 'share' ? t('ttpPivot.matrixShareNote') : t('ttpPivot.matrixRawNote')}
          </p>

          {data.truncated && (
            <p className="text-[11px] text-on-surface-dim italic" data-testid="cluster-ttp-matrix-truncated">
              {t('ttpPivot.matrixTruncated', { shown: grid.clusters.length, total: data.total_clusters })}
            </p>
          )}
        </>
      )}
    </section>
  );
}

function SegmentedButton({
  testid,
  active,
  onClick,
  label,
}: {
  testid: string;
  active: boolean;
  onClick: () => void;
  label: string;
}) {
  return (
    <button
      type="button"
      data-testid={testid}
      aria-pressed={active}
      onClick={onClick}
      className={`px-3 py-1 rounded-full transition-colors cursor-pointer ${
        active ? 'bg-surface-lowest text-on-surface' : 'text-on-surface-variant hover:text-on-surface'
      }`}
    >
      {label}
    </button>
  );
}

interface CellValue {
  count: number;
  conversation_count: number;
}

function MatrixCell({
  cell,
  convTotal,
  mode,
  maxCount,
  title,
}: {
  cell?: CellValue;
  convTotal: number;
  mode: NormalizationMode;
  maxCount: number;
  title: string;
}) {
  if (!cell || cell.count <= 0) {
    return (
      <td className="p-2 text-center text-on-surface-dim/30 font-mono" title={title} data-testid="cluster-ttp-matrix-cell">
        ·
      </td>
    );
  }
  // Shade a present cell by a single teal hue (the column header carries the
  // phase colour). Floor the alpha so the smallest values stay legible.
  // Raw mode shades by the cell's share of the busiest cell; per-conversation
  // mode shades directly by the cell's conversation share (0..1) — the honest
  // "how much of this playbook exhibits the tactic" ramp.
  const share = convTotal > 0 ? Math.min(1, cell.conversation_count / convTotal) : 0;
  const ratio = mode === 'share' ? share : maxCount > 0 ? cell.count / maxCount : 0;
  const alpha = 0.14 + 0.55 * ratio;
  const text = mode === 'share' ? `${Math.round(share * 100)}%` : cell.count.toLocaleString();
  return (
    <td
      className="p-2 text-center font-mono text-on-surface font-medium"
      style={{ backgroundColor: `rgba(45, 212, 191, ${alpha.toFixed(3)})` }}
      title={title}
      data-testid="cluster-ttp-matrix-cell"
    >
      {text}
    </td>
  );
}

interface GridShape {
  clusters: ClusterTtpMatrixData['clusters'];
  ttps: ClusterTtpMatrixData['ttps'];
  byCell: Map<string, CellValue>;
  maxCount: number;
}

function buildGrid(data?: ClusterTtpMatrixData): GridShape {
  if (!data) {
    return { clusters: [], ttps: [], byCell: new Map(), maxCount: 0 };
  }
  const byCell = new Map<string, CellValue>();
  let maxCount = 0;
  for (const cell of data.cells) {
    byCell.set(`${cell.cluster_id}|${cell.ttp_code}`, {
      count: cell.count,
      conversation_count: cell.conversation_count,
    });
    if (cell.count > maxCount) maxCount = cell.count;
  }
  return { clusters: data.clusters, ttps: data.ttps, byCell, maxCount };
}

export default ClusterTtpMatrix;
