import { useTranslation } from 'react-i18next';
import { generateCsv, downloadCsv, type CsvColumn } from '@/lib/csv';

interface ExportCsvButtonProps<T extends Record<string, unknown>> {
  data: T[];
  columns: CsvColumn<T>[];
  filename: string;
}

export function ExportCsvButton<T extends Record<string, unknown>>({
  data,
  columns,
  filename,
}: ExportCsvButtonProps<T>) {
  const { t } = useTranslation();

  function handleExport() {
    const csv = generateCsv(data, columns);
    downloadCsv(csv, filename);
  }

  return (
    <button
      onClick={handleExport}
      disabled={data.length === 0}
      className="flex items-center gap-1.5 px-3 py-2 text-xs font-medium text-on-surface-variant bg-surface-low hover:bg-surface-high rounded-lg transition-colors disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer"
      title={t('csv.export')}
    >
      <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
        <path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
      </svg>
      {t('csv.export')}
    </button>
  );
}
