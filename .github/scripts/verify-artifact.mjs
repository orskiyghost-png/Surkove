import { readdirSync, readFileSync, lstatSync } from 'node:fs';
import { resolve, relative, sep } from 'node:path';

const root = resolve('dist');
const index = resolve(root, 'index.html');
if (!lstatSync(root, { throwIfNoEntry: false })?.isDirectory() || !lstatSync(index, { throwIfNoEntry: false })?.isFile()) {
  throw new Error('Production artifact must contain dist/index.html');
}

const blockedName = /^(?:\.env(?:\..*)?|\.npmrc|.*\.(?:pem|key|p12|sqlite|sqlite3|db|php\d*|phtml|phar|cgi|pl|py|sh|htaccess|htpasswd))$/i;
const secretPatterns = [
  /-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/,
  /\b(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,})\b/,
  /\bAKIA[0-9A-Z]{16}\b/,
  /\b(?:FTP_PASSWORD|FTP_USERNAME|AWS_SECRET_ACCESS_KEY|GITHUB_TOKEN)\s*[:=]\s*[^\s"']{8,}/i,
];
const files = [];
function walk(directory) {
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const path = resolve(directory, entry.name);
    const rel = relative(root, path);
    if (entry.isSymbolicLink()) throw new Error(`Symlink not permitted in production artifact: ${rel}`);
    if (blockedName.test(entry.name)) throw new Error(`Private/configuration file not permitted in production artifact: ${rel}`);
    if (entry.isDirectory()) walk(path);
    else if (entry.isFile()) files.push(path);
    else throw new Error(`Unsupported file type in production artifact: ${rel}`);
  }
}
walk(root);

for (const file of files) {
  const content = readFileSync(file, 'utf8');
  if (secretPatterns.some((pattern) => pattern.test(content))) {
    throw new Error(`Potential secret detected in production artifact: ${relative(root, file)}`);
  }
}
if (!files.some((file) => relative(root, file).startsWith(`assets${sep}`) && file.endsWith('.css'))
    || !files.some((file) => relative(root, file).startsWith(`assets${sep}`) && /\.m?js$/i.test(file))) {
  throw new Error('Production artifact must contain CSS and JavaScript bundles under dist/assets');
}
console.log(`Validated ${files.length} production files; no blocked configuration or secret patterns found.`);
