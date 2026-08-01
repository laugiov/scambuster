import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { TheaterIoc, TheaterMessage } from '@/hooks/useTheaterReplay';
import { TheaterIocCard } from './TheaterIocCard';
import { TheaterMoneyShot } from './TheaterMoneyShot';
import { TheaterDomainClusterCard } from './TheaterDomainClusterCard';
import { TheaterTradecraftChips } from './TheaterTradecraftChips';
import { tierForIocType } from '@/lib/iocTier';
import { clusterDomainVariants } from '@/lib/domainVariants';

const FINANCIAL_TYPES = new Set([
  'iban', 'bic', 'wallet_btc', 'wallet_eth', 'wallet_xmr', 'bank_account', 'credit_card',
]);

interface TheaterIntelligencePanelProps {
  iocs: TheaterIoc[];
  messages: TheaterMessage[];
  visibleStep: number;
}

/**
 * Intelligence panel showing IOCs as they appear over the
 * playback. Each IOC appears when its parent message is revealed
 * (visibleStep >= parent_msg.idx).
 */
export function TheaterIntelligencePanel({ iocs, messages, visibleStep }: TheaterIntelligencePanelProps) {
  const { t } = useTranslation();
  const [contextExpanded, setContextExpanded] = useState(false);

  // Map msg_id -> idx so we know when to reveal each IOC.
  const idxByMsg = new Map<string, number>();
  messages.forEach((m) => idxByMsg.set(m.msg_id, m.idx));

  const visibleIocs = iocs.filter((ioc) => {
    const parentIdx = idxByMsg.get(ioc.msg_id);
    return typeof parentIdx === 'number' && visibleStep >= parentIdx;
  });

  // Tier each visible IOC into Actionable vs Context.
  // Headline counts Actionable only; Context goes into a collapsible
  // section below so header artifacts (subject, message_id, dmarc/spf/
  // dkim results, whois_*) don't inflate the analyst-pivotable count.
  // Financial IOCs are extracted into the Money-Shot
  // pinned banner at the top, so they are NOT also rendered in the
  // actionable list below (would be a duplicate).
  const actionableAll = visibleIocs.filter((i) => tierForIocType(i.type) === 'actionable');
  const actionable = actionableAll.filter((i) => !FINANCIAL_TYPES.has(i.type));
  const context = visibleIocs.filter((i) => tierForIocType(i.type) === 'context');
  const financialCount = actionableAll.filter((i) => FINANCIAL_TYPES.has(i.type)).length;

  if (iocs.length === 0) {
    return (
      <section className="p-5 flex flex-col gap-3">
        <h2 className="text-xs font-mono uppercase tracking-widest text-on-surface-dim">
          {t('theater.intelligence_panel')}
        </h2>
        <p className="text-sm text-on-surface-dim italic">
          {t('theater.no_iocs')}
        </p>
      </section>
    );
  }

  return (
    <section className="p-5 flex flex-col gap-3" data-testid="theater-intelligence-panel">
      <h2 className="text-xs font-mono uppercase tracking-widest text-on-surface-dim">
        {t('theater.intelligence_panel')}
      </h2>
      <div className="flex items-baseline gap-3" data-testid="intelligence-headline">
        <p className="text-3xl font-light text-on-surface">{actionableAll.length}</p>
        <p className="text-xs text-on-surface-dim">
          {t('theater.iocs_extracted', { count: actionableAll.length })}
        </p>
      </div>
      {financialCount > 0 && (
        <p className="text-xs text-amber-300 font-mono">
          {t('theater.financial_count', { count: financialCount })}
        </p>
      )}

      {/* Pinned money-shot at the top when financial
          IOCs have already revealed. Stacks one card per IOC. */}
      <TheaterMoneyShot iocs={visibleIocs} messages={messages} visibleStep={visibleStep} />

      {/* Tradecraft signals (e.g. email trackers) in
          the bodies of already-revealed messages. */}
      <TheaterTradecraftChips messages={messages} visibleStep={visibleStep} />

      {/* Actionable tier — financial, contact, infrastructure.
          Domain + url IOCs go through clusterDomainVariants
          so near-duplicates (techward + techwardinfo) collapse into one
          primary card with a `▸ N variants` chip. */}
      {actionable.length > 0 && (
        <div className="flex flex-col gap-2 mt-2" data-testid="intelligence-actionable">
          {clusterDomainVariants(actionable).map((cluster) => (
            <TheaterDomainClusterCard
              key={cluster.primary.indicator_id}
              cluster={cluster}
            />
          ))}
        </div>
      )}

      {/* Context tier — collapsible. Subject / message_id / dmarc / whois
          are useful for correlation but bury the headline if counted. */}
      {context.length > 0 && (
        <div className="mt-4 border-t border-outline-variant pt-3" data-testid="intelligence-context">
          <button
            type="button"
            onClick={() => setContextExpanded((v) => !v)}
            className="text-[11px] font-mono uppercase tracking-widest text-on-surface-dim hover:text-on-surface flex items-center gap-2 w-full"
            data-testid="intelligence-context-toggle"
          >
            <span>{contextExpanded ? '▾' : '▸'}</span>
            <span>{t('theater.context_section', { count: context.length })}</span>
          </button>
          {contextExpanded && (
            <div className="flex flex-col gap-2 mt-2" data-testid="intelligence-context-list">
              {context.map((ioc) => (
                <TheaterIocCard key={ioc.indicator_id} ioc={ioc} />
              ))}
            </div>
          )}
        </div>
      )}
    </section>
  );
}
