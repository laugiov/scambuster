/**
 * CSV generation utility with UTF-8 BOM and RFC 4180 escaping.
 */

export interface CsvColumn<T> {
  key: keyof T & string;
  header: string;
}

function escapeCsvValue(value: unknown): string {
  const str = value == null ? '' : String(value);
  if (str.includes(',') || str.includes('"') || str.includes('\n') || str.includes('\r')) {
    return `"${str.replace(/"/g, '""')}"`;
  }
  return str;
}

export function generateCsv<T extends Record<string, unknown>>(
  rows: T[],
  columns: CsvColumn<T>[],
): string {
  const BOM = '\uFEFF';
  const header = columns.map((c) => escapeCsvValue(c.header)).join(',');
  const body = rows
    .map((row) => columns.map((c) => escapeCsvValue(row[c.key])).join(','))
    .join('\r\n');

  return `${BOM}${header}\r\n${body}`;
}

export function downloadCsv(csvContent: string, filename: string): void {
  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
}
