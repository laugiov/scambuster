import { createContext, useContext } from 'react';

/**
 * Spec 097 — Centralized mask state for the Theater.
 *
 * Single source of truth. All `<MaskedValue>` components in the Theater
 * tree read from this context and re-render when the toggle flips.
 * Mask state ALWAYS defaults to true on mount — reveal must be a
 * deliberate user action, and is NOT persisted across reloads.
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
    // toggle no-op. This makes <MaskedValue> usable in isolation
    // (e.g. in a test) without crashing.
    return { masked: true, toggle: () => {}, setMasked: () => {} };
  }
  return ctx;
}
