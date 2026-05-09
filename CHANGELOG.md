# Changelog

## Unreleased

### Fixed
- **AdminProxy demuxes API vs GUI paths.** ledric's API endpoints
  (`/types`, `/rpc`, `/entries/...`, `/assets/...`, `/tags`, `/auth/...`,
  `/.well-known/...`, `/mcp`) live at the upstream root, but the GUI is
  under `/admin/*`. The proxy now routes the first-segment-matching
  API paths to root and prefixes everything else with the GUI mount.
  Without this, `api.types()` 404'd and the inline editor surfaced
  "Unknown type 'X'" for every editable element. Configurable via
  `ledric.admin.upstream_root_paths`.
- **AdminProxyController** no longer throws `Unable to read from stream`
  on every response. PSR-7 `Stream::read()` over curl-backed Guzzle
  bodies returns `false` at EOF before `eof()` flips, which the
  streaming loop didn't survive. Body is now buffered — admin GUI
  traffic is bounded HTML/JS/CSS, not large binaries (those go through
  `AssetProxyController`).
- **Outbound proxy path** now prefixes ledric's GUI mount on non-API
  routes. `/ledric-admin/inline.js` correctly reaches
  `<ledric>/admin/inline.js` rather than `<ledric>/inline.js` (404).
  Configurable via `LEDRIC_ADMIN_UPSTREAM_PREFIX` (default `admin`).

### Added
- `AdminProxyTest` covering both fixes plus header forwarding,
  upstream-prefix translation, API/GUI demux, and 503-on-unreachable.

### Changed
- Drops `'stream' => true` from `Client::forwardAdmin` since the
  controller buffers anyway.

## c14b026 — 2026-05-09

### Changed (breaking)
- Write methods reshape from opaque-array to typed parameters:
  ```
  draftEntry(string $type, array $fields, ?string $slug = null, array $opts = [])
  publishEntry(string $type, string $slug, ?int $version = null)
  renameEntry(string $type, string $slug, string $newSlug, ?string $locale = null)
  deleteEntry(string $type, string $slug)
  addEntryTags(string $type, string $slug, array $tags)
  removeEntryTags(string $type, string $slug, array $tags)
  ```
- Backward-compat shims removed: `normalizeEntry`/`normalizeEntryList`,
  `wrapRef`, `content → fields` alias in `draftEntry`, and the
  `schema_version` strip. **Requires ledric ≥ 0.3.6.**

## ca854d4 — 2026-05-09

### Fixed
- Wrong RPC tool names: `searchEntries` was posting to a non-existent
  `search_entries` (now routes through `find` with `q`); `draftEntry` /
  `publishEntry` were posting `draft_entry` / `publish_entry` (actual
  tools are `draft` / `publish`); `listTypes` was posting `list_types`
  which doesn't exist (now derives from `describe_model`).
- Entry-mutation tools now correctly nest `{ref: {type, slug}}` to
  match each tool's strict zod schema.

## ec46c05 — 2026-05-09

### Fixed
- 4xx errors with structured `{code, message}` bodies no longer crash
  the client (`(string) $array` warning).
- `read` now wraps args as `{ref: {type, slug}}` per the MCP schema.
- Successful `/rpc` responses are unwrapped from their `{result: ...}`
  envelope.

## 68c939b — 2026-05-04

Initial: Laravel client + admin/asset/preview proxies for ledric.
