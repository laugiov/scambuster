interface ErrorMessageProps {
  title?: string;
  message: string;
  onRetry?: () => void;
}

export function ErrorMessage({ title = 'Error', message, onRetry }: ErrorMessageProps) {
  return (
    <div className="bg-error/10 rounded-lg p-6 text-center">
      <p className="text-error font-medium mb-1">{title}</p>
      <p className="text-sm text-on-surface-variant mb-4">{message}</p>
      {onRetry && (
        <button
          onClick={onRetry}
          className="text-sm text-accent hover:text-accent-hover transition-colors cursor-pointer"
        >
          Retry
        </button>
      )}
    </div>
  );
}
