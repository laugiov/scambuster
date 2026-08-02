import { useTranslation } from 'react-i18next';

interface ErrorMessageProps {
  title?: string;
  message: string;
  onRetry?: () => void;
}

export function ErrorMessage({ title, message, onRetry }: ErrorMessageProps) {
  const { t } = useTranslation();

  return (
    <div className="bg-error/10 rounded-lg p-6 text-center" role="alert">
      <p className="text-error font-medium mb-1">{title ?? t('common.error')}</p>
      <p className="text-sm text-on-surface-variant mb-4">{message}</p>
      {onRetry && (
        <button
          onClick={onRetry}
          className="text-sm text-accent hover:text-accent-hover transition-colors cursor-pointer"
        >
          {t('common.retry')}
        </button>
      )}
    </div>
  );
}
