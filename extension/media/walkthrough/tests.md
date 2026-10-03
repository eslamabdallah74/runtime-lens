# Record your test suite

Cover every endpoint your feature tests touch in one run:

```bash
RUNTIME_LENS_RECORD_TESTS=true php artisan test
```

Requests made by tests are tagged `[test]` in **Recent Requests**. Test setup (factories, seeders, migrations) is never recorded.
