import { humanize } from './scamTypeLabels';

const IOC_TYPE_MAP: Record<string, string> = {
  ipv4: 'IPv4',
  ipv6: 'IPv6',
  iban: 'IBAN',
  bic: 'BIC',
  url: 'URL',
  wallet_btc: 'Wallet BTC',
  wallet_eth: 'Wallet ETH',
  wallet_xmr: 'Wallet XMR',
  telegram_username: 'Telegram',
  sha256: 'SHA256',
  sha1: 'SHA1',
  md5: 'MD5',
  message_id: 'Message ID',
  dmarc_result: 'DMARC',
  spf_result: 'SPF',
  dkim_result: 'DKIM',
  credit_card: 'Credit Card',
  bank_account: 'Bank Account',
  whois_email: 'WHOIS Email',
  phone: 'Phone',
  email: 'Email',
  domain: 'Domain',
  filename: 'Filename',
  subject: 'Subject',
  registrar: 'Registrar',
};

export function iocTypeLabel(type: string): string {
  return IOC_TYPE_MAP[type.toLowerCase()] ?? humanize(type);
}
