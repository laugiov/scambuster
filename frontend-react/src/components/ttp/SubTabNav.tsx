import type { ReactNode } from 'react';

export interface SubTab {
  id: string;
  label: ReactNode;
}

/**
 * Secondary, in-tab navigation used inside the TTP Explorer tabs (Playbooks,
 * Analytics). It reuses the main tab-row idiom (a border-b-2 -mb-px underline
 * button row) but renders visually SECONDARY — smaller type, tighter padding
 * and a lighter accent — so it reads as a sub-level rather than a duplicate of
 * the top-level tabs. Purely presentational: the parent owns the active id and
 * the selection handler (URL wiring lives in the page).
 *
 * A plain button nav (matching the house main tab-row idiom, not the full ARIA
 * tabs pattern): declaring role="tablist"/"tab" without tabpanels or arrow-key
 * roving would set a keyboard expectation the page does not wire, so the active
 * item is marked with aria-current instead — honest for assistive tech and
 * consistent with the top-level nav.
 */
export function SubTabNav({ tabs, active, onSelect }: {
  tabs: SubTab[];
  active: string;
  onSelect: (id: string) => void;
}) {
  return (
    <nav className="flex gap-1 border-b border-surface-high/60">
      {tabs.map((tab) => (
        <button
          key={tab.id}
          type="button"
          aria-current={active === tab.id ? 'true' : undefined}
          data-testid={`ttp-subtab-${tab.id}`}
          onClick={() => onSelect(tab.id)}
          className={`px-3 py-1.5 text-xs font-medium transition-colors border-b-2 -mb-px ${
            active === tab.id
              ? 'border-accent/70 text-accent'
              : 'border-transparent text-on-surface-dim hover:text-on-surface'
          }`}
        >
          {tab.label}
        </button>
      ))}
    </nav>
  );
}
