import { iocTypeLabel } from '@/lib/iocTypeLabels';
import { iocFamilyBadge } from '@/lib/actorColors';
import {
  ROLE_COLORS,
  humanizeContext,
  normalizeContextKey,
  urgencyColorClass,
  urgencyTextClass,
} from '@/lib/iocContextLabels';
import { stimulusColor } from '@/lib/stimulusLabels';

interface AnchorIoc {
  indicator_id: string;
  ioc_type: string;
  ioc_value: string;
  conv_count: number;
  dominant_semantic_role?: string | null;
  dominant_stimulus?: string | null;
  avg_urgency_score?: number | null;
}

interface AnchorReachRowProps {
  anchor: AnchorIoc;
  totalConversations: number;
  isSelected: boolean;
  onSelect: (indicatorId: string) => void;
  onOpenDetail: (indicatorId: string) => void;
}

/**
 * One row in the Anchor IOCs panel on /clusters/{id}. Replaces the inline
 * markup that lived in `ClusterDetail.tsx` and adds the reach bar:
 * `(conv_count / totalConversations) × 100%`. The bar is the visual proof
 * that the cluster is one actor — a phone covering 16 of 39 conversations
 * is a stronger signal than the same phone covering 1 of 39.
 *
 * Existing behaviour preserved: click toggles a filter on the Conversations
 * panel; the small icon button opens the IOC explorer detail page.
 */
export function AnchorReachRow({
  anchor,
  totalConversations,
  isSelected,
  onSelect,
  onOpenDetail,
}: AnchorReachRowProps) {
  const reachPct =
    totalConversations > 0 ? Math.round((anchor.conv_count / totalConversations) * 100) : 0;

  return (
    <div
      data-testid="anchor-reach-row"
      className={`px-4 py-3 cursor-pointer transition-colors border-l-4 ${
        isSelected
          ? 'bg-accent/15 border-accent shadow-inner'
          : 'border-transparent hover:bg-surface-dim/50'
      }`}
      onClick={() => onSelect(anchor.indicator_id)}
      title="Click to filter conversations sharing this IOC"
    >
      <div className="flex items-center justify-between gap-3">
        <div className="flex flex-col gap-1 min-w-0 flex-1">
          <div className="flex items-center gap-2 min-w-0">
            <span
              className={`shrink-0 rounded border px-1.5 py-0.5 text-xs ${iocFamilyBadge(anchor.ioc_type)} ${isSelected ? 'font-semibold' : ''}`}
            >
              {iocTypeLabel(anchor.ioc_type)}
            </span>
            <span
              className={`text-xs font-mono truncate ${isSelected ? 'text-on-surface font-medium' : 'text-on-surface'}`}
              title={anchor.ioc_value}
            >
              {anchor.ioc_value}
            </span>
          </div>
          {anchor.dominant_semantic_role && (
            <div className="flex items-center gap-1.5 ml-1 flex-wrap">
              <span
                className={`px-1.5 py-0.5 rounded text-[0.625rem] font-medium ${
                  ROLE_COLORS[normalizeContextKey(anchor.dominant_semantic_role)] ??
                  'bg-on-surface-dim/20 text-on-surface-dim'
                }`}
              >
                {humanizeContext(anchor.dominant_semantic_role)}
              </span>
              {anchor.dominant_stimulus && (
                <span
                  className={`px-1.5 py-0.5 rounded text-[0.625rem] font-medium ${stimulusColor(normalizeContextKey(anchor.dominant_stimulus))}`}
                >
                  {humanizeContext(anchor.dominant_stimulus)}
                </span>
              )}
              {anchor.avg_urgency_score !== null && anchor.avg_urgency_score !== undefined && anchor.avg_urgency_score > 0 && (
                <div className="flex items-center gap-1">
                  <div className="w-10 h-1 bg-surface-dim rounded-full overflow-hidden">
                    <div
                      className={`h-full ${urgencyColorClass(anchor.avg_urgency_score)}`}
                      style={{ width: `${Math.round(anchor.avg_urgency_score * 100)}%` }}
                    />
                  </div>
                  <span className={`text-[0.625rem] ${urgencyTextClass(anchor.avg_urgency_score)}`}>
                    {Math.round(anchor.avg_urgency_score * 100)}%
                  </span>
                </div>
              )}
            </div>
          )}
        </div>
        <div className="flex items-center gap-2 shrink-0">
          <span className={`text-xs ${isSelected ? 'text-accent font-medium' : 'text-on-surface-dim'}`}>
            {anchor.conv_count} conv.
          </span>
          <button
            type="button"
            title="View IOC details"
            onClick={(e) => {
              e.stopPropagation();
              onOpenDetail(anchor.indicator_id);
            }}
            className="p-1 rounded hover:bg-accent/20 text-on-surface-dim hover:text-accent transition-colors cursor-pointer"
          >
            <svg
              className="w-3.5 h-3.5"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              strokeWidth={2}
              aria-hidden="true"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"
              />
            </svg>
          </button>
        </div>
      </div>

      {/* Reach bar — visual proof of clustering */}
      <div className="mt-2.5 flex items-center gap-2">
        <div
          className="flex-1 h-1.5 bg-surface-dim rounded overflow-hidden"
          data-testid="anchor-reach-bar"
        >
          <div
            className="h-full bg-accent rounded"
            style={{ width: `${reachPct}%` }}
            data-reach-pct={reachPct}
          />
        </div>
        <span className="text-[0.625rem] text-on-surface-dim tabular-nums w-9 text-right">
          {reachPct}%
        </span>
      </div>
    </div>
  );
}
