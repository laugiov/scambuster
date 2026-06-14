import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { TheaterIoc, TheaterMessage } from '@/hooks/useTheaterReplay';
import { MaskedValue } from './MaskedValue';

interface TheaterMoneyShotProps {
  iocs: TheaterIoc[];
  messages: TheaterMessage[];
  visibleStep: number;
}

const FINANCIAL_TYPES = new Set([
  'iban',
  'bic',
  'wallet_btc',
  'wallet_eth',
  'wallet_xmr',
  'bank_account',
  'credit_card',
]);

/**
 * Spec 100 S1 — Pinned "money-shot" banners for financial IOCs.
 *
 * The headline conclusion of every demo conversation is "the scammer
 * eventually asked for money — here's what they asked for and how late
 * it came". Until now those IOCs (IBAN, BIC, bank account, wallet)
 * were rendered as ordinary cards somewhere in the catalog list, so
 * when they revealed at turn 12/13 nobody at the back of the room
 * saw them. This component pins them at the top of the Intelligence
 * panel with amber framing and the explicit reveal-turn ratio.
 *
 * Each banner pulses once on first appearance (1.5s) to catch the eye
 * during live playback, then sits static. Subsequent renders of the
 * same IOC do not re-pulse.
 *
 * Hides itself when no financial IOC is yet visible at the current
 * playback step.
 */
export function TheaterMoneyShot({ iocs, messages, visibleStep }: TheaterMoneyShotProps) {
  const { t } = useTranslation();
  const totalTurns = messages.length;
  const idxByMsg = new Map<string, number>();
  messages.forEach((m) => idxByMsg.set(m.msg_id, m.idx));

  const visibleFinancial = iocs
    .filter((ioc) => FINANCIAL_TYPES.has(ioc.type))
    .filter((ioc) => {
      const parentIdx = idxByMsg.get(ioc.msg_id);
      return typeof parentIdx === 'number' && visibleStep >= parentIdx;
    })
    .sort((a, b) => (idxByMsg.get(a.msg_id) ?? 0) - (idxByMsg.get(b.msg_id) ?? 0));

  if (visibleFinancial.length === 0) return null;

  return (
    <div className="flex flex-col gap-2" data-testid="theater-money-shot">
      <h3 className="text-[10px] font-mono uppercase tracking-widest text-amber-400/80">
        ★ {t('theater.money_shot_title')}
      </h3>
      {visibleFinancial.map((ioc) => {
        // Spec 101 S1 — `m.idx` is serialised by the backend as 1-based
        // (1..N). DO NOT add +1; that produced "turn 9/9 — 100%" on a
        // financial that actually revealed at turn 8/9 (= 89%), and the
        // inflated ratio was visible on the BH-review screenshots.
        const parentIdx = idxByMsg.get(ioc.msg_id) ?? 0;
        const turn = parentIdx;
        const ratioPct = totalTurns > 0 ? Math.round((turn / totalTurns) * 100) : 0;
        return (
          <MoneyShotCard
            key={ioc.indicator_id}
            ioc={ioc}
            turn={turn}
            total={totalTurns}
            ratioPct={ratioPct}
          />
        );
      })}
    </div>
  );
}

function MoneyShotCard({
  ioc,
  turn,
  total,
  ratioPct,
}: {
  ioc: TheaterIoc;
  turn: number;
  total: number;
  ratioPct: number;
}) {
  const { t } = useTranslation();
  const [fresh, setFresh] = useState(true);
  const seenRef = useRef(false);

  useEffect(() => {
    if (seenRef.current) return;
    seenRef.current = true;
    const id = setTimeout(() => setFresh(false), 1800);
    return () => clearTimeout(id);
  }, []);

  return (
    <div
      data-testid={`money-shot-card-${ioc.type}`}
      className={`rounded-lg border-2 border-amber-400/60 bg-linear-to-br from-amber-500/15 to-amber-500/5 p-4 shadow-lg shadow-amber-500/10 ${
        fresh ? 'animate-pulse' : ''
      }`}
    >
      <div className="flex items-baseline justify-between gap-2 mb-2">
        <span className="text-[10px] uppercase tracking-widest font-mono px-2 py-0.5 rounded bg-amber-500/30 text-amber-200 font-bold">
          {ioc.type}
        </span>
        <span className="text-[10px] font-mono text-amber-300/90">
          {t('theater.money_shot_revealed_at', { turn, total, pct: ratioPct })}
        </span>
      </div>
      <p className="text-base font-mono text-amber-50 break-all font-semibold">
        <MaskedValue value={ioc.value} type={ioc.type} />
      </p>
    </div>
  );
}
