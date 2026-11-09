# Shipcheck

Petit filet avant la mise en production. Un seul script PHP, zéro dépendance, qui refuse de laisser passer les classiques qui cassent un déploiement Laravel (ou PHP tout court) :

`dd()` oublié, `env()` en dehors de `config/`, SQL concaténé, secret vivant, `.env` pas ignoré par git, Debugbar dans `require`.

Zero-dependency PHP CLI that fails the build on the production footguns teams actually ship: a forgotten `dd()`, `env()` outside `config/`, interpolated SQL, a live secret, a `.env` that git will happily commit, or Debugbar left in `composer require`.

It only reads files. It does not execute the project and it does not send anything anywhere.

## Usage

```bash
php shipcheck.php
php shipcheck.php /path/to/app
php shipcheck.php --strict .
php shipcheck.php --format=json .
```

| Code | Meaning |
| --- | --- |
| 0 | No errors. Warnings are printed but do not fail the build. |
| 1 | At least one error, or a warning when `--strict` is set. |
| 2 | Bad arguments, or the path is not a directory. |

```text
ERROR   debug-leftover       app/Http/Controllers/OrderController.php:12
        Debug call left in the code. It can leak data or kill the request in production.
        > dd($order);

ERROR   env-outside-config   app/Services/Billing.php:7
        env() outside config/. Read config() instead, or config:cache will break this call.
        > return env('STRIPE_SECRET');

ERROR   sql-interpolation    app/Queries/UserQuery.php:7
        SQL built with interpolation or concatenation. Use a prepared statement and bindings.
        > return DB::select("select * from users where email = '$email'");

shipcheck: 3 errors, 0 warnings
```

`--format=json` prints a single JSON document (`version`, `errors`, `warnings`, `findings`) and nothing else, so it can be piped to `jq`.

## Rules

| Rule | Level | What it catches |
| --- | --- | --- |
| `debug-leftover` | error | `dd()`, `ddd()`, `ray()`, `var_dump()` |
| `debug-noise` | warning | `dump()`, `print_r()` |
| `env-outside-config` | error | `env()` anywhere except `config/` |
| `sql-interpolation` | error | `DB::select` / `whereRaw` / `mysqli_query` built with `$var` or `.` concatenation |
| `live-secret` | error | AWS access key id, `sk_live_`, `ghp_`, `github_pat_`, Slack token, private key header |
| `debugbar-in-prod` | error | `barryvdh/laravel-debugbar` in `require` instead of `require-dev` |
| `telescope-in-prod` | warning | `laravel/telescope` in `require` |
| `env-not-gitignored` | error | `.gitignore` missing a `.env` rule (`.env`, `.env.*`, `.env*`) |
| `env-tracked` | error | `.env` or `.env.production` already tracked by git |
| `debug-env-file` | error | `APP_DEBUG=true` in `.env.production`, `.env.prod` or `.env.staging` |
| `blade-unescaped` | warning | `{!! !!}` in a Blade file |
| `unguarded-model` | warning | `protected $guarded = []` or `Model::unguard()` |

A local `.env` with `APP_DEBUG=true` is normal and is not an error. A live secret inside that file still is. `.env.example`, `.env.testing`, `*.sample` and `*.dist` are ignored.

These calls are left alone on purpose:

- `env()` inside `config/`
- `getenv()`
- `DB::select('select … where id = ?', [$id])`
- `dd()` inside a comment, a string, `vendor/`, or `tests/`

## Ignore a line

```php
// shipcheck-ignore
dd($order);

dump($order); // shipcheck-ignore
```

Same rule in Blade: a `{{-- shipcheck-ignore --}}` comment, or the marker on the same line.

## What is skipped

Directories named `vendor`, `node_modules`, `storage`, `tests`, `test`, `.git`, plus `bootstrap/cache/`, `public/build/` and lockfiles. Files over 700 KB and binaries are skipped. Markdown is skipped so this README can show examples.

## CI

```yaml
- uses: actions/checkout@v4
- uses: shivammathur/setup-php@v2
  with:
    php-version: "8.3"
- run: php shipcheck.php --strict .
```

PHP 8.1 or newer. No Composer install is required. `composer test` runs the same suite as `php tests/run.php` if you want it.

## Limits

Shipcheck is a line-oriented scanner, not a PHP parser. It will miss SQL built across a heredoc, and it will not understand a query hidden in a variable (`$sql = ...; DB::select($sql)`). Secret checks are high-confidence patterns, not a guarantee that a string is safe. A clean report does not replace a review.

## Licence

MIT. See [LICENSE](LICENSE).
