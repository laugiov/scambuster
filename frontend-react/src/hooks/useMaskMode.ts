import { createContext, useContext } from 'react';

/**
 * Centralized mask state.
 *
 * A single boolean controls masking of every sensitive value in the
 * Theater: header conv addresses, message body inline PII, and the
 * right-panel IOC catalog. ALWAYS defaults to true on mount; reveal
 * must be a deliberate user action and is NOT persisted.
 *
 * Bound to two equivalent keyboard shortcuts (S, M) so muscle-memory
 * around either still works.
 */

export interface MaskModeContext {
  masked: boolean;
  toggle: () => void;
  setMasked: (masked: boolean) => void;
}

export const MaskModeReactContext = createContext<MaskModeContext | undefined>(undefined);

export function useMaskMode(): MaskModeContext {
  const ctx = useContext(MaskModeReactContext);
  if (!ctx) {
    // Default fallback when used outside provider: always masked,
    // toggle no-op. Lets <MaskedValue> render in isolation (e.g.
    // unit tests) without crashing.
    return {
      masked: true,
      toggle: () => {},
      setMasked: () => {},
    };
  }
  return ctx;
}
