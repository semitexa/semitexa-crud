# semitexa/crud

Admin screens declared in one class, over one ORM model, from a list of fields.

- `#[AsCrud]` + `CrudDefinition`: the whole screen. You get:
  - the list page in your layout, and a live grid with search, sort, filters and paging;
  - create and edit dialogs that live in the address (`?create`, `?edit=<id>`);
  - delete;
  - `{permission}.read / .create / .edit / .delete`, checked on every request and every write;
  - navigation (`crud_nav()`) and Ctrl+K commands.
- `#[AsSettingsPage]` + `SettingsDefinition`: a settings page whose fields have defaults. Its values are stored per tenant in semitexa/platform-settings and read back with `SettingsValues`.
- `RecordCountWidget` / `RecentRecordsWidget`: dashboard widgets for a screen (`#[AsDashboardWidget]`).
- `#[AsCollectionFeed]` + `CollectionFeed`: just the live feed behind a `platform.grid`.

Writes go through the ORM's aggregate write engine. It stamps the tenant, checks `#[Version]`
and fills in `created_at` / `updated_at`, and the change event refreshes every open grid. The
model's single-column unique indexes answer as field errors.

Field types come from `semitexa/platform-ui` (`Field::text('title')`, …).

## Install

Not included by the installer, and not tagged yet. Until its first release, add it to an existing project from the project root:

```bash
docker compose run --rm --no-deps --user "$(id -u):$(id -g)" app composer require semitexa/crud
bin/semitexa server:restart
```

Docs (in semitexa/docs): [CRUD screens](https://github.com/semitexa/semitexa-docs/blob/master/docs/en/rendering/crud-screens.md), [settings pages](https://github.com/semitexa/semitexa-docs/blob/master/docs/en/rendering/settings-pages.md), [dashboards](https://github.com/semitexa/semitexa-docs/blob/master/docs/en/rendering/dashboards.md) and [collection feeds](https://github.com/semitexa/semitexa-docs/blob/master/docs/en/rendering/collection-feeds.md).
