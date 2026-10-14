// Builds throw-away copies of the project in the three folder layouts of the deploy guide and serves them with REAL Apache + mod_php
// (what cPanel / LiteSpeed shared hosting runs). Used by apache-check.mjs (deployment checks) and perf.mjs (compression / caching numbers).
//   A  everything in the document root (root .htaccess routes into /public)      http://127.0.0.1:8086/
//   B  private code outside the web root, only /public contents in the docroot   http://127.0.0.1:8085/
//   C  layout A inside a sub-folder                                             http://127.0.0.1:8087/booster/
// Local dev only (uses the dev database). startApache() returns null when apache2 / mod_php are not installed.
import { execFileSync, spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, rmSync, readFileSync, writeFileSync } from 'node:fs';
import { ROOT, sleep } from './site.mjs';

export const W = '/tmp/qa/apache';
export const PORT_A = 8086, PORT_B = 8085, PORT_C = 8087;
const APACHE = ['/usr/sbin/apache2'].find(existsSync);
const MOD_PHP = ['/usr/lib/apache2/modules/libphp8.3.so', '/usr/lib/apache2/modules/libphp8.2.so', '/usr/lib/apache2/modules/libphp8.1.so'].find(existsSync);
export const sh = (cmd) => execFileSync('bash', ['-c', cmd], { stdio: 'pipe', encoding: 'utf8' });

export async function startApache() {
  if (!APACHE || !MOD_PHP) return null;
  rmSync(W, { recursive: true, force: true }); mkdirSync(`${W}/run`, { recursive: true });
  const EXCL = "--exclude node_modules --exclude .git --exclude 'storage/logs/*' --exclude 'storage/cache/*' --exclude storage/installed.lock --exclude '.env'";
  sh(`rsync -a ${EXCL} ${ROOT}/ ${W}/siteA/`);
  sh(`mkdir -p ${W}/siteB/smb ${W}/siteB/public_html && rsync -a ${EXCL} --exclude public ${ROOT}/ ${W}/siteB/smb/ && rsync -a ${ROOT}/public/ ${W}/siteB/public_html/`);
  sh(`mkdir -p ${W}/siteC/booster && rsync -a ${EXCL} ${ROOT}/ ${W}/siteC/booster/ && echo '<h1>parent site</h1>' > ${W}/siteC/index.html`);
  const envBase = readFileSync(`${ROOT}/.env`, 'utf8');
  const envFor = (port, path = '') => envBase.replace(/^APP_URL=.*$/m, `APP_URL=http://127.0.0.1:${port}${path}`).replace(/^APP_DEBUG=.*$/m, 'APP_DEBUG=false');
  writeFileSync(`${W}/siteA/.env`, envFor(PORT_A)); writeFileSync(`${W}/siteB/smb/.env`, envFor(PORT_B)); writeFileSync(`${W}/siteC/booster/.env`, envFor(PORT_C, '/booster'));
  for (const d of [`${W}/siteA`, `${W}/siteB/smb`, `${W}/siteC/booster`]) sh(`cp ${ROOT}/storage/installed.lock ${d}/storage/`);
  // layout B: the documented one-line edit in public_html/index.php
  const idx = readFileSync(`${W}/siteB/public_html/index.php`, 'utf8');
  const marker = "// define('SMB_ROOT', '/home/USER/smb');";
  if (!idx.includes(marker)) throw new Error('public/index.php no longer carries the documented layout-B line');
  writeFileSync(`${W}/siteB/public_html/index.php`, idx.replace(marker, `define('SMB_ROOT', '${W}/siteB/smb');`));
  // the web-server user must be able to write logs / cache / uploads — exactly what the deploy guide asks for
  for (const d of [`${W}/siteA/storage`, `${W}/siteA/public/uploads`, `${W}/siteB/smb/storage`, `${W}/siteB/public_html/uploads`, `${W}/siteC/booster/storage`, `${W}/siteC/booster/public/uploads`]) sh(`mkdir -p ${d}/logs ${d}/cache 2>/dev/null; chown -R www-data:www-data ${d}; chmod -R u+rwX,go+rX ${d}`);

  const modules = ['mpm_prefork', 'authz_core', 'authn_core', 'access_compat', 'alias', 'autoindex', 'deflate', 'dir', 'env', 'expires', 'filter', 'headers', 'mime', 'negotiation', 'rewrite', 'setenvif'];
  const vhost = (port, root, extra = '') => `<VirtualHost 127.0.0.1:${port}>\n    DocumentRoot ${root}\n    <Directory ${root}>\n        AllowOverride All\n        Require all granted\n    </Directory>\n${extra}</VirtualHost>\n`;
  writeFileSync(`${W}/apache2.conf`, `
ServerRoot "/etc/apache2"
PidFile ${W}/run/apache.pid
DefaultRuntimeDir ${W}/run
Mutex file:${W}/run default
Listen 127.0.0.1:${PORT_A}
Listen 127.0.0.1:${PORT_B}
Listen 127.0.0.1:${PORT_C}
User www-data
Group www-data
ServerName 127.0.0.1
ServerTokens Prod
ServerSignature Off
ErrorLog ${W}/error.log
LogLevel warn
${modules.map((m) => `LoadModule ${m}_module /usr/lib/apache2/modules/mod_${m}.so`).join('\n')}
LoadModule php_module ${MOD_PHP}
TypesConfig /etc/mime.types
AddType application/x-httpd-php .php
DirectoryIndex index.php index.html
<Directory />
    AllowOverride None
    Require all denied
</Directory>
${vhost(PORT_A, `${W}/siteA`)}${vhost(PORT_C, `${W}/siteC`)}${vhost(PORT_B, `${W}/siteB/public_html`, `    <Directory ${W}/siteB/smb>\n        Require all denied\n    </Directory>\n`)}`);
  sh(`chmod -R go+rX ${W}`);

  const test = spawnSync(APACHE, ['-f', `${W}/apache2.conf`, '-t'], { encoding: 'utf8' });
  if (test.status !== 0) throw new Error('Apache config error: ' + test.stderr);
  spawnSync(APACHE, ['-f', `${W}/apache2.conf`, '-k', 'start'], { encoding: 'utf8' });
  for (let i = 0; i < 40; i++) { try { await fetch(`http://127.0.0.1:${PORT_A}/health`); break; } catch { await sleep(250); } }
  const stop = () => spawnSync(APACHE, ['-f', `${W}/apache2.conf`, '-k', 'stop']);
  process.on('exit', stop);
  return { W, ports: { A: PORT_A, B: PORT_B, C: PORT_C }, stop, errorLog: () => (existsSync(`${W}/error.log`) ? readFileSync(`${W}/error.log`, 'utf8') : '') };
}
