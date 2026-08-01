import { useCallback, useMemo, useState, type ReactNode } from 'react';
import { MaskModeReactContext, type MaskModeContext } from './useMaskMode';

/**
 * Theater mask state.
 *
 * Single source of truth across:
 *   - header conv addresses (scammer / persona)
 *   - message body inline PII (emails, phones quoted in text)
 *   - right-panel IOC catalog values
 *
 * Default: masked=true on mount. Reveal is always a deliberate user
 * action and is NOT persisted across reloads. Previous implementation
 * split this into two independent toggles (`masked` for catalog, S key
 * for body PII) which left the header always-revealed and put two
 * keyboard shortcuts in the operator's head — both fragile under stage
 * pressure. Now everything flips together.
 */
export function MaskModeProvider({ children }: { children: ReactNode }) {
  const [masked, setMasked] = useState<boolean>(true);
  const toggle = useCallback(() => setMasked((prev) => !prev), []);

  const value = useMemo<MaskModeContext>(
    () => ({ masked, toggle, setMasked }),
    [masked, toggle],
  );

  return <MaskModeReactContext.Provider value={value}>{children}</MaskModeReactContext.Provider>;
}
