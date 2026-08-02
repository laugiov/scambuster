import { useCallback, useEffect, useReducer, useRef } from 'react';

/**
 * Theater playback state machine.
 *
 * States: idle → playing ⇄ paused → finished
 * Plus: currentStep (0..messages.length), speed (1|2|4), maskMode (in
 * useMaskMode), typingDirection (transient, drives the indicator UI).
 *
 * Step delay is 3000ms / speed (normal) or 1200ms / speed (reduced
 * motion) between reveals. setTimeout-driven (not RAF) so pause is
 * deterministic. The 3s base gives the viewer time to actually read
 * each message at 1×; 4× = 750ms for snappy demo flythrough.
 *
 * Cleanup invariant: ALL pending timeouts are cleared on unmount via
 * the ref + useEffect return. No state update on unmounted component.
 *
 * Under reduced-motion: typing indicator is skipped, reveals fire at
 * the same speed but with zero typing delay.
 */

export type PlayerStatus = 'idle' | 'playing' | 'paused' | 'finished';

export interface TheaterPlayerState {
  status: PlayerStatus;
  currentStep: number;
  totalSteps: number;
  speed: 1 | 2 | 4;
  typingDirection: 'in' | 'out' | null;
}

type Action =
  | { type: 'PLAY' }
  | { type: 'PAUSE' }
  | { type: 'RESTART' }
  | { type: 'SKIP_TO_END' }
  | { type: 'SCRUB'; step: number }
  | { type: 'STEP_REVEAL' }
  | { type: 'TYPING'; direction: 'in' | 'out' | null }
  | { type: 'SET_SPEED'; speed: 1 | 2 | 4 }
  | { type: 'SET_TOTAL'; total: number };

function reducer(state: TheaterPlayerState, action: Action): TheaterPlayerState {
  switch (action.type) {
    case 'PLAY':
      if (state.currentStep >= state.totalSteps && state.totalSteps > 0) {
        // Auto-restart when pressing play after finish
        return { ...state, status: 'playing', currentStep: 0, typingDirection: null };
      }
      return { ...state, status: 'playing' };
    case 'PAUSE':
      return { ...state, status: 'paused', typingDirection: null };
    case 'RESTART':
      return { ...state, status: 'idle', currentStep: 0, typingDirection: null };
    case 'SKIP_TO_END':
      return { ...state, status: 'finished', currentStep: state.totalSteps, typingDirection: null };
    case 'SCRUB': {
      const step = Math.max(0, Math.min(state.totalSteps, Math.floor(action.step)));
      return {
        ...state,
        status: step >= state.totalSteps && state.totalSteps > 0 ? 'finished' : 'paused',
        currentStep: step,
        typingDirection: null,
      };
    }
    case 'STEP_REVEAL': {
      const next = state.currentStep + 1;
      if (next >= state.totalSteps) {
        return { ...state, currentStep: state.totalSteps, status: 'finished', typingDirection: null };
      }
      return { ...state, currentStep: next, typingDirection: null };
    }
    case 'TYPING':
      return { ...state, typingDirection: action.direction };
    case 'SET_SPEED':
      return { ...state, speed: action.speed };
    case 'SET_TOTAL':
      return {
        ...state,
        totalSteps: action.total,
        currentStep: Math.min(state.currentStep, action.total),
        status: state.currentStep >= action.total ? 'finished' : state.status,
      };
    default:
      return state;
  }
}

interface UseTheaterPlayerProps {
  totalSteps: number;
  reducedMotion?: boolean;
}

export function useTheaterPlayer({ totalSteps, reducedMotion = false }: UseTheaterPlayerProps) {
  const [state, dispatch] = useReducer(reducer, {
    status: 'idle' as PlayerStatus,
    currentStep: 0,
    totalSteps,
    speed: 1 as 1 | 2 | 4,
    typingDirection: null,
  });

  const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Keep total in sync when conv loads/changes
  useEffect(() => {
    dispatch({ type: 'SET_TOTAL', total: totalSteps });
  }, [totalSteps]);

  // Drive the playback loop — ONE timeout per step. The typing indicator
  // is rendered by Theater.tsx as a DERIVED value (status=playing AND
  // !reducedMotion AND step < total). This avoids the dispatch-then-
  // setTimeout combo which was flaky under React 18 Strict Mode.
  //
  // No mountedRef guard: the cleanup's clearTimeout() makes setTimeout
  // unreachable after unmount. A mountedRef would survive across the
  // Strict-Mode double-mount (useRef does NOT reset on remount) and
  // permanently silence the dispatch.
  useEffect(() => {
    if (state.status !== 'playing') return;
    if (state.currentStep >= state.totalSteps) return;

    const delay = reducedMotion ? 1200 / state.speed : 3000 / state.speed;

    timeoutRef.current = setTimeout(() => {
      dispatch({ type: 'STEP_REVEAL' });
    }, delay);

    return () => {
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current);
        timeoutRef.current = null;
      }
    };
  }, [state.status, state.currentStep, state.totalSteps, state.speed, reducedMotion]);

  return {
    state,
    play: useCallback(() => dispatch({ type: 'PLAY' }), []),
    pause: useCallback(() => dispatch({ type: 'PAUSE' }), []),
    restart: useCallback(() => dispatch({ type: 'RESTART' }), []),
    skipToEnd: useCallback(() => dispatch({ type: 'SKIP_TO_END' }), []),
    scrub: useCallback((step: number) => dispatch({ type: 'SCRUB', step }), []),
    setSpeed: useCallback((speed: 1 | 2 | 4) => dispatch({ type: 'SET_SPEED', speed }), []),
  };
}
