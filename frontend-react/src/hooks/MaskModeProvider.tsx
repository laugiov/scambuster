import { useCallback, useMemo, useState, type ReactNode } from 'react';
import { MaskModeReactContext, type MaskModeContext } from './useMaskMode';

export function MaskModeProvider({ children }: { children: ReactNode }) {
  const [masked, setMasked] = useState<boolean>(true);
  const toggle = useCallback(() => setMasked((prev) => !prev), []);

  const value = useMemo<MaskModeContext>(
    () => ({ masked, toggle, setMasked }),
    [masked, toggle],
  );

  return <MaskModeReactContext.Provider value={value}>{children}</MaskModeReactContext.Provider>;
}
