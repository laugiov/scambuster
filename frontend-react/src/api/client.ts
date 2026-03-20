import axios from 'axios';
import type { AxiosError, InternalAxiosRequestConfig } from 'axios';
import type { LoginRequest, LoginResponse, RefreshRequest } from '@/types/api';

const API_BASE = '/api/v1';

const client = axios.create({
  baseURL: API_BASE,
  headers: { 'Content-Type': 'application/json' },
  timeout: 15000,
});

let accessToken: string | null = null;
let refreshToken: string | null = null;
let isRefreshing = false;
let failedQueue: Array<{
  resolve: (token: string) => void;
  reject: (error: unknown) => void;
}> = [];

function processQueue(error: unknown, token: string | null): void {
  failedQueue.forEach(({ resolve, reject }) => {
    if (error) {
      reject(error);
    } else if (token) {
      resolve(token);
    }
  });
  failedQueue = [];
}

export function setTokens(access: string, refresh: string): void {
  accessToken = access;
  refreshToken = refresh;
}

export function clearTokens(): void {
  accessToken = null;
  refreshToken = null;
}

export function hasTokens(): boolean {
  return accessToken !== null;
}

// Request interceptor: attach JWT
client.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  if (accessToken && config.headers) {
    config.headers.Authorization = `Bearer ${accessToken}`;
  }
  return config;
});

// Response interceptor: handle 401 with token refresh
client.interceptors.response.use(
  (response) => response,
  async (error: AxiosError) => {
    const originalRequest = error.config;
    if (!originalRequest) return Promise.reject(error);

    const isLoginRoute = originalRequest.url?.includes('/auth/login');
    const isRefreshRoute = originalRequest.url?.includes('/auth/refresh');

    if (error.response?.status !== 401 || isLoginRoute || isRefreshRoute) {
      return Promise.reject(error);
    }

    if (isRefreshing) {
      return new Promise<string>((resolve, reject) => {
        failedQueue.push({ resolve, reject });
      }).then((token) => {
        if (originalRequest.headers) {
          originalRequest.headers.Authorization = `Bearer ${token}`;
        }
        return client(originalRequest);
      });
    }

    isRefreshing = true;

    try {
      if (!refreshToken) {
        throw new Error('No refresh token available');
      }

      const { data } = await axios.post<LoginResponse>(
        `${API_BASE}/auth/refresh`,
        { refresh_token: refreshToken } satisfies RefreshRequest,
      );

      setTokens(data.token, data.refresh_token);
      processQueue(null, data.token);

      if (originalRequest.headers) {
        originalRequest.headers.Authorization = `Bearer ${data.token}`;
      }
      return client(originalRequest);
    } catch (refreshError) {
      processQueue(refreshError, null);
      clearTokens();
      window.location.href = '/login';
      return Promise.reject(refreshError);
    } finally {
      isRefreshing = false;
    }
  },
);

export async function login(credentials: LoginRequest): Promise<LoginResponse> {
  const { data } = await client.post<LoginResponse>('/auth/login', credentials);
  setTokens(data.token, data.refresh_token);
  return data;
}

export async function logout(): Promise<void> {
  try {
    await client.post('/auth/logout');
  } finally {
    clearTokens();
  }
}

export default client;
