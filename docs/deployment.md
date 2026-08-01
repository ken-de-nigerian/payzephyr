# Deployment

This chapter covers the mechanics of getting PayZephyr working in a real deployed environment. Pair it with the [Production Checklist](production-checklist.md), which is the "did I remember everything" review — this chapter is more "here's how each piece actually gets deployed."

## Environment variables

Every provider you enable needs its credentials present in your production environment — however your platform manages environment variables (a `.env` file, your host's secrets manager, container environment variables, whatever your deployment pipeline uses). There's no PayZephyr-specific mechanism here; it reads from Laravel's normal `env()`/config system like anything else in your app.

A minimal checklist of what needs to exist per enabled provider is in the [Configuration](configuration.md#provider-credentials) table — cross-reference that against whatever secrets-management step your deployment process has.

## Migrations

PayZephyr's three tables (`payment_transactions`, `subscription_transactions`, `webhook_events`) need to exist before your app can log anything. If your deployment pipeline runs `php artisan migrate` as a standard step (most do), nothing special is needed — the migrations were copied into your app's own `database/migrations` directory during [installation](installation.md), so they run alongside your app's other migrations automatically.

If you're deploying for the first time and skipped running migrations during installation, run them now:

```bash
php artisan migrate --force
```

(`--force` is needed in production, since Laravel normally asks for confirmation before running migrations against a production environment.)

## Queue workers

Covered in depth in [Queues](queues.md) — the deployment-specific part is making sure a worker process is actually running continuously, and restarts automatically if it crashes or if a deploy restarts your app. How you do this depends on your infrastructure:

- **Traditional server (VPS, dedicated box):** [Supervisor](https://laravel.com/docs/queues#supervisor-configuration) is the standard choice — it keeps `php artisan queue:work` running and restarts it automatically.
- **Platform-as-a-service (Forge, Vapor, Cloud, Heroku-style platforms):** most have a built-in "worker" process type — use it rather than trying to run a worker inside your web process.
- **Containers (Docker/Kubernetes):** run the queue worker as its own container/pod, separate from your web server container, so it scales and restarts independently.

Whatever mechanism you use, **restart the worker after every deploy.** A running worker holds your application code in memory from when it started — if you deploy new code (including PayZephyr updates) without restarting workers, they keep running the *old* code until restarted, which can cause confusing "the fix didn't work" situations that are actually just a stale worker.

```bash
php artisan queue:restart
```

This signals workers to finish their current job and then exit gracefully — your process supervisor (Supervisor, your platform's worker management) should then start fresh ones automatically.

## Config caching

If your deployment pipeline runs `php artisan config:cache` (common, and recommended for production performance), do this *after* all your environment variables are actually set — `config:cache` bakes the current environment's values into a cached file, so setting an environment variable afterward without re-caching won't take effect. If you ever change a `PAYMENTS_*` environment variable in production, remember to re-run `config:cache` (or `config:clear` if you're not using config caching) afterward.

## Monitoring

The [health endpoint](configuration.md#health-check) (`/payments/health`, once [authenticated](security.md#health-endpoint) per the production checklist) is the natural thing to point an uptime monitor at — it reports whether PayZephyr can currently reach each of your enabled providers, which is a more useful signal than "is the homepage up" for catching a provider-side outage before your customers hit it during checkout.

Beyond that, keep an eye on:

- **Your `failed_jobs` table** — a webhook that exhausted all its retries lands here. A growing number of failed webhook jobs is worth investigating; it usually means either a bug in your `WebhookReceived` listener, or a provider sending a payload shape PayZephyr didn't expect.
- **The `payments` log channel** — see [Configuration](configuration.md#transaction-logging). Anything PayZephyr logs at `error` level here is worth alerting on.

## Next steps

- [Production Checklist](production-checklist.md) — the full pre-launch review
- [Upgrade Guide](upgrade-guide.md) — deploying a new major version of PayZephyr itself
