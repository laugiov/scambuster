import { describe, it, expect } from 'vitest';
import { isUrgencyPlaceholder } from '../clusterUrgencyHeuristic';

describe('isUrgencyPlaceholder', () => {
  it('fires when both profile and all anchors are exactly 0.20 with 2+ anchors', () => {
    const profile = { avg_urgency_score: 0.2 };
    const anchors = [
      { avg_urgency_score: 0.2 },
      { avg_urgency_score: 0.2 },
      { avg_urgency_score: 0.2 },
    ];
    expect(isUrgencyPlaceholder(profile, anchors)).toBe(true);
  });

  it('does not fire when the profile aggregate is not 0.20', () => {
    expect(
      isUrgencyPlaceholder({ avg_urgency_score: 0.35 }, [
        { avg_urgency_score: 0.2 },
        { avg_urgency_score: 0.2 },
      ]),
    ).toBe(false);
  });

  it('does not fire when any anchor diverges from 0.20', () => {
    expect(
      isUrgencyPlaceholder({ avg_urgency_score: 0.2 }, [
        { avg_urgency_score: 0.2 },
        { avg_urgency_score: 0.4 },
      ]),
    ).toBe(false);
  });

  it('does not fire when there is only one anchor (could be a real reading)', () => {
    expect(isUrgencyPlaceholder({ avg_urgency_score: 0.2 }, [{ avg_urgency_score: 0.2 }])).toBe(false);
  });

  it('does not fire when no anchors are passed', () => {
    expect(isUrgencyPlaceholder({ avg_urgency_score: 0.2 }, [])).toBe(false);
  });

  it('does not fire when an anchor has null urgency', () => {
    expect(
      isUrgencyPlaceholder({ avg_urgency_score: 0.2 }, [
        { avg_urgency_score: 0.2 },
        { avg_urgency_score: null },
      ]),
    ).toBe(false);
  });

  it('does not fire on null / missing profile', () => {
    expect(isUrgencyPlaceholder(null, [{ avg_urgency_score: 0.2 }, { avg_urgency_score: 0.2 }])).toBe(false);
    expect(isUrgencyPlaceholder(undefined, [])).toBe(false);
  });

  it('treats 0.2000001 as still placeholder (epsilon tolerance)', () => {
    expect(
      isUrgencyPlaceholder({ avg_urgency_score: 0.2000001 }, [
        { avg_urgency_score: 0.2 },
        { avg_urgency_score: 0.2 },
      ]),
    ).toBe(true);
  });
});
