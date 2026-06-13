import { describe, it, expect } from 'vitest';
import en from './en.json';
import fr from './fr.json';

/**
 * Spec 097 — i18n parity test (preventive against EN/FR drift).
 *
 * Recursively collects all leaf keys (dot-path notation) and asserts
 * EN and FR have the exact same set.
 */
function collectKeys(obj: unknown, prefix = ''): string[] {
  if (obj === null || typeof obj !== 'object') return [];
  const out: string[] = [];
  for (const [key, value] of Object.entries(obj)) {
    const path = prefix ? `${prefix}.${key}` : key;
    if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
      out.push(...collectKeys(value, path));
    } else {
      out.push(path);
    }
  }
  return out;
}

describe('i18n EN/FR parity', () => {
  it('both locales must have the same set of keys', () => {
    const enKeys = collectKeys(en).sort();
    const frKeys = collectKeys(fr).sort();
    const enOnly = enKeys.filter((k) => !frKeys.includes(k));
    const frOnly = frKeys.filter((k) => !enKeys.includes(k));
    expect(enOnly, `Keys present in EN but missing in FR: ${enOnly.join(', ')}`).toEqual([]);
    expect(frOnly, `Keys present in FR but missing in EN: ${frOnly.join(', ')}`).toEqual([]);
  });
});
