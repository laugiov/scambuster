// Client-side reconstruction of TTP evidence highlights.
//
// The backend never sends evidence TEXT — only character offsets. Those offsets
// are UTF-8 CODE-POINT positions [start, end) (end exclusive) into the exact
// text the extractor indexed, which is `subject + "\n\n" + body_text`
// (see App\Application\Ttp\TtpHandler::extractForMessage). The UI rebuilds the
// highlight from the message body it already renders.
//
// All slicing goes through Array.from() (code points), never String.substring on
// raw indices, so multi-byte characters (emoji, accents) never desync the marks.

export interface OffsetRange {
  start: number;
  end: number;
}

/**
 * Code-point offset where the body begins inside the combined base text
 * (`subject + "\n\n" + body`): the subject length plus the 2-char separator.
 */
export function bodyStartOffset(subject: string | null | undefined): number {
  return Array.from(subject ?? '').length + 2;
}

/** Non-null, non-empty [start, end) ranges from a message's timeline TTPs. */
export function evidenceRanges(
  ttps: ReadonlyArray<{ evidence_start: number | null; evidence_end: number | null }>,
): OffsetRange[] {
  const out: OffsetRange[] = [];
  for (const t of ttps) {
    if (
      t.evidence_start !== null &&
      t.evidence_end !== null &&
      t.evidence_end > t.evidence_start
    ) {
      out.push({ start: t.evidence_start, end: t.evidence_end });
    }
  }
  return out;
}

/**
 * Translate combined-base offset ranges into BODY-relative code-point ranges,
 * keeping only the portion that intersects the body. Ranges that fall entirely
 * inside the subject/separator are dropped (the TTP badge still renders; only
 * the in-body highlight is skipped).
 */
export function toBodyRanges(
  ranges: ReadonlyArray<OffsetRange>,
  subject: string | null | undefined,
  body: string,
): OffsetRange[] {
  const base = bodyStartOffset(subject);
  const bodyLen = Array.from(body).length;
  const out: OffsetRange[] = [];
  for (const r of ranges) {
    const start = Math.max(0, r.start - base);
    const end = Math.min(bodyLen, r.end - base);
    if (end > start) {
      out.push({ start, end });
    }
  }
  return out;
}

export interface HighlightSegment {
  text: string;
  highlighted: boolean;
}

/**
 * Split `text` into consecutive segments flagged highlighted / not, given a set
 * of code-point ranges [start, end). Overlapping or unsorted ranges are merged
 * via a boolean mask, and out-of-bounds ranges are clamped. Returns a single
 * non-highlighted segment for empty input so callers can map unconditionally.
 */
export function highlightSegments(
  text: string,
  ranges: ReadonlyArray<OffsetRange>,
): HighlightSegment[] {
  const chars = Array.from(text);
  const n = chars.length;

  if (ranges.length === 0 || n === 0) {
    return [{ text, highlighted: false }];
  }

  const mask = new Array<boolean>(n).fill(false);
  for (const r of ranges) {
    const s = Math.max(0, r.start);
    const e = Math.min(n, r.end);
    for (let i = s; i < e; i++) {
      mask[i] = true;
    }
  }

  const segments: HighlightSegment[] = [];
  let i = 0;
  while (i < n) {
    const flag = mask[i];
    let j = i;
    while (j < n && mask[j] === flag) {
      j++;
    }
    segments.push({ text: chars.slice(i, j).join(''), highlighted: flag });
    i = j;
  }
  return segments;
}

/**
 * Snap each range OUTWARD to the nearest whitespace boundary (in code-point
 * space). The server caps evidence mid-token, so an evidence boundary can fall
 * inside an IOC value; without snapping, `highlightSegments` would split that
 * value across a highlighted and a non-highlighted segment and per-segment PII
 * masking (which only matches whole values) would leak the fragment. Snapping
 * guarantees no value straddles a segment edge. Slight over-highlight is the
 * accepted, honest cost. Ranges are clamped to the text bounds.
 */
export function snapRangesToWhitespace(
  ranges: ReadonlyArray<OffsetRange>,
  text: string,
): OffsetRange[] {
  const chars = Array.from(text);
  const n = chars.length;

  return ranges.map((r) => {
    let start = Math.max(0, Math.min(n, r.start));
    let end = Math.max(start, Math.min(n, r.end));
    while (start > 0 && !/\s/.test(chars[start - 1])) start -= 1;
    while (end < n && !/\s/.test(chars[end])) end += 1;

    return { start, end };
  });
}

/**
 * Case-insensitive, code-point-based occurrences of `value` in `chars`.
 *
 * @param chars code-point array of the haystack (Array.from(text))
 * @return non-overlapping [start, end) spans
 */
function valueSpans(chars: ReadonlyArray<string>, value: string): OffsetRange[] {
  const needle = Array.from(value).map((c) => c.toLowerCase());
  const m = needle.length;

  if (m === 0) {
    return [];
  }

  const hay = chars.map((c) => c.toLowerCase());
  const spans: OffsetRange[] = [];

  for (let i = 0; i + m <= hay.length; i++) {
    let match = true;
    for (let j = 0; j < m; j++) {
      if (hay[i + j] !== needle[j]) {
        match = false;
        break;
      }
    }
    if (match) {
      spans.push({ start: i, end: i + m });
      i += m - 1;
    }
  }

  return spans;
}

/**
 * Expand each range OUTWARD so that any occurrence of a `values` string it
 * partially overlaps is fully enclosed. Whitespace snapping alone is not enough
 * once a maskable value contains internal whitespace (e.g. a postal address):
 * a segment boundary could land inside such a value, and per-segment masking —
 * which only matches whole values — would then leak the exposed fragment. This
 * guarantees no known value straddles a highlight segment edge. No-op when
 * `values` is empty (e.g. masking off). Ranges are clamped to the text bounds.
 */
export function expandRangesOverValues(
  ranges: ReadonlyArray<OffsetRange>,
  text: string,
  values: ReadonlyArray<string>,
): OffsetRange[] {
  if (ranges.length === 0 || values.length === 0) {
    return ranges.map((r) => ({ start: r.start, end: r.end }));
  }

  const chars = Array.from(text);
  const n = chars.length;
  const spans: OffsetRange[] = [];
  for (const v of values) {
    spans.push(...valueSpans(chars, v));
  }

  if (spans.length === 0) {
    return ranges.map((r) => ({ start: r.start, end: r.end }));
  }

  return ranges.map((r) => {
    let start = Math.max(0, Math.min(n, r.start));
    let end = Math.max(start, Math.min(n, r.end));
    let changed = true;
    while (changed) {
      changed = false;
      for (const s of spans) {
        const overlaps = s.start < end && s.end > start;
        if (overlaps && (s.start < start || s.end > end)) {
          start = Math.min(start, s.start);
          end = Math.max(end, s.end);
          changed = true;
        }
      }
    }

    return { start, end };
  });
}
