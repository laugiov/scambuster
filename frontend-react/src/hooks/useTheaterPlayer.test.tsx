import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useTheaterPlayer } from './useTheaterPlayer';

describe('useTheaterPlayer / Spec 097 S4 — state machine', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  function setup(totalSteps = 5, reducedMotion = false) {
    const directionAt = (step: number): 'in' | 'out' | null =>
      step % 2 === 0 ? 'in' : 'out';
    return renderHook(() => useTheaterPlayer({ totalSteps, directionAt, reducedMotion }));
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
      vi.advanceTimersByTime(600);
    });
    expect(result.current.state.currentStep).toBe(1);
    act(() => {
      vi.advanceTimersByTime(600);
    });
    expect(result.current.state.currentStep).toBe(2);
    act(() => {
      vi.advanceTimersByTime(600);
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
    const { result } = setup(2, true);
    act(() => result.current.setSpeed(4));
    expect(result.current.state.speed).toBe(4);
    act(() => result.current.play());
    act(() => {
      vi.advanceTimersByTime(150); // 600 / 4
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

  it('Reduced-motion: no typing direction is set, reveal is immediate', () => {
    const { result } = setup(3, true);
    act(() => result.current.play());
    act(() => {
      vi.advanceTimersByTime(0);
    });
    expect(result.current.state.typingDirection).toBe(null);
  });

  it('Normal motion: typing direction is set before reveal', () => {
    const { result } = setup(3, false);
    act(() => result.current.play());
    act(() => {
      vi.advanceTimersByTime(0);
    });
    expect(result.current.state.typingDirection).toBe('in');
  });
});
