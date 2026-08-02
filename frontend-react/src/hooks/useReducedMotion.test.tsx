import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useReducedMotion } from './useReducedMotion';

describe('useReducedMotion', () => {
  let mockMql: { matches: boolean; addEventListener: ReturnType<typeof vi.fn>; removeEventListener: ReturnType<typeof vi.fn>; addListener: ReturnType<typeof vi.fn>; removeListener: ReturnType<typeof vi.fn> };
  let changeHandler: ((e: MediaQueryListEvent) => void) | null = null;

  beforeEach(() => {
    changeHandler = null;
    mockMql = {
      matches: false,
      addEventListener: vi.fn((_event: string, handler: (e: MediaQueryListEvent) => void) => {
        changeHandler = handler;
      }),
      removeEventListener: vi.fn(),
      addListener: vi.fn(),
      removeListener: vi.fn(),
    };
    vi.stubGlobal('matchMedia', vi.fn(() => mockMql));
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('returns false when prefers-reduced-motion is not set', () => {
    mockMql.matches = false;
    const { result } = renderHook(() => useReducedMotion());
    expect(result.current).toBe(false);
  });

  it('returns true when prefers-reduced-motion: reduce is set', () => {
    mockMql.matches = true;
    const { result } = renderHook(() => useReducedMotion());
    expect(result.current).toBe(true);
  });

  it('reacts to runtime changes via addEventListener', () => {
    mockMql.matches = false;
    const { result } = renderHook(() => useReducedMotion());
    expect(result.current).toBe(false);
    act(() => {
      changeHandler?.({ matches: true } as MediaQueryListEvent);
    });
    expect(result.current).toBe(true);
  });

  it('cleans up the listener on unmount', () => {
    const { unmount } = renderHook(() => useReducedMotion());
    unmount();
    expect(mockMql.removeEventListener).toHaveBeenCalled();
  });
});
