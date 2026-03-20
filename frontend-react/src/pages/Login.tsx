import { useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuthStore } from '@/store/authStore';

export function Login() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const navigate = useNavigate();
  const { login, isLoading, error, clearError } = useAuthStore();

  async function handleSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();

    const trimmedEmail = email.trim();
    if (!trimmedEmail || !password) return;

    try {
      await login({ email: trimmedEmail, password });
      navigate('/', { replace: true });
    } catch {
      // Error is stored in authStore
    }
  }

  return (
    <div className="min-h-screen bg-surface-base flex items-center justify-center px-4">
      <div className="w-full max-w-sm">
        <div className="text-center mb-8">
          <h1 className="text-2xl font-semibold text-on-surface tracking-wide">ScamBuster</h1>
          <p className="text-xs text-on-surface-dim uppercase tracking-widest mt-1">Threat Intelligence Platform</p>
        </div>

        <form onSubmit={(e) => void handleSubmit(e)} className="bg-surface-low rounded-lg p-6 space-y-5">
          <div>
            <label htmlFor="email" className="block text-xs text-on-surface-dim uppercase tracking-widest mb-2">
              Email
            </label>
            <input
              id="email"
              type="email"
              autoComplete="username"
              required
              value={email}
              onChange={(e) => { clearError(); setEmail(e.target.value); }}
              className="w-full bg-surface-base text-on-surface rounded px-3 py-2.5 text-sm placeholder-on-surface-dim focus:outline-none focus:ring-2 focus:ring-accent"
              placeholder="analyst@scambuster.io"
            />
          </div>

          <div>
            <label htmlFor="password" className="block text-xs text-on-surface-dim uppercase tracking-widest mb-2">
              Password
            </label>
            <input
              id="password"
              type="password"
              autoComplete="current-password"
              required
              value={password}
              onChange={(e) => { clearError(); setPassword(e.target.value); }}
              className="w-full bg-surface-base text-on-surface rounded px-3 py-2.5 text-sm placeholder-on-surface-dim focus:outline-none focus:ring-2 focus:ring-accent"
              placeholder="Enter password"
            />
          </div>

          {error && (
            <p className="text-sm text-error bg-error/10 rounded px-3 py-2" role="alert">
              {error}
            </p>
          )}

          <button
            type="submit"
            disabled={isLoading}
            className="w-full bg-accent-muted hover:bg-accent-hover text-on-surface font-medium rounded py-2.5 text-sm transition-colors disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
          >
            {isLoading ? 'Authenticating...' : 'Sign In'}
          </button>
        </form>

        <p className="text-center text-xs text-on-surface-dim mt-6">
          Authorized personnel only. All access is monitored.
        </p>
      </div>
    </div>
  );
}
