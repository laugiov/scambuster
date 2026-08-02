import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useTheaterPlayer } from './useTheaterPlayer';

describe('useTheaterPlayer — state machine', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  function setup(totalSteps = 5, reducedMotion = false) {
    return renderHook(() => useTheaterPlayer({ totalSteps, reducedMotion }));
  }

  it('starts in idle status with currentStep=0', () => {
    const { result } = setup(5);
    expect(result.current.state.status).toBe('idle');
    expect(result.current.state.currentStep).toBe(0);
    expect(result.current.state.totalSteps).toBe(5);
    expect(result.current.state.speed).toBe(1);
    expect(result.current.state.typingDirection).toBe(null);
  });

  it('PLAY transitions to playing and advances steps over time', () => {
    const { result } = setup(3, true /* reducedMotion */);
    act(() => result.current.play());
    expect(result.current.state.status).toBe('playing');
    act(() => {
      vi.advanceTimersByTime(1200);
    });
    expect(result.current.state.currentStep).toBe(1);
    act(() => {
      vi.advanceTimersByTime(1200);
    });
    expect(result.current.state.currentStep).toBe(2);
    act(() => {
      vi.advanceTimersByTime(1200);
    });
    expect(result.current.state.status).toBe('finished');
    expect(result.current.state.currentStep).toBe(3);
  });

  it('PAUSE stops advancing and clears typing indicator', () => {
    const { result } = setup(5);
    act(() => result.current.play());
    act(() => {
      vi.advanceTimersByTime(100);
    });
    act(() => result.current.pause());
    const stepBefore = result.current.state.currentStep;
    expect(result.current.state.status).toBe('paused');
    expect(result.current.state.typingDirection).toBe(null);
    act(() => {
      vi.advanceTimersByTime(5000);
    });
    expect(result.current.state.currentStep).toBe(stepBefore);
  });

  it('RESTART resets to idle + currentStep=0', () => {
    const { result } = setup(5, true);
    act(() => result.current.play());
    act(() => {
      vi.advanceTimersByTime(2000);
    });
    expect(result.current.state.currentStep).toBeGreaterThan(0);
    act(() => result.current.restart());
    expect(result.current.state.status).toBe('idle');
    expect(result.current.state.currentStep).toBe(0);
  });

  it('SKIP_TO_END jumps to finished + currentStep=total', () => {
    const { result } = setup(5);
    act(() => result.current.skipToEnd());
    expect(result.current.state.status).toBe('finished');
    expect(result.current.state.currentStep).toBe(5);
  });

  it('SCRUB pauses and clamps step within [0, total]', () => {
    const { result } = setup(5);
    act(() => result.current.scrub(3));
    expect(result.current.state.status).toBe('paused');
    expect(result.current.state.currentStep).toBe(3);
    act(() => result.current.scrub(99));
    expect(result.current.state.currentStep).toBe(5);
    expect(result.current.state.status).toBe('finished');
    act(() => result.current.scrub(-5));
    expect(result.current.state.currentStep).toBe(0);
  });

  it('SET_SPEED changes the multiplier and shortens delays', () => {
    const { result } = setup(2, true /* reducedMotion: 1200ms base */);
    act(() => result.current.setSpeed(4));
    expect(result.current.state.speed).toBe(4);
    act(() => result.current.play());
    act(() => {
      vi.advanceTimersByTime(300); // 1200 / 4
    });
    expect(result.current.state.currentStep).toBe(1);
  });

  it('PLAY after finished auto-restarts to 0', () => {
    const { result } = setup(2, true);
    act(() => result.current.skipToEnd());
    expect(result.current.state.status).toBe('finished');
    act(() => result.current.play());
    expect(result.current.state.status).toBe('playing');
    expect(result.current.state.currentStep).toBe(0);
  });

  it('CRITICAL: cleanup on unmount clears pending timeouts (no setTimeout after unmount)', () => {
    const { result, unmount } = setup(10);
    act(() => result.current.play());
    act(() => {
      vi.advanceTimersByTime(100);
    });
    expect(vi.getTimerCount()).toBeGreaterThan(0);
    unmount();
    expect(vi.getTimerCount()).toBe(0);
    // No more state updates should happen even if we advance time
    vi.advanceTimersByTime(10_000);
    // No assertion crash = success (no act() warning, no leak)
  });

  it('Reduced-motion: reveal after 1200ms / speed', () => {
    const { result } = setup(3, true);
    act(() => result.current.play());
    act(() => {
      vi.advanceTimersByTime(1199);
    });
    expect(result.current.state.currentStep).toBe(0);
    act(() => {
      vi.advanceTimersByTime(1);
    });
    expect(result.current.state.currentStep).toBe(1);
  });

  it('REGRESSION: survives React Strict-Mode double-mount and still advances', () => {
    // Strict Mode mounts the component twice in dev. A previous version of
    // this hook kept a `mountedRef` that was set to `false` on the first
    // cleanup and never reset on remount, permanently silencing the
    // STEP_REVEAL dispatch. This test simulates that pattern by rendering
    // → unmounting → rendering a second time and asserting playback still
    // advances on the second instance.
    const { unmount } = renderHook(() => useTheaterPlayer({ totalSteps: 3, reducedMotion: true }));
    unmount();
    const second = renderHook(() => useTheaterPlayer({ totalSteps: 3, reducedMotion: true }));
    act(() => second.result.current.play());
    act(() => {
      vi.advanceTimersByTime(1200);
    });
    expect(second.result.current.state.currentStep).toBe(1);
  });

  it('Normal motion: reveal after typing delay (3000ms / speed)', () => {
    const { result } = setup(3, false);
    act(() => result.current.play());
    act(() => {
      vi.advanceTimersByTime(2999);
    });
    expect(result.current.state.currentStep).toBe(0);
    act(() => {
      vi.advanceTimersByTime(1);
    });
    expect(result.current.state.currentStep).toBe(1);
  });
});
