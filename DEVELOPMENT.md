# Development Guide

## Requirements

- Docker Desktop or a compatible Docker Engine with `docker compose`
- Composer

## Setup

1. Copy the environment file:

```bash
cp .env.example .env
```

2. Start WordPress and MySQL:

```bash
docker compose up -d --wait
```

3. Open the site:

- Site: `http://localhost:${WORDPRESS_PORT}`
- Admin: `http://localhost:${WORDPRESS_PORT}/wp-admin`

Default admin credentials come from `.env`:

- Username: `WORDPRESS_ADMIN_USER`
- Password: `WORDPRESS_ADMIN_PASSWORD`

## Lifecycle

- Start services: `composer dev:up`
- Stop services: `composer dev:down`
- Stop and remove data: `composer dev:reset`

## WP-CLI

Run WP-CLI through the dedicated service:

```bash
docker compose run --rm wpcli core version
```

The repo is mounted in the containers at `/workspace/surge`.
The install scripts decide whether WordPress should use:

- a symlinked working tree for fast local iteration
- an installed ZIP for release validation

## Common Workflows

Build a preview ZIP:

```bash
composer plugin:build:preview
```

Install the latest built ZIP:

```bash
composer plugin:install:zip
```

Install the working tree directly:

```bash
composer plugin:install:worktree
```

Run the smoke test:

```bash
composer test:smoke
```
