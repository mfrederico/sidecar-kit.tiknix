# Tiknix Sidecar Kit

The shared **front-controller boot + SSO-consume + plugin registry** that every tiknix
sidecar runs on: `pipelines.tiknix`, `store.tiknix` (shop), `explorer.tiknix`, and the
planned `aibuilder.tiknix` / `workspace.tiknix`.

A sidecar is a standalone app SSO'd into the tiknix shell iframe. This kit is the piece
they all share so a new sidecar is *its own controllers + views* and nothing else.

## What's here

- `src/Sidecar/Kernel.php` — the front controller: allowlist `guard()`, boot, dispatch
  to the sidecar's own controllers.
- `src/Sidecar/Sso.php` — consume the token minted by core at `/sidecar/launch/<name>`
  and start a same-site session (`Sso::session()`).
- `src/Sidecar/Token.php` — HMAC token verify (shared `sso_secret`).
- `src/Sidecar/Access.php` — the SSO'd member's accessible instances (via core DB).
- `src/Sidecar/Registry.php` — `[sidecar.<name>]` discovery (core side).
- `templates/public/index.php` — the front-controller a new sidecar drops into `public/`.

## The contract (the ABI a sidecar must honor)

1. **Register** — one `[sidecar.<name>]` in **core's** `conf/config.ini`
   (`url`, `sso_secret`, `feature`, `label`, `icon`). Core's `Registry::all()` finds it.
2. **Consume SSO** — accept the `?token=` at `/sso`, verify with the shared `sso_secret`,
   start the session. Same-site `*.tiknix.com` so the `SameSite=Lax` cookie survives the
   iframe.
3. **Embed** — render inside core's shell iframe at `/sidecar/app/<name>`; no
   `X-Frame-Options`; may `postMessage({tiknixHeight:N})` to size the frame.
4. **Reach instances only over the documented server-to-server paths** — an instance's
   `[pipeline] trigger_secret` for its own `/pipeline/*`, or its `brk_` broker key for the
   MCP broker. Never hold a connector token; never touch a connector credential.

Satisfy that and a sidecar can be written in **any language** (see §"Non-PHP" below).

## Minimal PHP sidecar

```
my-sidecar/
  conf/config.ini            # [sidecar] core_root, sso_secret, feature …  (gitignored)
  conf/config.example.ini
  public/index.php           # copy templates/public/index.php
  controls/Sso.php           # SSO consume endpoint
  controls/Index.php         # your landing
  controls/<Feature>.php     # your app
  views/…
```

`public/index.php` boots the Kernel and maps route prefixes to your controllers:

```php
(new app\Sidecar\Kernel(dirname(__DIR__), [
    'index' => 'Index',
    'sso'   => 'Sso',
    'edit'  => 'Edit',     // your feature
]))->run();
```

## ⚠️ Current coupling — read before treating this as a package

Today this kit is **not dependency-free**. The sidecar front controller does:

```php
require $coreRoot . '/lib/Sidecar/Kernel.php';   // co-located core
```

and `Kernel` then `require`s **core's `vendor/autoload.php`**, so a sidecar boots on top
of the co-located core repo's shared classes (`app\BaseControls\Control`, `app\Bean`,
RedBeanPHP, Flight). In other words: **a sidecar currently must live on the same box as a
core checkout**, and `core/lib/Sidecar/*` is the *live* source.

This repo is the **canonical home** for that source and the template for new sidecars, but
the two are kept in sync by hand until the de-coupling below lands.

## Roadmap (from `SIDECAR-ECOSYSTEM-PLAN.md` in core)

- **Make this repo the single source of truth** — core consumes it (composer/path) instead
  of owning `lib/Sidecar/*`, so they can't drift.
- **De-co-locate (Phase D)** — replace `require $coreRoot/...` + core-autoload with a
  self-contained kit (own Flight/RedBean deps or a thin HTTP client), and move
  sidecar→instance access fully onto HTTP (`trigger_secret`/`brk_`). *That* is what lets a
  sidecar run anywhere and be written in any language.
- **Language-agnostic SSO-consume spec** — for non-PHP sidecars, document token verify +
  the session handshake as a spec, not just this PHP lib.

## Non-PHP sidecars

Until de-co-location, a sidecar must be PHP-on-core-box. After it, any language that can:
verify the HMAC SSO token, hold a same-site session cookie, render in an iframe, and call
the instance's `trigger_secret`/`brk_` HTTP endpoints — is a valid sidecar.
