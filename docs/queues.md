# Queues

## Why this chapter exists

If you skip this chapter and deploy PayZephyr without a running queue worker, webhooks will silently stop working. Not error loudly, just quietly never get processed. This chapter explains why, so it doesn't happen to you.

## What gets queued, and why

When a webhook arrives at PayZephyr's endpoint (see [Webhooks](webhooks.md)), the actual work of processing it (checking for duplicates, updating your transaction record, dispatching the `WebhookReceived` event, running your listeners) doesn't happen immediately, in that HTTP request. It's handed off to a queued job, `KenDeNigerian\PayZephyr\Jobs\ProcessWebhook`, and the HTTP response to the provider returns right away.

This is a deliberate design choice, not an accident, for two reasons:

**Speed matters to the provider.** Most providers expect your webhook endpoint to respond quickly (often within a few seconds); if it doesn't, they may consider the delivery failed and retry it, or in some cases stop trying altogether. If PayZephyr did all of its processing (which can include your own listener code, such as sending an email or updating other systems) synchronously inside that request, a slow listener could make the whole webhook delivery look like it failed to the provider, even though PayZephyr itself handled it fine.

**Some providers' verification itself requires an extra network call.** PayPal and, in one specific configuration, Mollie verify webhook signatures by calling back to the provider's own API rather than checking a local HMAC; that's inherently slower than a local signature check, and doing it inside the initial request would make the endpoint even more likely to time out. PayZephyr defers this kind of verification to the queued job specifically to keep the initial HTTP response fast regardless.

## The consequence: a worker has to be running

A queued job doesn't process itself: something has to actually pick it up off the queue and run it. In local development, Laravel's default `sync` queue driver runs jobs immediately, in the same request, which is why webhooks can appear to "just work" while you're building without you ever having thought about queues. **That's not what happens in production**, where you should be using a real queue driver (database, Redis, SQS, or similar), and a real queue driver needs a worker process actually running to consume from it:

```bash
php artisan queue:work
```

If nothing is running this (or your process manager equivalent: Supervisor, systemd, whatever you use to keep it alive and restart it if it crashes), jobs pile up in the queue table/store and never execute. Webhooks will look like they're being accepted (PayZephyr responds `200 OK` to the provider, the request itself succeeded) but nothing downstream (your transaction updates, your `WebhookReceived` listeners) ever actually runs. This is the single most common "my webhooks don't work in production" cause, and it produces no error message anywhere, because nothing is actually failing: the job is just sitting there, unprocessed.

See the [Production Checklist](production-checklist.md) and [Deployment](deployment.md) for how to keep a worker running reliably in production, and [Troubleshooting](troubleshooting.md#webhooks-not-processing) if you're debugging this exact symptom right now.

## Retry behavior

If processing a webhook fails partway through (a transient database error, for example), PayZephyr's job retries automatically:

```env
PAYMENTS_WEBHOOK_MAX_RETRIES=3
PAYMENTS_WEBHOOK_RETRY_BACKOFF=60
```

Three attempts by default, 60 seconds apart, using Laravel's standard job-retry mechanism. If all retries are exhausted, the job is marked failed and lands in your `failed_jobs` table like any other failed queued job; worth monitoring, since a webhook stuck there means that specific event never got processed.

## Which queue connection does PayZephyr use?

Whatever your application's default queue connection is (`QUEUE_CONNECTION` in `.env`): PayZephyr doesn't configure its own separate queue connection. If you want webhook processing on a dedicated queue name (useful if you want to prioritize it separately from your app's other background jobs), you can route it the way you'd route any job: see [Laravel's queue documentation](https://laravel.com/docs/queues#job-batching) on dispatching to specific connections/queues, or the newer `Queue::route()` mechanism if you're on Laravel 13.

## Next steps

- [Webhooks](webhooks.md): the full picture of what happens once a job is actually running
- [Production Checklist](production-checklist.md): making sure a worker survives deploys and crashes
- [Troubleshooting](troubleshooting.md#webhooks-not-processing): diagnosing "webhooks aren't working" step by step
