import { describe, it, expect } from 'vitest';
import { formatDate, formatTime } from './time';

describe('time — coverage gaps (formatDate and formatTime)', () => {
  it('formatDate returns formatted date string', () => {
    const result = formatDate('2026-03-20T14:30:00Z');
    // Should contain date and time components
    expect(result).toMatch(/20/);
    expect(result).toMatch(/03/);
    expect(result).toMatch(/2026/);
  });

  it('formatTime returns HH:MM format', () => {
    const result = formatTime('2026-03-20T14:30:00Z');
    // Should be in HH:MM format
    expect(result).toMatch(/\d{2}:\d{2}/);
  });

  it('formatDate handles edge case dates', () => {
    const result = formatDate('2026-01-01T00:00:00Z');
    expect(result).toMatch(/01/);
    expect(result).toMatch(/2026/);
  });

  it('formatTime handles midnight', () => {
    const result = formatTime('2026-03-20T00:00:00Z');
    expect(result).toMatch(/\d{2}:\d{2}/);
  });
});
