# Contributing

Thanks for your interest in shortly. This is a small project so the rules
are short.

## Getting set up

```bash
cp config.example.php config.php
# edit at least public_url and ip_salt
php -S localhost:8000
```

The schema is created on the first request. SQLite is the default; switch
to MySQL by uncommenting the second `db` block in `config.php`.

## Sending a PR

- One topic per PR. If your change touches both abuse handling and the
  bio editor, it's two PRs.
- Match the surrounding code style. The codebase is plain PHP with PSR-12
  vibes — no enforced linter, but try not to introduce new patterns
  unless you're refactoring intentionally.
- No new dependencies on Composer or npm without discussion. Part of the
  point of this project is to ship as files you can FTP onto cheap
  hosting.
- Keep commits focused. Small, reviewable diffs > large "WIP" branches.
- If you add a config key, document it in `config.example.php` and the
  README's config table.

## What to work on

Issues marked `good first issue` and `help wanted` are open for grabs. If
you want to do something larger, open an issue first so we can agree on
the shape before you sink time into it.

## Security

Please don't open public issues for security problems. Email the
maintainer instead — see the contact in `config.php`/site footer of any
running deployment, or use GitHub's private vulnerability reporting on
the repo.

## License

By submitting a PR you agree your contribution is licensed under the
project's [MIT license](LICENSE).
