<!-- doc-version: 1.0.0 | cut: 2026-08-31T00:15Z | src-commit: b87605e | doc: MODULE-API -->

# Module API v1.0

**Doc version 1.0.0 · cut 2026-08-31T00:15Z · from source commit `b87605e`**

> Each document in this set carries its own version stamp. Filenames are stable so links do not
> rot; revisions bump the stamp in the header above. If you are reading a copy without a stamp,
> it is not a release document.

This describes the **module contract** for Luminal Open CMS: how a module declares itself, what the
loader does with that declaration, and where a module may store data. It is a description of
behaviour that already exists, not a proposal.

**Module API version: `1.0`.** Declare compatibility with `"requires_cms_api": "1.0"` (see
[Versioning](#versioning)).

---

## 1. What a module is

A module is a directory under `admin/modules/` containing a `module.json` manifest and at least an
entry point. The loader discovers it, reads the manifest, and — if the module declares a menu entry
— places it in the admin menu. There is no registration step, no database, and no build.

```
admin/modules/MyModule/
├── module.json          # the manifest — required
├── MyModule.php         # entry_point — the admin screen
├── api.php              # api_endpoint — optional, for XHR/JSON
└── lib/…                # whatever else the module needs
```

Persistent data does **not** live here. See [§4](#4-where-data-lives).

### How the loader finds your module

`admin/modules/module_loader.php` has two discovery paths, and it is worth knowing which one you
are on:

1. **Glob discovery (the default).** The loader globs `admin/modules/*/module.json` and loads
   everything it finds. Drop a directory in, it appears; remove it, it silently disappears. This
   is what a standard Luminal Open CMS install uses, because it applies whenever there is no
   `admin/deployment_mgr/site_distribution.json`.
2. **Registry discovery (managed fleets).** If a site declares a distribution, the loader reads a
   generated registry that names exactly which modules that class of site should have. This exists
   so an operator running many sites can control module membership centrally.

Registry discovery is **fail-soft by design**: if the registry is missing or yields nothing, the
loader falls through to glob discovery rather than rendering an empty admin menu. So a module
directory that is present is, in practice, a module that loads.

⇒ **You do not register a module anywhere.** Installation is copying a directory.

## 2. The manifest

```json
{
  "name":             "MyModule",
  "version":          "1.0.0",
  "requires_cms_api": "1.0",
  "description":      "One sentence on what this module does.",
  "author":           "Your Name",
  "entry_point":      "MyModule.php",
  "api_endpoint":     "api.php",
  "dependencies":     [],
  "menu_entry": {
    "key":   "MyModule",
    "title": "My Module",
    "href":  "MyModule.php",
    "role":  "admin",
    "order": 50,
    "icon":  "🧩"
  },
  "permissions": {
    "admin": ["read", "write", "create", "delete"],
    "staff": ["read"]
  }
}
```

### Field reference

| field | required | meaning |
|---|---|---|
| `name` | **yes** | Must match the directory name exactly. It is the module's identity everywhere. |
| `version` | **yes** | The module's own version. Semantic versioning is expected but not enforced. |
| `requires_cms_api` | recommended | Which Module API this module targets, e.g. `"1.0"`. Omitted means "unversioned, pre-1.0" — accepted, but you lose compatibility diagnostics. |
| `description` | **yes** | One sentence. Shown in module listings. |
| `author` | no | Free text. |
| `entry_point` | practically yes | The admin screen, relative to the module directory. Without it a module has no UI. |
| `api_endpoint` | no | Relative path to a JSON endpoint for the module's own XHR calls. |
| `dependencies` | no | Array of module `name`s that must be installed. Absent or `[]` means none. |
| `menu_entry` | no | Places the module in the admin menu. Omit for modules with no UI of their own. |
| `permissions` | no | Role → capability map. Roles not listed get nothing. |
| `requires_data_dirs` | no | **Scaffolding only — read [§4](#4-where-data-lives) before using.** |
| `features` | no | Array of strings, for display. |

### Deprecated aliases — do not use in new modules

These appear in older modules. **The loader does not read them; they do nothing at all.** They are
listed so you recognise them in existing code, not so you copy them.

| you may see | the field that actually works |
|---|---|
| `data_dirs` | `requires_data_dirs` |
| `data_dir` | `requires_data_dirs` |
| `requires` | `dependencies` |
| `menu_entries` | `menu_entry` |

## 3. The menu

A module with a `menu_entry` appears in the admin navigation. `order` sorts it; `role` gates who
sees it; `href` is relative to the module directory.

Modules that supply a whole navigation section rather than a single item use `menu_provider`
instead — that is a distinct, less common pattern; a single `menu_entry` is what most modules want.

⚠️ **Keep `menu_entry.href` pointing at a file that exists.** If you remove the target file but
leave the entry, the module renders a dead menu item — a failure that is invisible until a user
clicks it.

## 4. Where data lives

**Persistent module data belongs in `admin/data/{ModuleName}/`.**

That is the rule, and it exists for a concrete reason: **updates overwrite the module directory.**
Anything your module wrote inside `admin/modules/MyModule/` can be replaced on the next update.
`admin/data/` is never overwritten.

```php
$dataDir = SITE_ROOT . '/admin/data/MyModule';
if (!is_dir($dataDir)) { @mkdir($dataDir, 0775, true); }
```

### ⚠️ `requires_data_dirs` does NOT do what its name suggests

The loader auto-creates the directories you list — but **relative to the module directory**:

```php
// admin/modules/module_loader.php
$fullDir = dirname($manifestPath) . '/' . $dir;   // → admin/modules/MyModule/{dir}
```

So `"requires_data_dirs": ["data"]` creates `admin/modules/MyModule/data` — inside the directory
updates overwrite. **It is safe only for scaffolding your module ships and can regenerate** (a
cache dir, a config skeleton). It is the wrong place for anything a user would be upset to lose.

If in doubt, do not use the field. Create `admin/data/{ModuleName}/` yourself, as above.

📌 *This asymmetry is retained in v1.0 rather than changed, because altering the loader's
resolution would move data under every module already relying on the current behaviour. It is
documented instead. A future API version may resolve it to `admin/data/` and offer a migration.*

## 5. Optional modules and graceful degradation

The CMS is built so that **a missing module is a missing feature, never an error.** If you extend
core behaviour, follow the same discipline — it is what lets a site run any subset of modules.

Guard file includes:

```php
$mod = SITE_ROOT . '/admin/modules/MyModule/lib/render.php';
if (is_file($mod)) { require $mod; }
```

Guard function calls, and degrade visibly but harmlessly:

```php
$html = function_exists('mymodule_render')
    ? mymodule_render($attrs)
    : '<!-- mymodule: module not installed -->';
```

For an API route, return a clean status rather than fataling:

```php
if (!is_file($required)) {
    api_json(['ok' => false, 'error' => 'MyModule not found on this site'], 404);
}
```

**Never** `require` a module's file at the top level of a shipping file. If you must, scope it
inside the branch that actually needs it.

## 6. Dependencies

`dependencies` lists module names, not versions:

```json
"dependencies": ["OtherModule"]
```

The declaration is advisory — it documents intent and drives tooling. **It does not stop your
module loading when the dependency is absent**, so guard your use of another module's code exactly
as in [§5](#5-optional-modules-and-graceful-degradation). Assume the dependency may be missing.

## 7. Versioning

The Module API is versioned separately from the CMS and from your module.

- **`1.0`** — this document. The contract described here.
- Additive changes (new optional fields) bump the **minor**: `1.1`, `1.2`. Modules targeting `1.0`
  keep working.
- Changes that alter or remove existing behaviour bump the **major**: `2.0`. Those will be
  announced with a migration note, not shipped silently.

Declare what you target:

```json
"requires_cms_api": "1.0"
```

A module that omits it is treated as pre-1.0 and loaded anyway; you simply lose the ability to be
told that the CMS has moved on.

## 8. A minimal working module

```
admin/modules/HelloWorld/
├── module.json
└── HelloWorld.php
```

```json
{
  "name": "HelloWorld",
  "version": "1.0.0",
  "requires_cms_api": "1.0",
  "description": "The smallest module that appears in the admin menu.",
  "entry_point": "HelloWorld.php",
  "dependencies": [],
  "menu_entry": {
    "key": "HelloWorld", "title": "Hello World",
    "href": "HelloWorld.php", "role": "admin", "order": 99, "icon": "👋"
  }
}
```

```php
<?php
$dataDir = SITE_ROOT . '/admin/data/HelloWorld';
if (!is_dir($dataDir)) { @mkdir($dataDir, 0775, true); }
echo '<h1>Hello World</h1>';
```

Drop the directory in `admin/modules/`, reload the admin. That is the whole install process —
glob discovery picks it up with no registration step.
