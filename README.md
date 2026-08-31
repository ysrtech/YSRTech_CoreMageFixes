# YSRTech_CoreMageFixes

A Magento 1 / OpenMage module for bug fixes to core (`Mage_*`) classes that
don't belong to any single site. Installed alongside a site's own codebase,
not tied to one theme or installation.

## Why this exists

Site-specific repos tend to accumulate core overrides as raw file copies
dropped into `app/code/local/Mage/...`, relying on Magento's `local` pool
loading before `core`. That works, but it silently freezes the *entire*
overridden file at whatever core looked like on the day it was copied - any
later upstream bug fix to that file's other methods is lost until someone
notices and manually re-diffs it.

This module instead uses real class rewrites (`<rewrite>` in `etc/config.xml`)
with thin subclasses that `extends` the actual core class and override only
the one method being fixed. Everything else is inherited live from whatever
core version is actually installed, on this site or any other.

## Adding a new fix

1. Add a small subclass under `Model/` (or wherever matches the namespace of
   the class being fixed) that `extends` the real core class and overrides
   only the broken method(s). Don't copy the rest of the class.
2. Register it with a `<rewrite>` entry in
   `app/code/local/YSRTech/CoreMageFixes/etc/config.xml`, under the correct
   `<global><models>` (or `<blocks>`/`<helpers>`) group.
3. Document *why* the fix exists in a short comment on the class - the
   original bug, not just what the code does.
4. Bump the module `<version>` in `etc/config.xml`.

Only one rewrite target may exist per class per installation (Magento 1 has
no rewrite conflict resolution) - check `app/code/local`, `app/code/community`,
and any third-party modules on the target site for an existing rewrite of the
same class before adding one here.

## Current fixes

### `Mage_Catalog_Model_Product_Type_Configurable_Attribute::getLabel()`

**Bug:** A configurable product's super-attribute label can get permanently
frozen. When an attribute is newly assigned to a configurable product (via
Admin's Configurable Attributes step, or on import), the association is
saved with `use_default` unset - which core casts to `0` - together with
whatever label text happened to be showing at that moment. From then on,
`getLabel()` returns that frozen value forever for that product/attribute
pair, completely ignoring any later translation or label change made on the
attribute itself in Admin. This affects every product-facing surface that
renders a configurable attribute's label (PDP swatch/dropdown, admin grid,
etc).

**Fix:** `getLabel()` no longer looks at `use_default` or any saved custom
label at all - it always returns the attribute's own `getStoreLabel()`. The
per-product custom-label feature is intentionally dropped in favor of a
single source of truth: whatever the attribute's label is set to, per store
view, in Admin.
