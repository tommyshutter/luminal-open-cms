<!-- doc-version: 1.1.0 | cut: 2026-08-31T00:32Z | src-commit: b87605e | doc: CONTRIBUTING -->

# Contributing

**Doc version 1.1.0 · cut 2026-08-31T00:32Z**

Contributions are welcome. This project came out of a working fleet rather than a committee, so
some conventions will look opinionated — ask before assuming something is an accident.

## Before you build something large

Open an issue describing the problem first. The most common reason a large pull request is
declined is that it solves a real problem in a way that conflicts with an architectural
invariant the maintainers know about and the contributor could not have.

## The invariants

These are not style preferences. Breaking them causes data loss in production:

1. **Persistent data lives in `admin/data/{ModuleName}/`** — never inside the module directory.
   Updates overwrite module directories. See [`docs/MODULE-API.md`](docs/MODULE-API.md) §4.
2. **A missing module is a missing feature, never a fatal error.** Guard cross-module calls with
   `is_file()` / `function_exists()` and degrade to a comment or a clean 404.
3. **Never commit anything from `admin/data/` or `media/`.** Those are the operator's content and
   secrets. The `.gitignore` covers them; do not add exceptions.
4. **No build step.** If a change requires a compiler or a package manager to produce the files
   that ship, it does not fit this project.

## Pull requests

- One logical change per pull request.
- `php -l` must pass on every changed PHP file.
- Say in the description **what you tested and how**. "Works on my site" is a fine answer; silence
  is not.
- Document behaviour changes in the same PR. A doc in this project carries a version stamp — bump
  it when you change what the doc describes.

## Reporting bugs

Include: what you expected, what happened, your PHP version, your web server, and the smallest
reproduction you can manage. If it involves a module, name the module and its version from
`module.json`.

Security issues go through [`SECURITY.md`](SECURITY.md) instead — please do not file them as
public bugs.

## Licensing of contributions

By submitting a contribution you agree that it is licensed under the Apache License 2.0 — the same
terms as the rest of this project. You keep the copyright in your own work.

**There is no Contributor License Agreement to sign.** Inbound contributions are licensed exactly
as the project is licensed outbound, which is the simplest arrangement for everyone: you send a
patch, it is Apache-2.0, done. No paperwork, no account, no assignment of your rights.
