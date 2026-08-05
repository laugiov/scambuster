import '@testing-library/jest-dom/vitest';
import '../i18n';
import * as matchers from 'vitest-axe/matchers';
import { expect } from 'vitest';

expect.extend(matchers);
