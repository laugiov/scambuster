import { describe, it, expect } from 'vitest';
import { buildClusterVerdict, maskAnchor, type VerdictInputs } from '../clusterVerdict';

function inputs(overrides: Partial<VerdictInputs> = {}): VerdictInputs {
  return {
    conversation_count: 3,
    primary_scam_types: ['CEO_FRAUD'],
    anchor_iocs: [
      { ioc_type: 'bank_account', ioc_value: '808-244517-8804', conv_count: 3 },
    ],
    behavioral_profile: {
      dominant_revelation_turn: 1,
      dominant_stimulus: 'DIRECT_REQUEST',
      templated_excerpt_count: 0,
      total_excerpt_variant_count: 5,
      avg_urgency_score: 0.4,
    },
    ...overrides,
  };
}

describe('maskAnchor', () => {
  it('masks the trailing 4 of a bank account', () => {
    expect(maskAnchor('bank_account', '808-244517-8804')).toBe('****8804');
  });

  it('masks the trailing 4 of an IBAN', () => {
    expect(maskAnchor('iban', 'FR7630006000011234567890189')).toBe('****0189');
  });

  it('masks a phone keeping the last 4 digits', () => {
    expect(maskAnchor('phone', '+1 332 401 0173')).toBe('****0173');
  });

  it('returns the value as-is for non-sensitive types', () => {
    expect(maskAnchor('email', 'a@b.co')).toBe('a@b.co');
    expect(maskAnchor('domain', 'example.co')).toBe('example.co');
  });

  it('returns the value as-is when too short to mask', () => {
    expect(maskAnchor('bank_account', '12')).toBe('12');
  });
});

describe('buildClusterVerdict', () => {
  it('produces a financial-anchor verdict for the Hartmere cluster shape', () => {
    const v = buildClusterVerdict(inputs());
    expect(v).toMatch(/Ceo fraud cluster on a shared bank account \(\*\*\*\*8804\), 3 conversations\./);
    expect(v).toMatch(/IOCs revealed on turn 1/);
  });

  it('uses the templated phrasing when templated_excerpt_count is high', () => {
    const v = buildClusterVerdict(
      inputs({
        conversation_count: 39,
        behavioral_profile: {
          dominant_revelation_turn: 1,
          dominant_stimulus: 'DIRECT_REQUEST',
          templated_excerpt_count: 58,
          total_excerpt_variant_count: 5,
        },
      }),
    );
    expect(v).toMatch(/Templated ceo fraud operation: 39 conversations across 5 script variants\./);
  });

  it('phrases a single-conversation cluster differently', () => {
    const v = buildClusterVerdict(inputs({ conversation_count: 1 }));
    expect(v).toMatch(/Single-conversation ceo fraud scam pointing at bank account \*\*\*\*8804\./);
  });

  it('handles a multi-type cluster with a financial anchor', () => {
    const v = buildClusterVerdict(
      inputs({
        primary_scam_types: ['CEO_FRAUD', 'INVOICE_FRAUD'],
        conversation_count: 5,
      }),
    );
    expect(v).toMatch(/Multi-type cluster \(2 scam categories\)/);
    expect(v).toMatch(/\*\*\*\*8804/);
    expect(v).toMatch(/5 conversations/);
  });

  it('handles a phone-only anchor (non-financial) without claiming exploitable infra', () => {
    const v = buildClusterVerdict(
      inputs({
        anchor_iocs: [{ ioc_type: 'phone', ioc_value: '+1 332 401 0173', conv_count: 3 }],
      }),
    );
    expect(v).toMatch(/Ceo fraud cluster spanning 3 conversations on a shared phone number\./);
  });

  it('falls back to a generic phrasing when no anchor is exposed', () => {
    const v = buildClusterVerdict(inputs({ anchor_iocs: [] }));
    expect(v).toMatch(/Ceo fraud cluster of 3 conversations\./);
  });

  it('drops the secondary sentence when turn is late and no stimulus', () => {
    const v = buildClusterVerdict(
      inputs({
        behavioral_profile: {
          dominant_revelation_turn: 8,
          dominant_stimulus: null,
          templated_excerpt_count: 0,
        },
      }),
    );
    expect(v).not.toMatch(/IOCs revealed on turn/);
    expect(v).not.toMatch(/Primary tactic/);
  });

  it('uses "Primary tactic" wording when turn is late but stimulus is known', () => {
    const v = buildClusterVerdict(
      inputs({
        behavioral_profile: {
          dominant_revelation_turn: 8,
          dominant_stimulus: 'COERCIVE',
          templated_excerpt_count: 0,
        },
      }),
    );
    expect(v).toMatch(/Primary tactic: coercive\./);
  });
});
