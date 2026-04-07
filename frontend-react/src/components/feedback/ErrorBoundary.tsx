import { Component } from 'react';
import type { ErrorInfo, ReactNode } from 'react';
import i18next from 'i18next';

interface Props {
  children: ReactNode;
}

interface State {
  hasError: boolean;
  error: Error | null;
}

export class ErrorBoundary extends Component<Props, State> {
  constructor(props: Props) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    console.error('[ErrorBoundary] Uncaught error:', {
      message: error.message,
      stack: error.stack,
      componentStack: info.componentStack,
    });
  }

  private handleRetry = (): void => {
    this.setState({ hasError: false, error: null });
  };

  render(): ReactNode {
    if (this.state.hasError) {
      return (
        <div className="min-h-screen bg-surface-base flex items-center justify-center px-4">
          <div className="max-w-md text-center" role="alert">
            <h1 className="text-xl font-semibold text-on-surface mb-2">
              {i18next.t('common.somethingWentWrong')}
            </h1>
            <p className="text-sm text-on-surface-variant mb-4">
              {i18next.t('common.unexpectedError')}
            </p>
            {import.meta.env.DEV && this.state.error && (
              <pre className="text-xs text-error bg-error/10 rounded-lg p-3 mb-4 text-left overflow-auto max-h-40">
                {this.state.error.message}
              </pre>
            )}
            <button
              onClick={this.handleRetry}
              className="px-4 py-2 bg-accent-muted hover:bg-accent-hover text-on-surface text-sm font-medium rounded-lg transition-colors cursor-pointer"
            >
              {i18next.t('common.tryAgain')}
            </button>
          </div>
        </div>
      );
    }

    return this.props.children;
  }
}
