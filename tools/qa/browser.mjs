// Shared Chromium launcher for the QA tools. The sandbox ships its own Chromium; Playwright's default
// download is not available offline, so we point at the pre-installed binary (override with CHROMIUM=…).
import { chromium } from 'playwright';
import { existsSync } from 'node:fs';

const CANDIDATES = [process.env.CHROMIUM, '/opt/pw-browsers/chromium', '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'].filter(Boolean);

export async function launch(opts = {}) {
  const executablePath = CANDIDATES.find((p) => existsSync(p));
  return chromium.launch({ executablePath, args: ['--no-sandbox', '--disable-dev-shm-usage'], ...opts });
}
