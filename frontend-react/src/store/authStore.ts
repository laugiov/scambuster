import { create } from 'zustand';
import { login as apiLogin, logout as apiLogout, clearTokens, hasTokens } from '@/api/client';
import type { LoginRequest } from '@/types/api';

interface AuthState {
  isAuthenticated: boolean;
  isLoading: boolean;
  error: string | null;
  login: (credentials: LoginRequest) => Promise<void>;
  logout: () => Promise<void>;
  clearError: () => void;
}

export const useAuthStore = create<AuthState>((set) => ({
  isAuthenticated: hasTokens(),
  isLoading: false,
  error: null,

  login: async (credentials: LoginRequest) => {
    set({ isLoading: true, error: null });
    try {
      await apiLogin(credentials);
      set({ isAuthenticated: true, isLoading: false });
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Authentication failed';
      set({ isAuthenticated: false, isLoading: false, error: message });
      throw err;
    }
  },

  logout: async () => {
    try {
      await apiLogout();
    } finally {
      clearTokens();
      set({ isAuthenticated: false, error: null });
    }
  },

  clearError: () => set({ error: null }),
}));
