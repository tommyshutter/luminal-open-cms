<!-- doc-version: 1.1.0 | cut: 2026-08-31T00:29Z | src-commit: b87605e | doc: README -->

# Luminal Open CMS

A file-based PHP content management system for people who run **more than one site**.

It was built over two years to operate a working fleet of ~35 live sites from a single codebase,
and it is released here under the Apache License 2.0. See [`NOTICE`](../NOTICE) for the origin
story, and [`LICENSE`](../LICENSE) for your rights — which are broad.

**Doc version 1.1.0 · cut 2026-08-31T00:29Z**

---

## What it is

- **No database.** Content, settings and module data are JSON files on disk. You can read your
  whole site with `cat`, back it up with `tar`, and diff it in git. A few optional modules use a
  database for their own storage; the core does not.
- **Modular.** Every feature is a directory under `admin/modules/`. Drop one in and it appears —
  there is no registration step, no build, no package manager. See
  [`MODULE-API.md`](MODULE-API.md).
- **Multi-site by design.** The architecture assumes you will run the same codebase across many
  sites with per-site data. That assumption shaped everything, and it is the main reason to
  choose this over a single-site CMS.
- **Plain PHP.** No framework, no compile step, no `node_modules`. Deploy is copying files.

## What it is not

Honesty is more useful than a feature list:

- **There is no migration path from WordPress.** If you have an existing WordPress site, there is
  currently no importer. This is the single biggest gap.
- **It is not a plugin marketplace.** The module API is real and stable, but the ecosystem is
  whatever you and the community build.
- **The admin UI assumes a technical operator.** It is dense and functional rather than gentle.
- **It grew inside one organisation's fleet.** Some conventions exist because they solved a real
  problem there. Where that leaks into your way of working, change it — that is the point of the
  license.

## Requirements

| | |
|---|---|
| PHP | 8.1+ (developed and run on 8.3) |
| Extensions | `json`, `mbstring`, `curl`, `gd`, `openssl` |
| Web server | Apache with **`mod_rewrite`** enabled (`AllowOverride All` for the site root) |
| Database | none |

## Install

```bash
# 1. put the tree at your web root
git clone https://github.com/<org>/luminal-open-cms.git /var/www/mysite
cd /var/www/mysite

# 2. the web server user must own the data and media directories
chown -R www-data:www-data admin/data media

# 3. point an Apache vhost at the directory, with AllowOverride All
#    then load the site in a browser
```

The admin lives at `/admin/`. First load walks you through creating the initial user.

⚠️ **`admin/data/` must never be web-readable.** The shipped `.htaccess` files handle this, which
is why `AllowOverride All` matters — without it, your settings and content are served to anyone
who asks.

## Layout

```
admin/          the admin application
  data/         ← ALL persistent data lives here. Back this up.
  modules/      ← features. Drop a directory in to install it.
css/ js/        front-end assets
header/         shared page furniture
includes/       shared PHP (shortcodes, renderers)
media/          user-uploaded images, audio, documents
panels/         reusable front-end blocks
```

**Back up `admin/data/` and `media/`.** Everything else is code you can re-clone.

## Documentation

| doc | what it covers |
|---|---|
| [`MODULE-API.md`](MODULE-API.md) | writing a module — the manifest, the loader, where data lives |
| [`CONTRIBUTING.md`](../CONTRIBUTING.md) | how to propose changes |
| [`SECURITY.md`](../SECURITY.md) | reporting a vulnerability |

Every document carries its own version stamp in its header, so you can tell which release a page
belongs to and request an updated one.

## Fleet users — running many sites

If you are running more than a handful of sites, you will want a deployment system: something that
pushes new code to every site at once without touching any site's content.

**We do not ship one, and that is deliberate.** A deployment system is welded to *your*
infrastructure — your servers, your domains, your backup storage. Ours would cost you more to
un-pick than to write. What transfers is the design, and the hard-won rules about how such a system
lies to you.

So: **have Claude, Codex, or the coding agent of your choice build one for you.** Point it at
[`docs/DEPLOY-ARCHITECTURE.md`](DEPLOY-ARCHITECTURE.md) — it is written to be implemented from,
with a ready-to-paste brief at the end.

The short version of what you are asking for:

> You have one codebase and many sites. Every site has its own content, settings and uploads that
> the codebase knows nothing about. A deployment system copies new **code** to every site while
> touching no site's **content** — and, above all, tells you the truth about whether it worked.
>
> Build it in this order. **Back up every site first, and check the backup actually arrived** —
> not that the backup command succeeded. **Deploy to one site**, and prove that site still works
> by loading a page from it. **Ask a human** before doing the other thirty-nine. Then **check each
> site really got the new file** by comparing file contents, never by trusting a success message.
>
> The rule underneath all of it: the expensive failures are not crashes. They are the times
> something reported success while doing nothing.

That last paragraph is two years of running a fleet, compressed. The long version, with the
specific incidents behind each rule, is in the architecture doc.

## Commercial modules

Some modules are not part of this release and are available commercially — podcast production and
publishing, and a native WebRTC studio. They install into this CMS through the same public module
API documented here; nothing about them is privileged. If you fork and rebrand this software, they
still install into your fork.

## Support

Community support is best-effort through the issue tracker. There is no service level, and no
promise that an issue will be answered. If you need guaranteed support, that comes with the
commercial modules.

This is stated plainly up front because a boundary drawn later reads as a bait-and-switch.
