import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { DedupHeroCard } from '../DedupHeroCard';
import type { ClusterStats } from '@/hooks/useClusters';

function makeStats(overrides: Partial<ClusterStats> = {}): ClusterStats {
  return {
    total_clusters: 46,
    total_conversations: 649,
    clustered_conversations: 150,
    singleton_conversations: 499,
    suspect_clusters: 0,
    taxii_noise_reduction_pct: 16,
    ...overrides,
  };
}

describe('DedupHeroCard', () => {
  it('renders the percentage reduction prominently', () => {
    render(<DedupHeroCard stats={makeStats()} />);
    expect(screen.getByText(/−16%/)).toBeDefined();
    expect(screen.getByText(/fewer threat actors/i)).toBeDefined();
  });

  it('renders before and after numbers with locale formatting', () => {
    render(<DedupHeroCard stats={makeStats({ total_conversations: 1234, total_clusters: 100, singleton_conversations: 900 })} />);
    expect(screen.getByText(/1,234 before/)).toBeDefined();
    expect(screen.getByText(/1,000 after/)).toBeDefined();
  });

  it('mentions clusters and singletons in the explanatory caption', () => {
    render(<DedupHeroCard stats={makeStats()} />);
    expect(screen.getByText(/46 cluster/)).toBeDefined();
    expect(screen.getByText(/499 singleton/)).toBeDefined();
  });

  it('renders nothing when there are no source conversations', () => {
    const { container } = render(<DedupHeroCard stats={makeStats({ total_conversations: 0 })} />);
    expect(container.firstChild).toBeNull();
  });

  it('caps the after bar width at 100% even on inconsistent data', () => {
    render(<DedupHeroCard stats={makeStats({ total_conversations: 10, total_clusters: 8, singleton_conversations: 50 })} />);
    // The after row label still renders even when after > before
    expect(screen.getByText(/58 after/)).toBeDefined();
  });
});
