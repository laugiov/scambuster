import { describe, it, expect } from 'vitest';
import { iocTypeLabel } from './iocTypeLabels';

describe('iocTypeLabel', () => {
  it('returns proper acronyms for standard types', () => {
    expect(iocTypeLabel('ipv4')).toBe('IPv4');
    expect(iocTypeLabel('ipv6')).toBe('IPv6');
    expect(iocTypeLabel('iban')).toBe('IBAN');
    expect(iocTypeLabel('bic')).toBe('BIC');
    expect(iocTypeLabel('url')).toBe('URL');
    expect(iocTypeLabel('sha256')).toBe('SHA256');
    expect(iocTypeLabel('md5')).toBe('MD5');
  });

  it('returns proper labels for wallet types', () => {
    expect(iocTypeLabel('wallet_btc')).toBe('Wallet BTC');
    expect(iocTypeLabel('wallet_eth')).toBe('Wallet ETH');
    expect(iocTypeLabel('wallet_xmr')).toBe('Wallet XMR');
  });

  it('returns simple labels for common types', () => {
    expect(iocTypeLabel('email')).toBe('Email');
    expect(iocTypeLabel('domain')).toBe('Domain');
    expect(iocTypeLabel('phone')).toBe('Phone');
    expect(iocTypeLabel('telegram_username')).toBe('Telegram');
  });

  it('is case-insensitive', () => {
    expect(iocTypeLabel('IPV4')).toBe('IPv4');
    expect(iocTypeLabel('IBAN')).toBe('IBAN');
  });

  it('falls back to humanize for unknown types', () => {
    expect(iocTypeLabel('some_new_type')).toBe('Some New Type');
  });
});
