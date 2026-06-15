import { describe, it, expect } from 'vitest';
import { maskPiiInBody } from './maskPiiInBody';

describe('maskPiiInBody — Spec 099 S7', () => {
  it('returns body unchanged when no IOC values', () => {
    expect(maskPiiInBody('hello world', [])).toBe('hello world');
  });

  it('returns body unchanged when body is empty', () => {
    expect(maskPiiInBody('', ['+15555550111'])).toBe('');
  });

  it('replaces a single IOC value with the placeholder', () => {
    const out = maskPiiInBody('Send to +15555550111 please', ['+15555550111']);
    expect(out).toBe('Send to [•••] please');
  });

  it('replaces multiple distinct IOC values', () => {
    const out = maskPiiInBody(
      'Phone +15555550111 and IBAN DE89370400440532013000 today',
      ['+15555550111', 'DE89370400440532013000'],
    );
    expect(out).toBe('Phone [•••] and IBAN [•••] today');
  });

  it('is case-insensitive', () => {
    const out = maskPiiInBody('Visit https://Example.Com/x', ['https://example.com/x']);
    expect(out).toBe('Visit [•••]');
  });

  it('masks the un-defanged form of a defanged value_norm', () => {
    // value_norm stored defanged ('acme[.]example'); body has the canonical form.
    const out = maskPiiInBody('Go to acme.example/page', ['acme[.]example/page']);
    expect(out).toContain('[•••]');
    expect(out).not.toContain('acme.example/page');
  });

  it('ignores IOC values shorter than 3 chars (to avoid over-masking)', () => {
    expect(maskPiiInBody('a small test sentence', ['a', 'an'])).toBe('a small test sentence');
  });

  it('handles overlapping values by preferring the longest match', () => {
    // "acme.example.com" contains "acme.example" — must mask once with the longer match.
    const out = maskPiiInBody('Go to acme.example.com here', ['acme.example', 'acme.example.com']);
    expect(out).toBe('Go to [•••] here');
  });

  // Regression — body PII leaked when the body kept the display form
  // (e.g. "+91-7906757261") while the IOC was indexed only via the
  // normalized form (e.g. "+917906757261"). Caller is now expected to
  // pass BOTH forms; this asserts the masker handles them in one pass.
  it('masks both display and normalized forms when both are supplied', () => {
    const out = maskPiiInBody(
      'Reach me at +91-7906757261 or fallback +917906757261',
      ['+917906757261', '+91-7906757261'],
    );
    expect(out).toBe('Reach me at [•••] or fallback [•••]');
  });
});
