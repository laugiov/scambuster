import { describe, it, expect } from 'vitest';
import { iocSeverity } from './iocSeverity';

describe('iocSeverity', () => {
  describe('HIGH value types (always HIGH regardless of VT)', () => {
    it.each(['iban', 'wallet_btc', 'wallet_eth', 'wallet_xmr', 'phone', 'bic', 'bank_account', 'credit_card'])(
      '%s → HIGH',
      (type) => {
        expect(iocSeverity(type, 0, 0).label).toBe('HIGH');
      },
    );
  });

  describe('MEDIUM value types (MEDIUM by default, HIGH if VT > 0)', () => {
    it.each(['url', 'domain', 'email', 'ipv4', 'ipv6', 'sha256'])(
      '%s with VT=0 → MEDIUM',
      (type) => {
        expect(iocSeverity(type, 0, 0).label).toBe('MEDIUM');
      },
    );

    it.each(['url', 'domain', 'email', 'ipv4'])(
      '%s with VT>0 → HIGH',
      (type) => {
        expect(iocSeverity(type, 5, 0).label).toBe('HIGH');
      },
    );

    it('upgrades to HIGH on urlscan score too', () => {
      expect(iocSeverity('domain', 0, 3).label).toBe('HIGH');
    });
  });

  describe('LOW value types (metadata)', () => {
    it.each(['subject', 'message_id', 'dmarc_result', 'spf_result', 'unknown_type'])(
      '%s → LOW',
      (type) => {
        expect(iocSeverity(type, 0, 0).label).toBe('LOW');
      },
    );
  });

  describe('API severity override', () => {
    it('uses API severity when provided', () => {
      expect(iocSeverity('subject', 0, 0, 'HIGH').label).toBe('HIGH');
    });
  });

  describe('returns correct style objects', () => {
    it('HIGH has error colors', () => {
      const sev = iocSeverity('iban', 0, 0);
      expect(sev.color).toContain('error');
      expect(sev.border).toContain('error');
    });

    it('MEDIUM has warning colors', () => {
      const sev = iocSeverity('domain', 0, 0);
      expect(sev.color).toContain('warning');
    });

    it('LOW has waiting colors', () => {
      const sev = iocSeverity('subject', 0, 0);
      expect(sev.color).toContain('waiting');
    });
  });
});
