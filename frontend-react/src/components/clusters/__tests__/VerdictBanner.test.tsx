import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { VerdictBanner } from '../VerdictBanner';
import type { VerdictInputs } from '@/lib/clusterVerdict';

const baseCluster: VerdictInputs = {
  conversation_count: 3,
  primary_scam_types: ['CEO_FRAUD'],
  anchor_iocs: [{ ioc_type: 'bank_account', ioc_value: '808-244517-8804', conv_count: 3 }],
  behavioral_profile: {
    dominant_revelation_turn: 1,
    dominant_stimulus: 'DIRECT_REQUEST',
    templated_excerpt_count: 0,
    total_excerpt_variant_count: null,
  },
};

describe('VerdictBanner', () => {
  it('renders the verdict text inside a tagged section', () => {
    const { container } = render(<VerdictBanner cluster={baseCluster} />);
    const section = container.querySelector('[data-testid="cluster-verdict"]');
    expect(section).not.toBeNull();
    expect(section?.textContent).toMatch(/Ceo fraud cluster on a shared bank account \(\*\*\*\*8804\)/);
  });

  it('always renders a non-empty string (no blank banner)', () => {
    const { container } = render(<VerdictBanner cluster={{ ...baseCluster, anchor_iocs: [] }} />);
    const section = container.querySelector('[data-testid="cluster-verdict"]');
    expect(section?.textContent?.length ?? 0).toBeGreaterThan(0);
  });
});
