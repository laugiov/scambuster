import { describe, it, expect } from 'vitest';
import { formatTokenCount, formatPurposeName, formatShortDate } from './format';

describe('formatTokenCount', () => {
  it('returns raw number below 1000', () => {
    expect(formatTokenCount(0)).toBe('0');
    expect(formatTokenCount(999)).toBe('999');
  });

  it('formats thousands as K', () => {
    expect(formatTokenCount(1000)).toBe('1K');
    expect(formatTokenCount(1500)).toBe('1.5K');
    expect(formatTokenCount(42300)).toBe('42.3K');
  });

  it('formats millions as M', () => {
    expect(formatTokenCount(1000000)).toBe('1M');
    expect(formatTokenCount(2500000)).toBe('2.5M');
  });

  it('removes trailing .0', () => {
    expect(formatTokenCount(2000)).toBe('2K');
    expect(formatTokenCount(3000000)).toBe('3M');
  });
});

describe('formatPurposeName', () => {
  it('capitalizes single word', () => {
    expect(formatPurposeName('generation')).toBe('Generation');
  });

  it('capitalizes and joins underscored words', () => {
    expect(formatPurposeName('ioc_extraction')).toBe('Ioc Extraction');
    expect(formatPurposeName('conversation_analysis')).toBe('Conversation Analysis');
  });

  it('handles already capitalized input', () => {
    expect(formatPurposeName('Validation')).toBe('Validation');
  });
});

describe('formatShortDate', () => {
  it('formats ISO date to short month-day', () => {
    expect(formatShortDate('2026-03-22')).toBe('Mar 22');
    expect(formatShortDate('2026-01-05')).toBe('Jan 5');
  });

  it('returns input for invalid date', () => {
    expect(formatShortDate('not-a-date')).toBe('not-a-date');
  });
});
