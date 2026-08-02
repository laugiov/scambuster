import { useTranslation } from 'react-i18next';

const LANGUAGES = [
  { code: 'en', label: 'EN' },
  { code: 'fr', label: 'FR' },
] as const;

export function LanguageSwitcher({ variant = 'compact' }: { variant?: 'compact' | 'full' }) {
  const { i18n } = useTranslation();
  const currentLang = i18n.language?.slice(0, 2) ?? 'en';

  function switchLang(code: string) {
    void i18n.changeLanguage(code);
  }

  if (variant === 'full') {
    return (
      <div className="flex gap-2">
        {LANGUAGES.map((lang) => (
          <button
            key={lang.code}
            onClick={() => switchLang(lang.code)}
            className={`px-3 py-1.5 text-xs rounded transition-colors cursor-pointer ${
              currentLang === lang.code
                ? 'bg-accent-muted text-on-surface font-medium'
                : 'bg-surface-base text-on-surface-variant hover:bg-surface-high'
            }`}
          >
            {lang.code === 'en' ? 'English' : 'Francais'}
          </button>
        ))}
      </div>
    );
  }

  return (
    <button
      onClick={() => switchLang(currentLang === 'en' ? 'fr' : 'en')}
      className="px-2 py-1 text-xs font-mono text-on-surface-variant hover:text-on-surface bg-surface-high hover:bg-surface-highest rounded transition-colors cursor-pointer"
      aria-label="Switch language"
    >
      {currentLang.toUpperCase()}
    </button>
  );
}
