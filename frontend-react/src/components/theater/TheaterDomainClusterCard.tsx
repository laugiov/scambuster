import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { TheaterIocCard } from './TheaterIocCard';
import type { DomainCluster } from '@/lib/domainVariants';

interface TheaterDomainClusterCardProps {
  cluster: DomainCluster;
}

/**
 * Render a DomainCluster as ONE primary IOC card with
 * a `▸ N variants` chip below it. Clicking the chip expands the
 * variant list. The primary carries a "Multi-domain operator" label
 * tooltip so the audience reads variant grouping as a CTI signal
 * (scammer multi-façade infrastructure), not as a UI defect.
 *
 * When the cluster has zero variants the component degrades to a
 * plain TheaterIocCard with no wrapping — no visual regression for
 * the common non-clustered case.
 */
export function TheaterDomainClusterCard({ cluster }: TheaterDomainClusterCardProps) {
  const { t } = useTranslation();
  const [expanded, setExpanded] = useState(false);

  if (cluster.variants.length === 0) {
    return <TheaterIocCard ioc={cluster.primary} />;
  }

  return (
    <div data-testid="theater-domain-cluster" className="flex flex-col">
      <div className="relative">
        <TheaterIocCard ioc={cluster.primary} />
        <span
          className="absolute top-2 right-2 text-[9px] uppercase tracking-widest font-mono px-1.5 py-0.5 rounded bg-purple-500/20 text-purple-300 border border-purple-500/40"
          title={t('theater.multi_domain_operator_tooltip')}
        >
          {t('theater.multi_domain_operator')}
        </span>
      </div>
      <button
        type="button"
        onClick={() => setExpanded((v) => !v)}
        className="mt-1 text-[10px] font-mono uppercase tracking-widest text-on-surface-dim hover:text-on-surface flex items-center gap-2 self-start ml-2"
        data-testid="cluster-variants-toggle"
      >
        <span>{expanded ? '▾' : '▸'}</span>
        <span>{t('theater.variants_count', { count: cluster.variants.length })}</span>
      </button>
      {expanded && (
        <div className="flex flex-col gap-2 mt-2 ml-4 border-l-2 border-purple-500/30 pl-3" data-testid="cluster-variants-list">
          {cluster.variants.map((variant) => (
            <TheaterIocCard key={variant.indicator_id} ioc={variant} />
          ))}
        </div>
      )}
    </div>
  );
}
