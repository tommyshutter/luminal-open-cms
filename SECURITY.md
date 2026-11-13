<!-- doc-version: 1.1.0 | cut: 2026-10-03T13:25Z | src-commit: 0f281f5 | doc: SECURITY -->

# Security Policy

**Doc version 1.1.0 · cut 2026-10-03T13:25Z**

## Reporting a vulnerability

Please report security issues **privately**, by either:

- GitHub's private vulnerability reporting ("Report a vulnerability" on the Security tab of this
  repository), or
- email to **security@brightborn.ai**.

Both keep the report confidential until a fix exists. The machine-readable contact is at
<https://brightborn.ai/.well-known/security.txt>.

**Please do not open a public issue for a security problem**, and please do not post a working
exploit publicly before a fix is available.

When reporting, the most useful things to include are: what an attacker can achieve, the smallest
set of steps that demonstrates it, and the version or commit you tested.

## Security bulletins

To hear about security updates as soon as they are released, sign up at
**<https://brightborn.ai/security>**: one email field, a confirmation link, and you can leave at any
time. On GitHub you can also choose **Watch → Custom → Security alerts** on this repository.

## What to expect

This is a community-maintained open source project. Reports are read and taken seriously, but
there is **no guaranteed response time**. You will get an acknowledgement and, where a fix is
made, credit in the release notes if you want it.

## Scope

In scope: anything in this repository that lets someone read data they should not, write data they
should not, execute code, or bypass authentication.

Out of scope: issues that require the operator to have already misconfigured the server in ways
this project's documentation warns against — most commonly, serving `admin/data/` to the web
because `AllowOverride All` was not set and the shipped `.htaccess` files were therefore ignored.

## Advisories

- **November 2026: numerous security fixes and stabilization** (MyStore 1.4.0 and related files).
  Everyone running a previous release should update. If your site takes payments, add your PayPal
  keys under Admin › Payment Providers and make one sandbox test purchase afterwards.

## Operator responsibilities

A CMS cannot secure a server on its own. Two things matter most:

1. **`admin/data/` and `media/` must not be world-writable.** They should be owned by the web
   server user, with the owner bit sufficient. `777` is never the right answer.
2. **Keep backups off the web root.** A backup archive inside the served directory is a download
   link for anyone who guesses the filename.
3. **Leave a new install sealed until you are ready to claim it.** A site with no admin account
   accepts only an unseal key (see "First sign-in" in the README). Do not leave
   `admin/data/UNSEAL.txt` lying around with a short or guessable passphrase, and do not share a
   minted key over a channel you would not trust with a password — until it is used, it *is* one.
4. **Publish a security contact for your own site.** A `/.well-known/security.txt` file
   (RFC 9116) with a `Contact:` line and an `Expires:` date tells researchers where to write.
