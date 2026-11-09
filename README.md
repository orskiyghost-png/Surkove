# Surkove

Static storefront built with React, TypeScript, and Vite. Production files are generated in `dist/` and published to Majordomo over explicit FTPS by the single GitHub Actions workflow in `.github/workflows/ci.yml`.

## Local development and validation

```sh
npm ci
npm run dev
npm run typecheck
npm run build
node .github/scripts/verify-artifact.mjs
```

There are no application runtime dependencies on PHP, Composer, a database, or server-side APIs. The site is a static build served from the configured web root.

## CI and automatic publication

The `CI` workflow runs on pull requests targeting `main` and on pushes to `main`. It installs the locked npm dependencies, audits packages, type-checks the application, builds the production site, and scans `dist/` for blocked configuration and known secret formats. On a push to `main`, FTPS publication runs only after those validation steps pass. Pull-request jobs do not receive FTP credentials.

To enable this process, create these repository settings under **Settings → Secrets and variables → Actions**:

**Secrets**

- `FTP_USERNAME`
- `FTP_PASSWORD`

**Variables**

- `FTP_HOST`
- `FTP_REMOTE_DIR` — the actual website document root as seen after FTP login
- `FTP_PORT` — optional; defaults to `21`

Use the Majordomo FTPS hostname and explicit FTP-over-TLS service. The workflow requires certificate validation, TLS-protected control and data connections, and passive mode. It never falls back to plain FTP, SSH, or SFTP.

After the workflow is present on the repository's default branch, manual checks are available from **Actions → CI → Run workflow** on `main`: `connection-test` checks TLS, authentication and the configured directory; `smoke-test` creates a unique temporary file, reads it back and removes it; `dry-run` previews the transfer; `deploy` manually republishes the already validated build. The automatic `push` to `main` path performs the deployment after validation.

## Remote-file safety

Only the contents of `dist/` are transferred. No remote delete option is used. Existing `.env` files, PHP/configuration files, user uploads, documents, databases, sessions, logs, caches, backups, and other paths outside the build output are not synchronized or removed. This assumes the configured FTP directory is the site's document root and the application does not intentionally generate files with the same names as user data. Old content-hashed assets may remain on the host; removing them is intentionally out of scope until ownership is proven.

The upload updates files in place and is not an atomic release switch. If a transfer fails mid-deployment, the web root can temporarily contain a mixture of old and new files. A retry republishes the validated build; the workflow does not perform database migrations.

## Rollback

Use Git rather than rewriting history: create a **Revert** Pull Request for the change that introduced the unwanted site version, merge it into `main`, and let CI rebuild and publish the reverted source. The deploy workflow does not force-push, reset, or rewrite Git history. To restore a specific earlier state, revert the relevant commit(s) and merge the revert PR.

## Main branch protection

The `main` branch should require the successful GitHub Actions check **Validate application** before merge and disallow force pushes and branch deletion. This setting is managed in GitHub repository rules/branch protection, not in the application code. The check must be selected after it has appeared on a PR.

## Majordomo prerequisites and verification

The repository cannot establish account-specific FTPS host, port, remote directory, certificate, or passive-data connectivity. Confirm those values in Majordomo before enabling a merge that should publish. The local/CI build passing does not prove that the hosting account serves the configured directory or that FTPS works. Do not consider production deployment verified until the connection test, write/read/delete smoke test, dry-run, and a separately authorized real publication have succeeded.

Product imagery and the licensed Monroe font are intentionally not included yet.
