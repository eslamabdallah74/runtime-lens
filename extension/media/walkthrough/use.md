# Use your app

Open a page, call an endpoint from Postman, or click through your frontend as usual.

Within about a second, the code that ran gets labels:

- `⚠ N+1 · 10× in one request`: the same query repeated from one line. Eager load with `->with()`.
- `🐢 slow query · 310ms` and `🌐 slow HTTP · 1200ms`
- `✖ RuntimeException ×2`: reported exceptions
- `3 queries · 4ms`: what this line costs per request

Hover a label for the SQL, its bindings and the requests that ran it. The same findings appear in the **Problems** panel.
