import { describe, it, expect, beforeEach } from 'vitest';
import { useAuthStore } from './authStore';

describe('authStore', () => {
  beforeEach(() => {
    useAuthStore.setState({ isAuthenticated: false, isLoading: false, error: null });
    localStorage.clear();
  });

  it('has initial state', () => {
    const state = useAuthStore.getState();
    expect(state.isLoading).toBe(false);
    expect(state.error).toBeNull();
  });

  it('clearError resets error state', () => {
    useAuthStore.setState({ error: 'some error' });
    useAuthStore.getState().clearError();
    expect(useAuthStore.getState().error).toBeNull();
  });

  it('login sets loading state', () => {
    // Don't await — just verify the loading state is set synchronously
    void useAuthStore.getState().login({ email: 'test@test.com', password: 'pass' });
    expect(useAuthStore.getState().isLoading).toBe(true);
    expect(useAuthStore.getState().error).toBeNull();
  });
});
