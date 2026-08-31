<!-- doc-version: 1.0.0 | cut: 2026-08-31T00:25Z | src-commit: b87605e | doc: SECURITY -->

# Security Policy

**Doc version 1.0.0 · cut 2026-08-31T00:25Z**

## Reporting a vulnerability

Please report security issues **privately** using GitHub's private vulnerability reporting
("Report a vulnerability" on the Security tab of this repository). That channel is preferred
because it keeps the report confidential until a fix exists.

**Please do not open a public issue for a security problem**, and please do not post a working
exploit publicly before a fix is available.

When reporting, the most useful things to include are: what an attacker can achieve, the smallest
set of steps that demonstrates it, and the version or commit you tested.

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

## Operator responsibilities

A CMS cannot secure a server on its own. Two things matter most:

1. **`admin/data/` and `media/` must not be world-writable.** They should be owned by the web
   server user, with the owner bit sufficient. `777` is never the right answer.
2. **Keep backups off the web root.** A backup archive inside the served directory is a download
   link for anyone who guesses the filename.
