import { describe, it, expect } from 'vitest';
import { timeSince, formatShortTimestamp } from './time';

describe('timeSince', () => {
  it('returns seconds ago for recent dates', () => {
    const tenSecondsAgo = new Date(Date.now() - 10_000).toISOString();
    expect(timeSince(tenSecondsAgo)).toBe('10s ago');
  });

  it('returns minutes ago', () => {
    const fiveMinutesAgo = new Date(Date.now() - 5 * 60_000).toISOString();
    expect(timeSince(fiveMinutesAgo)).toBe('5m ago');
  });

  it('returns hours ago', () => {
    const threeHoursAgo = new Date(Date.now() - 3 * 3_600_000).toISOString();
    expect(timeSince(threeHoursAgo)).toBe('3h ago');
  });

  it('returns days ago', () => {
    const twoDaysAgo = new Date(Date.now() - 2 * 86_400_000).toISOString();
    expect(timeSince(twoDaysAgo)).toBe('2d ago');
  });

  it('returns "just now" for future dates', () => {
    const tomorrow = new Date(Date.now() + 86_400_000).toISOString();
    expect(timeSince(tomorrow)).toBe('just now');
  });

  it('returns "--" for invalid dates', () => {
    expect(timeSince('not-a-date')).toBe('--');
    expect(timeSince('')).toBe('--');
  });
});

describe('formatShortTimestamp', () => {
  it('formats recent date with time', () => {
    const yesterday = new Date(Date.now() - 86_400_000);
    const result = formatShortTimestamp(yesterday.toISOString());
    expect(result).toMatch(/\w{3} \d{1,2}, \d{2}:\d{2}/);
  });

  it('formats old date without time', () => {
    const old = new Date(Date.now() - 10 * 86_400_000);
    const result = formatShortTimestamp(old.toISOString());
    expect(result).toMatch(/\w{3} \d{1,2}$/);
  });

  it('returns -- for invalid date', () => {
    expect(formatShortTimestamp('invalid')).toBe('--');
  });
});
