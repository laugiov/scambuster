import { describe, it, expect } from 'vitest';
import i18n from '../i18n';

// Execution probe (adversarial review): `count` is special-cased by i18next
// (plural suffix lookup). Prove the bulkConfirm label still interpolates.
describe('i18n verdict interpolation', () => {
  it('bulkConfirm interpolates count in en', async () => {
    await i18n.changeLanguage('en');
    expect(i18n.t('iocVerdict.bulkConfirm', { count: 3 })).toBe('Confirm selection (3)');
    expect(i18n.t('iocVerdict.bulkConfirm', { count: 1 })).toBe('Confirm selection (1)');
  });

  it('bulkResult interpolates ok/failed in fr', async () => {
    await i18n.changeLanguage('fr');
    expect(i18n.t('iocVerdict.bulkResult', { ok: 2, failed: 1 })).toBe('2 confirmé(s), 1 en échec');
  });
});
