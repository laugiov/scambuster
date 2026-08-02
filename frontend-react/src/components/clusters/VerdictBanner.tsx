import { buildClusterVerdict, type VerdictInputs } from '@/lib/clusterVerdict';

interface VerdictBannerProps {
  cluster: VerdictInputs;
}

/**
 * Plain-English 1-2 sentence summary at the top of the cluster detail page.
 * Pure derivation from the existing ClusterDetail response — no extra API
 * call, no LLM. See `clusterVerdict.ts` for the generator.
 */
export function VerdictBanner({ cluster }: VerdictBannerProps) {
  const verdict = buildClusterVerdict(cluster);

  return (
    <section
      data-testid="cluster-verdict"
      className="rounded-lg border border-border bg-surface-low px-5 py-4 text-sm text-on-surface leading-relaxed"
    >
      {verdict}
    </section>
  );
}
