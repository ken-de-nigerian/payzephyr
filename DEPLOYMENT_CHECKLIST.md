# 🚀 Deployment Checklist

Quick reference for deploying the Payments Router package.

## Pre-Deployment

- [ ] Update version in `composer.json`
- [ ] Update `CHANGELOG.md` with changes
- [ ] Run all tests: `composer test`
- [ ] Check code style: `composer format`
- [ ] Review security: No keys in code
- [ ] Update documentation if needed

## GitHub Setup

- [ ] Create repository: `ken-de-nigerian/payzephyr`
- [ ] Push code to main branch
- [ ] Add repository description
- [ ] Add topics: `laravel`, `payment`, `paystack`, `stripe`, `flutterwave`
- [ ] Create release v1.0.0
- [ ] Add release notes from CHANGELOG

### Commands
```bash
git init
git add .
git commit -m "Initial release v1.0.0"
git branch -M main
git remote add origin https://github.com/ken-de-nigerian/payzephyr.git
git push -u origin main
git tag v1.0.0
git push --tags
```

## Packagist Setup

- [ ] Sign in to packagist.org with GitHub
- [ ] Click "Submit" button
- [ ] Enter repository URL
- [ ] Click "Check" then "Submit"
- [ ] Copy webhook URL from Packagist
- [ ] Add webhook to GitHub repo (Settings → Webhooks)

## Verification

- [ ] Package appears on Packagist
- [ ] Try installing: `composer require ken-de-nigerian/payzephyr`
- [ ] Check badge displays on README
- [ ] GitHub Actions tests run successfully
- [ ] Webhook triggers on push

## Post-Launch

- [ ] Announce on Laravel News
- [ ] Post on Reddit r/laravel
- [ ] Share on Twitter/X
- [ ] Add to awesome-laravel list
- [ ] Monitor GitHub issues

## Environment Variables Template

Provide this to users:

```env
# Payments Router Configuration

# Default Settings
PAYMENTS_DEFAULT_PROVIDER=paystack
PAYMENTS_FALLBACK_PROVIDER=stripe

# Paystack
PAYSTACK_SECRET_KEY=
PAYSTACK_PUBLIC_KEY=
PAYSTACK_ENABLED=true

# Flutterwave
FLUTTERWAVE_SECRET_KEY=
FLUTTERWAVE_PUBLIC_KEY=
FLUTTERWAVE_ENCRYPTION_KEY=
FLUTTERWAVE_ENABLED=false

# Monnify
MONNIFY_API_KEY=
MONNIFY_SECRET_KEY=
MONNIFY_CONTRACT_CODE=
MONNIFY_ENABLED=false

# Stripe
STRIPE_SECRET_KEY=
STRIPE_PUBLIC_KEY=
STRIPE_WEBHOOK_SECRET=
STRIPE_ENABLED=false

# PayPal
PAYPAL_CLIENT_ID=
PAYPAL_CLIENT_SECRET=
PAYPAL_MODE=sandbox
PAYPAL_ENABLED=false

# Optional Settings
PAYMENTS_WEBHOOK_VERIFY_SIGNATURE=true
PAYMENTS_LOGGING_ENABLED=true
PAYMENTS_HEALTH_CHECK_ENABLED=true
```

## Support Channels

- [ ] Set up GitHub Discussions
- [ ] Create support email alias
- [ ] Monitor issues daily (first week)
- [ ] Respond to questions promptly

## Monitoring

First week metrics to track:
- [ ] Installation count
- [ ] Stars on GitHub
- [ ] Issues opened
- [ ] Pull requests
- [ ] Community feedback

## Success Criteria

✅ 100+ downloads in first week  
✅ 10+ stars on GitHub  
✅ No critical bugs reported  
✅ Positive community feedback  
✅ Clean CI pipeline  

---

## Quick Commands Reference

### Testing
```bash
composer test              # Run tests
composer test-coverage     # With coverage
composer format           # Fix code style
composer analyse          # Static analysis
```

### Git
```bash
git tag v1.x.x           # Create tag
git push --tags          # Push tags
git push origin main     # Push code
```

### Installation (for users)
```bash
composer require ken-de-nigerian/payzephyr
php artisan vendor:publish --tag=payments-config
php artisan migrate
```

---

**Ready to launch!** 🚀

Follow this checklist step-by-step for a smooth deployment.
