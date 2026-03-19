# Testing Guide

## Requirements

- Docker with `docker compose`
- Composer
- Docker daemon running locally

## Local Commands

Copy the environment file once:

```bash
cp .env.example .env
```

Run the shell-level packaging tests:

```bash
composer test:shell
```

Start WordPress and MySQL:

```bash
composer dev:up
```

Build an installable preview ZIP:

```bash
composer plugin:build:preview
```

Install the latest built ZIP into WordPress:

```bash
composer plugin:install:zip
```

Run the caching smoke test:

```bash
composer test:smoke
```

Stop the local environment:

```bash
composer dev:down
```

Remove local WordPress and database data:

```bash
composer dev:reset
```

## Fast Iteration Mode

To test the mounted working tree instead of a ZIP:

```bash
composer plugin:install:worktree
composer test:smoke
```

This mode is useful while iterating on PHP-only changes. The ZIP install path remains the release-validation path.

## Admin UI Verification

When iterating on the React admin UI, use the mounted working tree so the Docker WordPress container reflects local JS and CSS rebuilds:

```bash
pnpm build
composer plugin:install:worktree
```

Then open `http://localhost:8888/wp-admin` and sign in with the credentials from `.env` or `.env.example`:

- Username: `admin`
- Password: `password`

Recommended manual checks for `Settings > Surge`:

- The page loads without console errors and only on the Surge admin screen
- The WordPress page heading remains the top-level heading, and the app does not introduce a second `<main>`
- Keyboard navigation reaches the skip link, primary actions, status notices, and danger-zone controls in a sensible order
- `Flush cache`, `Flush and delete files`, and `Reinstall drop-in` open designed confirmation UI with explicit consequence copy and action labels
- Successful actions show notices and refresh cache metrics deterministically
- The layout remains usable with the admin sidebar expanded, collapsed, and at narrower content widths

## Smoke Coverage

The smoke test verifies:

- WordPress is bootstrapped and Surge is active
- `wp-content/advanced-cache.php` exists and belongs to Surge
- a first anonymous request returns `X-Cache: miss`
- a second anonymous request returns `X-Cache: hit`
- cache files are created on disk
- `wp surge flush --delete` removes cached files

## CI And Releases

- `.github/workflows/ci.yml` runs shell tests, builds a preview ZIP, installs it, and smoke-tests it on pull requests and `trunk` pushes.
- `.github/workflows/preview-release.yml` publishes a prerelease ZIP for each `trunk` push and manual dispatch.
- `.github/workflows/release.yml` publishes a stable GitHub Release from tags like `v1.1.1` after version validation against `surge.php` and `readme.txt`.
