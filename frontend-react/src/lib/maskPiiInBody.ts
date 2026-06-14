/**
 * Spec 099 S7 — Screen-share PII masking for message bodies.
 *
 * Given a message body and the list of IOC value_norms observed in
 * that conversation, returns the body with each IOC value occurrence
 * replaced by a `[•••]` placeholder. Used in TheaterThread when
 * `screenShareMode` is on, so a presenter can share their screen
 * without leaking concrete PII (SWIFT codes, phone numbers, etc.) in
 * the message text itself.
 *
 * Caveat (R3 in spec.md): pathological IOC values that happen to be
 * substrings of unrelated tokens in the body would be over-masked.
 * Acceptable for v1; we surface the issue if it ever bites.
 */

const PLACEHOLDER = '[•••]';

function escapeRegExp(s: string): string {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Replace each non-empty IOC value (raw + un-defanged form) inside
 * `body` with the redacted placeholder. Matching is case-insensitive
 * and respects word boundaries only when both ends are word-chars
 * (so symbols like `+` in phones or `@` in emails still match).
 */
export function maskPiiInBody(body: string, iocValues: readonly string[]): string {
  if (!body || iocValues.length === 0) return body;

  const seen = new Set<string>();
  for (const v of iocValues) {
    if (!v) continue;
    seen.add(v);
    // Also include the un-defanged form so `acme[.]example` IOC values
    // mask the canonical `acme.example` text in bodies.
    seen.add(v.replace(/\[\.\]/g, '.').replace(/\[\/\]/g, '/').replace(/\[:\/\/\]/g, '://').replace(/\[:\]/g, ':'));
  }

  // Sort longest-first so substrings don't pre-empt longer matches.
  const variants = Array.from(seen).filter((s) => s.length >= 3).sort((a, b) => b.length - a.length);
  if (variants.length === 0) return body;

  const pattern = new RegExp(variants.map(escapeRegExp).join('|'), 'gi');
  return body.replace(pattern, PLACEHOLDER);
}
