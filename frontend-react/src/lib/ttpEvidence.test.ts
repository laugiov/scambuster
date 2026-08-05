import { describe, it, expect } from 'vitest';
import {
  bodyStartOffset,
  evidenceRanges,
  toBodyRanges,
  highlightSegments,
  snapRangesToWhitespace,
  expandRangesOverValues,
} from './ttpEvidence';

describe('bodyStartOffset', () => {
  it('is subject length + 2 (the "\\n\\n" separator)', () => {
    expect(bodyStartOffset('Hi')).toBe(4); // 2 + 2
    expect(bodyStartOffset('')).toBe(2);
    expect(bodyStartOffset(null)).toBe(2);
    expect(bodyStartOffset(undefined)).toBe(2);
  });

  it('counts code points, not UTF-16 units, in the subject', () => {
    // "😀" is one code point but two UTF-16 units.
    expect(bodyStartOffset('😀')).toBe(3); // 1 code point + 2
  });
});

describe('evidenceRanges', () => {
  it('keeps non-null, non-empty ranges and drops null / empty ones', () => {
    const ranges = evidenceRanges([
      { evidence_start: 4, evidence_end: 9 },
      { evidence_start: null, evidence_end: null }, // paraphrased → dropped
      { evidence_start: 5, evidence_end: 5 }, // empty → dropped
      { evidence_start: 2, evidence_end: null }, // half-null → dropped
    ]);
    expect(ranges).toEqual([{ start: 4, end: 9 }]);
  });
});

describe('toBodyRanges', () => {
  it('translates combined-base offsets to body-relative ranges', () => {
    // combined = "Sub" + "\n\n" + "hello world" ; body starts at code point 5.
    // Evidence [5,10) in the combined base = "hello" → body-relative [0,5).
    const ranges = toBodyRanges([{ start: 5, end: 10 }], 'Sub', 'hello world');
    expect(ranges).toEqual([{ start: 0, end: 5 }]);
  });

  it('clamps a range that straddles the separator into the body', () => {
    // body starts at 5; a range [3,8) covers separator+"hel" → clamps to [0,3).
    const ranges = toBodyRanges([{ start: 3, end: 8 }], 'Sub', 'hello world');
    expect(ranges).toEqual([{ start: 0, end: 3 }]);
  });

  it('drops a range that falls entirely in the subject', () => {
    const ranges = toBodyRanges([{ start: 0, end: 3 }], 'Sub', 'hello world');
    expect(ranges).toEqual([]);
  });

  it('clamps the end to the body length', () => {
    const ranges = toBodyRanges([{ start: 5, end: 999 }], 'Sub', 'hello');
    expect(ranges).toEqual([{ start: 0, end: 5 }]);
  });
});

describe('highlightSegments', () => {
  it('returns a single plain segment when there are no ranges', () => {
    expect(highlightSegments('hello', [])).toEqual([{ text: 'hello', highlighted: false }]);
  });

  it('splits the string around a single range', () => {
    expect(highlightSegments('hello world', [{ start: 0, end: 5 }])).toEqual([
      { text: 'hello', highlighted: true },
      { text: ' world', highlighted: false },
    ]);
  });

  it('merges overlapping / adjacent ranges', () => {
    expect(highlightSegments('abcdef', [
      { start: 0, end: 2 },
      { start: 1, end: 4 },
    ])).toEqual([
      { text: 'abcd', highlighted: true },
      { text: 'ef', highlighted: false },
    ]);
  });

  it('slices on code points so multi-byte characters are not split', () => {
    // "a😀b😀c" — highlight the two emoji (code points 1 and 3).
    const segments = highlightSegments('a😀b😀c', [
      { start: 1, end: 2 },
      { start: 3, end: 4 },
    ]);
    expect(segments).toEqual([
      { text: 'a', highlighted: false },
      { text: '😀', highlighted: true },
      { text: 'b', highlighted: false },
      { text: '😀', highlighted: true },
      { text: 'c', highlighted: false },
    ]);
  });

  it('clamps out-of-bounds ranges', () => {
    expect(highlightSegments('hi', [{ start: 0, end: 99 }])).toEqual([
      { text: 'hi', highlighted: true },
    ]);
  });
});

describe('snapRangesToWhitespace', () => {
  it('extends a range outward to enclose the whole token it bisects', () => {
    // Range covers "call +91-790" (ends mid-token) → snaps end to the space.
    const text = 'call +91-7906757261 urgently';
    const range = { start: 0, end: 'call +91-790'.length };
    expect(snapRangesToWhitespace([range], text)).toEqual([
      { start: 0, end: 'call +91-7906757261'.length },
    ]);
  });

  it('extends the start left to the token boundary', () => {
    const text = 'wire acct now';
    // Range covers "cct" (starts mid-token) → snaps start left onto "acct".
    const start = text.indexOf('cct');
    const range = { start, end: start + 3 };
    expect(snapRangesToWhitespace([range], text)).toEqual([
      { start: text.indexOf('acct'), end: text.indexOf('acct') + 'acct'.length },
    ]);
  });

  it('leaves a whitespace-aligned range unchanged and clamps bounds', () => {
    const text = 'one two three';
    expect(snapRangesToWhitespace([{ start: 4, end: 7 }], text)).toEqual([{ start: 4, end: 7 }]);
    expect(snapRangesToWhitespace([{ start: -5, end: 99 }], text)).toEqual([{ start: 0, end: text.length }]);
  });
});

describe('expandRangesOverValues', () => {
  it('expands a range to fully enclose a multi-word value it partially overlaps', () => {
    const text = 'send to plot no 1 mamram now';
    const value = 'plot no 1 mamram';
    // Range covers "to plot no 1" — ends INSIDE the multi-word value.
    const range = { start: text.indexOf('to'), end: text.indexOf('to') + 'to plot no 1'.length };
    const [out] = expandRangesOverValues([range], text, [value]);
    // Must now enclose the whole value.
    expect(out.start).toBeLessThanOrEqual(text.indexOf(value));
    expect(out.end).toBeGreaterThanOrEqual(text.indexOf(value) + value.length);
  });

  it('is a no-op when there are no values (masking off)', () => {
    const text = 'a b c';
    expect(expandRangesOverValues([{ start: 0, end: 1 }], text, [])).toEqual([{ start: 0, end: 1 }]);
  });

  it('matches values case-insensitively', () => {
    const text = 'ref ABC-123 end';
    const range = { start: 0, end: text.indexOf('ABC') + 1 }; // touches just the "A"
    const [out] = expandRangesOverValues([range], text, ['abc-123']);
    expect(out.end).toBeGreaterThanOrEqual(text.indexOf('ABC-123') + 'ABC-123'.length);
  });

  it('does not expand a range that does not overlap any value', () => {
    const text = 'hello world secret';
    expect(expandRangesOverValues([{ start: 0, end: 5 }], text, ['secret'])).toEqual([{ start: 0, end: 5 }]);
  });
});
