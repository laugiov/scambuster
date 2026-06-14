import { useCallback, useMemo, useState, type ReactNode } from 'react';
import { MaskModeReactContext, type MaskModeContext } from './useMaskMode';

export function MaskModeProvider({ children }: { children: ReactNode }) {
  const [masked, setMasked] = useState<boolean>(true);
  const [screenShareMode, setScreenShareMode] = useState<boolean>(false);
  const toggle = useCallback(() => setMasked((prev) => !prev), []);
  const toggleScreenShare = useCallback(() => setScreenShareMode((prev) => !prev), []);

  const value = useMemo<MaskModeContext>(
    () => ({ masked, toggle, setMasked, screenShareMode, toggleScreenShare }),
    [masked, toggle, screenShareMode, toggleScreenShare],
  );

  return <MaskModeReactContext.Provider value={value}>{children}</MaskModeReactContext.Provider>;
}
