# TrackWP Translations

Source language: Danish (da_DK).
Translation files in this directory:

- `trackwp.pot` — empty template, regenerated when source strings change
- `trackwp-en_US.po` — English translation (compile to .mo before WP can use it)

## Compiling .mo

WordPress reads compiled `.mo` files, not `.po` source. To compile:

```
msgfmt trackwp-en_US.po -o trackwp-en_US.mo
```

Or use any tool that handles gettext (Poedit, WP-CLI `wp i18n make-mo languages`).

## Adding a new locale

1. Copy `trackwp.pot` to `trackwp-<locale>.po` (e.g. `trackwp-de_DE.po` for German)
2. Translate every `msgstr ""`
3. Compile to `.mo` (see above)
