# TrackWP Translations

Source language: Danish (da_DK).

Translation files in this directory:

- `trackwp.pot` — empty template, regenerated when source strings change
- `trackwp-en_US.po` — English translation (source)
- `trackwp-en_US.mo` — English translation (compiled; this is the file WordPress actually reads)

## The .mo MUST be committed and shipped

WordPress loads `.mo`, never `.po`. Until 1.9.0 only the `.po` was in the repo and in the release
ZIP, so the English translation never worked on any site — English admins silently got Danish
strings. **When you change a translation, recompile and commit the `.mo` in the same commit.**

## Compiling .mo

With gettext installed:

```
msgfmt trackwp-en_US.po -o trackwp-en_US.mo
```

Or WP-CLI: `wp i18n make-mo languages`, or Poedit (which compiles on save).

**No `msgfmt` on this machine.** The Windows dev box has no gettext, which is how the missing
`.mo` went unnoticed for so long. The 1.9.0 `.mo` was produced with a small Python compiler
(the MO format is a simple hash-less table of sorted msgid/msgstr pairs). Verify any compiled
file before committing:

```
python -c "import gettext; t=gettext.GNUTranslations(open('trackwp-en_US.mo','rb')); print(t.gettext('Vi bruger cookies'))"
```

That must print `We use cookies`, not the Danish input.

## Regenerating the .pot

The `.pot` is stale whenever source strings change — 1.9.0 added a batch of new admin strings
(firing triggers, conditions builder, delivery log) that are not in it yet. Regenerate with
WP-CLI:

```
wp i18n make-pot . languages/trackwp.pot --domain=trackwp
```

Run it from the plugin root, then merge into existing `.po` files with `msgmerge` before
translating the new entries.

## Adding a new locale

1. Copy `trackwp.pot` to `trackwp-<locale>.po` (e.g. `trackwp-de_DE.po` for German)
2. Translate every `msgstr ""`
3. Compile to `.mo` (see above) and commit **both** files
