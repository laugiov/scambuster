import { Link } from 'react-router-dom';

interface Excerpt {
  text: string;
  occurrence_count: number;
  source_conv_id: string;
}

interface CampaignExcerptsPanelProps {
  excerpts: Excerpt[];
  templatedExcerptCount: number;
  totalVariantCount?: number | null;
}

/**
 * Replaces the inline Campaign Excerpts section on /clusters/{id}. Behaviour:
 *   - Always renders when excerpts.length > 0 (operator still sees variants
 *     even on low-template clusters).
 *   - Header shows count + an "automation" badge when templated_excerpt_count
 *     suggests a script-driven operation (> 1).
 *   - When templated, each row gets a horizontal bar showing its
 *     occurrence_count relative to the most-repeated excerpt in the panel —
 *     visualising which script variant the operator uses most.
 *   - Caption reads "Top {N} of {total} variants" if total_variant_count is
 *     provided by the API (spec 121 data quality #3 reconciliation).
 */
export function CampaignExcerptsPanel({
  excerpts,
  templatedExcerptCount,
  totalVariantCount,
}: CampaignExcerptsPanelProps) {
  if (excerpts.length === 0) return null;

  const isTemplated = templatedExcerptCount > 1;
  const top5 = excerpts.slice(0, 5);
  const maxOccurrence = Math.max(...top5.map((e) => e.occurrence_count), 1);

  return (
    <section
      data-testid="campaign-excerpts"
      data-templated={isTemplated ? 'true' : 'false'}
      className="rounded-lg border border-border bg-surface-low/30"
    >
      <div className="px-4 py-2 border-b border-border flex items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <h2 className="text-xs uppercase tracking-widest text-on-surface-dim">Campaign Excerpts</h2>
          {isTemplated && (
            <span
              className="px-1.5 py-0.5 rounded bg-warning/20 text-warning text-[0.625rem] font-medium flex items-center gap-1"
              title={`${templatedExcerptCount} IOC observations share these excerpts — templated automation signal`}
            >
              <svg className="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3} aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
              </svg>
              Templated · {templatedExcerptCount} IOCs
            </span>
          )}
        </div>
        <span className="text-xs text-on-surface-dim tabular-nums">
          {totalVariantCount && totalVariantCount > top5.length
            ? `Top ${top5.length} of ${totalVariantCount} variants`
            : `${excerpts.length} unique excerpt${excerpts.length === 1 ? '' : 's'}`}
        </span>
      </div>
      <ul className="px-4 py-3 space-y-2">
        {top5.map((excerpt, idx) => {
          const barPct = isTemplated ? Math.round((excerpt.occurrence_count / maxOccurrence) * 100) : 0;
          return (
            <li
              key={idx}
              data-testid="campaign-excerpt-row"
              className="text-xs flex items-start justify-between gap-3 pl-3 border-l-2 border-accent/40"
            >
              <div className="flex-1 min-w-0">
                <span className="text-on-surface-dim italic block">
                  &ldquo;{excerpt.text}&rdquo;
                </span>
                {isTemplated && barPct > 0 && (
                  <div className="mt-1.5 h-1 max-w-[260px] bg-surface-dim rounded overflow-hidden">
                    <div
                      className="h-full bg-warning/60 rounded"
                      style={{ width: `${barPct}%` }}
                      data-occurrence-bar
                    />
                  </div>
                )}
              </div>
              <span className="flex items-center gap-2 shrink-0 mt-0.5">
                {excerpt.occurrence_count > 1 && (
                  <span
                    className="px-1.5 py-0.5 rounded bg-warning/20 text-warning text-[0.625rem] font-medium tabular-nums"
                    title={`Repeated in ${excerpt.occurrence_count} conversations`}
                  >
                    ×{excerpt.occurrence_count}
                  </span>
                )}
                {excerpt.source_conv_id && (
                  <Link
                    to={`/conversations/${excerpt.source_conv_id}`}
                    className="text-accent hover:underline font-mono text-[0.625rem]"
                    title="Open source conversation"
                  >
                    {excerpt.source_conv_id.slice(0, 8)}
                  </Link>
                )}
              </span>
            </li>
          );
        })}
      </ul>
    </section>
  );
}
