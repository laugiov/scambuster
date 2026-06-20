import { describe, it, expect } from 'vitest';
import { NON_ACTIONABLE_IOC_TYPES, isActionableIocType } from './iocActionable';

/**
 * Spec 111 — parity test pinning the TS list to the PHP source of truth
 * at `backend-symfony/src/Domain/Communication/Policy/IocActionablePolicy::NON_ACTIONABLE_TYPES`.
 * Any change to one side MUST be mirrored on the other in the same
 * commit; the PHP sibling test (`IocActionablePolicyTest::testNonActionableTypesListIsPinned`)
 * flips the same assertion.
 */
describe('NON_ACTIONABLE_IOC_TYPES (spec 111)', () => {
  it('pins the verbatim list (in sync with the PHP policy)', () => {
    const expected = [
      // Header metadata
      'subject', 'message_id', 'x_mailer', 'return_path',
      // Auth results
      'spf_result', 'dkim_result', 'dmarc_result',
      // WHOIS metadata
      'whois_email', 'whois_registrar_name', 'registrar',
      // File metadata
      'filename', 'mimetype',
      // Reference identifiers
      'cve', 'malware_family', 'mitre_attack_id', 'tracking_number',
      // File hashes
      'md5', 'sha1', 'sha256',
    ];

    expect([...NON_ACTIONABLE_IOC_TYPES].sort()).toEqual(expected.slice().sort());
    expect(NON_ACTIONABLE_IOC_TYPES.size).toBe(expected.length);
  });
});

describe('isActionableIocType (spec 111)', () => {
  it('returns false for each non-actionable type', () => {
    NON_ACTIONABLE_IOC_TYPES.forEach((type) => {
      expect(isActionableIocType(type)).toBe(false);
    });
  });

  it('returns true for representative actionable types', () => {
    const actionable = [
      'email', 'phone', 'iban', 'bic', 'url', 'domain',
      'ipv4', 'ipv6', 'wallet_btc', 'wallet_eth', 'wallet_xmr',
      'bank_account', 'credit_card', 'telegram_username',
      'discord_username', 'skype_id', 'postal_address',
    ];

    actionable.forEach((type) => {
      expect(isActionableIocType(type)).toBe(true);
    });
  });

  it('is case-insensitive', () => {
    expect(isActionableIocType('Subject')).toBe(false);
    expect(isActionableIocType('MESSAGE_ID')).toBe(false);
    expect(isActionableIocType('IBAN')).toBe(true);
    expect(isActionableIocType('Email')).toBe(true);
  });

  it('defaults to actionable for unknown types', () => {
    expect(isActionableIocType('totally_new_type')).toBe(true);
  });
});
