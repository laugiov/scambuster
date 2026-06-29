import { describe, it, expect } from 'vitest';
import { bucketRecency, recencyTooltip, recencyRank } from '../clusterRecency';

describe('bucketRecency', () => {
  const now = new Date('2026-06-15T10:00:00Z');

  it('returns "now" when last_seen is in the current calendar month', () => {
    expect(bucketRecency('2026-06-01T00:00:00Z', now)).toBe('now');
    expect(bucketRecency('2026-06-30T23:59:59Z', now)).toBe('now');
  });

  it('returns "recent" when last_seen is in the immediately previous calendar month', () => {
    expect(bucketRecency('2026-05-01T00:00:00Z', now)).toBe('recent');
    expect(bucketRecency('2026-05-31T23:59:59Z', now)).toBe('recent');
  });

  it('returns "stale" when last_seen is two or more months old', () => {
    expect(bucketRecency('2026-04-15T00:00:00Z', now)).toBe('stale');
    expect(bucketRecency('2025-12-01T00:00:00Z', now)).toBe('stale');
  });

  it('returns "stale" for null / undefined / invalid input', () => {
    expect(bucketRecency(null, now)).toBe('stale');
    expect(bucketRecency(undefined, now)).toBe('stale');
    expect(bucketRecency('not-a-date', now)).toBe('stale');
  });

  it('handles year boundary (January now, December last year)', () => {
    const jan = new Date('2026-01-10T00:00:00Z');
    expect(bucketRecency('2025-12-20T00:00:00Z', jan)).toBe('recent');
    expect(bucketRecency('2025-11-20T00:00:00Z', jan)).toBe('stale');
    expect(bucketRecency('2026-01-05T00:00:00Z', jan)).toBe('now');
  });

  it('returns "now" if last_seen is in the future (clock skew)', () => {
    expect(bucketRecency('2027-01-01T00:00:00Z', now)).toBe('now');
  });
});

describe('recencyTooltip', () => {
  it('returns a human-readable explanation per bucket', () => {
    expect(recencyTooltip('now')).toMatch(/this month/i);
    expect(recencyTooltip('recent')).toMatch(/last month/i);
    expect(recencyTooltip('stale')).toMatch(/2\+/);
  });
});

describe('recencyRank', () => {
  it('ranks now > recent > stale for use in sort comparators', () => {
    expect(recencyRank('now')).toBeGreaterThan(recencyRank('recent'));
    expect(recencyRank('recent')).toBeGreaterThan(recencyRank('stale'));
  });
});
