import { describe, it, expect, vi } from 'vitest';
import { generateCsv, downloadCsv } from '../csv';
import type { CsvColumn } from '../csv';

interface TestRow {
  name: string;
  value: number;
  notes: string;
}

const columns: CsvColumn<TestRow>[] = [
  { key: 'name', header: 'Name' },
  { key: 'value', header: 'Value' },
  { key: 'notes', header: 'Notes' },
];

describe('generateCsv', () => {
  it('generates CSV with BOM and header row', () => {
    const rows: TestRow[] = [];
    const result = generateCsv(rows, columns);
    expect(result).toBe('\uFEFFName,Value,Notes\r\n');
  });

  it('generates rows separated by CRLF', () => {
    const rows: TestRow[] = [
      { name: 'Alice', value: 10, notes: 'ok' },
      { name: 'Bob', value: 20, notes: 'good' },
    ];
    const result = generateCsv(rows, columns);
    const lines = result.split('\r\n');
    expect(lines[0]).toBe('\uFEFFName,Value,Notes');
    expect(lines[1]).toBe('Alice,10,ok');
    expect(lines[2]).toBe('Bob,20,good');
  });

  it('escapes values containing commas', () => {
    const rows: TestRow[] = [{ name: 'Doe, Jane', value: 5, notes: 'fine' }];
    const result = generateCsv(rows, columns);
    expect(result).toContain('"Doe, Jane"');
  });

  it('escapes values containing double quotes', () => {
    const rows: TestRow[] = [{ name: 'He said "hi"', value: 1, notes: '' }];
    const result = generateCsv(rows, columns);
    expect(result).toContain('"He said ""hi"""');
  });

  it('escapes values containing newlines', () => {
    const rows: TestRow[] = [{ name: 'line1\nline2', value: 0, notes: '' }];
    const result = generateCsv(rows, columns);
    expect(result).toContain('"line1\nline2"');
  });

  it('escapes values containing carriage returns', () => {
    const rows: TestRow[] = [{ name: 'a\rb', value: 0, notes: '' }];
    const result = generateCsv(rows, columns);
    expect(result).toContain('"a\rb"');
  });

  it('handles null/undefined values as empty string', () => {
    const cols: CsvColumn<Record<string, unknown>>[] = [
      { key: 'a', header: 'A' },
      { key: 'b', header: 'B' },
    ];
    const rows = [{ a: null, b: undefined }];
    const result = generateCsv(rows, cols);
    expect(result).toContain(',');
    // Both null and undefined become empty strings
    const dataLine = result.split('\r\n')[1];
    expect(dataLine).toBe(',');
  });

  it('escapes header values if needed', () => {
    const cols: CsvColumn<Record<string, unknown>>[] = [
      { key: 'a', header: 'Name, First' },
    ];
    const result = generateCsv([], cols);
    expect(result).toContain('"Name, First"');
  });
});

describe('downloadCsv', () => {
  it('creates a blob link, clicks it, and revokes URL', () => {
    const mockClick = vi.fn();
    const mockCreateElement = vi.spyOn(document, 'createElement').mockReturnValue({
      href: '',
      download: '',
      click: mockClick,
    } as unknown as HTMLAnchorElement);
    const mockCreateObjectURL = vi.fn().mockReturnValue('blob:test');
    const mockRevokeObjectURL = vi.fn();

    globalThis.URL.createObjectURL = mockCreateObjectURL;
    globalThis.URL.revokeObjectURL = mockRevokeObjectURL;

    downloadCsv('test,csv,data', 'export.csv');

    expect(mockCreateElement).toHaveBeenCalledWith('a');
    expect(mockCreateObjectURL).toHaveBeenCalled();
    expect(mockClick).toHaveBeenCalled();
    expect(mockRevokeObjectURL).toHaveBeenCalledWith('blob:test');

    mockCreateElement.mockRestore();
  });
});
