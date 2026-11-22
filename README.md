# contractlayer/econtract-laravel

Laravel package for [eContract](https://econtract.online) e-signatures: a `SignatureProvider`
interface your code depends on, the eContract implementation, webhook signature verification, a queued
job that downloads the signed PDF and a reconcile command. Laravel 11, 12 and 13, PHP 8.2+.

Guide: [Integrating eContract](https://developers.econtract.online/docs/integration). Runnable Node.js
example and cURL scripts: [econtract-integration-demo](https://github.com/contractlayer/econtract-integration-demo).

```sh
composer require contractlayer/econtract-laravel
php artisan vendor:publish --tag=econtract-config
php artisan migrate
```

```env
ECONTRACT_API_KEY=cl_test_xxx
ECONTRACT_WEBHOOK_SECRET=…
```

Your code depends on `SignatureProvider`; see [`examples/HireController.php`](examples/HireController.php).
The webhook route `POST /webhooks/econtract` is registered for you; run a queue worker for the download
job and schedule `econtract:reconcile` hourly. Listen to `SignatureRequestUpdated` to update your domain.

Start with a test key (`cl_test_…`): contracts are marked TEST, no emails are sent and the signer code is
`000000`.

## Tests

`bin/test-docker.sh` (Docker, no local PHP) or `composer install && vendor/bin/phpunit`.

## License

MIT
