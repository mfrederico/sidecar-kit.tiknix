# Tiknix Sidecar Kit

The shared **front-controller boot + SSO-consume + plugin registry** that every tiknix
sidecar runs on: `pipelines.tiknix`, `store.tiknix` (shop), `explorer.tiknix`, and the
planned `workbench.tiknix` (task board + AI Builder).

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

   **A link to core is a link OUT of the embed.** Build it from `sidecar.core_url` (never
   a leading slash — that resolves against *this* host and 404s) and give it
   `target="_top"`:

   ```php
   <a href="<?= htmlspecialchars((string) Flight::get('sidecar.core_url')) ?>/teams"
      target="_top">Teams</a>
   ```

   Without `_top` the link loads core's entire shell *inside* the frame — nav within nav,
   with the real topbar still above it. It looks like it worked, which is why it survived
   in three separate sidecars. Same-sidecar links take no target; a deliberate new tab
   keeps `target="_blank"`.
4. **Reach instances only over the documented server-to-server paths** — an instance's
   `[pipeline] trigger_secret` for its own `/pipeline/*`, or its `brk_` broker key for the
   MCP broker. Never hold a connector token; never touch a connector credential.
5. **Take the project from the SSO claim. Never offer your own picker.**
   Core owns which project a member is working on; the claim carries `instance` + `slug`,
   already access-checked. Use them:

   ```php
   $project = \app\Sidecar\Sso::project();          // ['id' => int, 'slug' => string] | null
   if (!$project) {
       Flight::redirect(\app\Sidecar\Sso::projectPickerUrl());   // core's /projects
       return;
   }
   ```

   **Do not fall back to "the first accessible instance."** That single line is what made
   the surface flip: a sidecar guessing on your behalf meant arriving from another tool
   could silently edit, or show tasks for, a project you had not chosen — with nothing in
   the UI to reveal the swap. If the claimed project is not accessible here, send the
   member back to core to choose; do not substitute another.

   Deep links may still address a specific instance explicitly (`?inst=<slug>`,
   `?instance_id=<id>`); resolve those first, then fall through to the claim.

Satisfy that and a sidecar can be written in **any language** (see §"Non-PHP" below).

### Naming

One concept, one name, so the next sidecar does not reinvent it:

| Term | Means |
|---|---|
| **project** | what the member chose in core — the SSO claim, `Sso::project()` |
| **instance** | the underlying registry row / clone that a project resolves to |
| `slug` | the instance's immutable `{base}-{hash}` identity |

A sidecar's own selection helper should be named for the claim it consults
(`projectInstance()`), not for a default it invents.

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
