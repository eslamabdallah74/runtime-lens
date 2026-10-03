# Install the Laravel package

In your Laravel project:

```bash
composer require --dev runtime-lens/laravel
php artisan runtime-lens:install
```

`runtime-lens:install` checks your environment and storage folder, and can turn recording on when your local `APP_ENV` isn't `local`.

It's a dev-only dependency and never runs in production.
