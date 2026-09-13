# Bundled fonts

## Play

The display face Convermetry's admin headings are set in, matching the
Convermetry dashboard design.

The files here are the Google Fonts builds of **Play v21**, downloaded once and
committed. They are **self-hosted on purpose**: linking
`fonts.googleapis.com` would make every Convermetry admin page view a request to
a third party and hand that third party the administrator's IP address and
referring URL, which is not a thing a plugin should do to a site owner's
wp-admin without asking. Self-hosting also means the headings render the same on
an intranet, behind a firewall, and offline.

| File | Weight | Subset |
|---|---|---|
| `play-400-latin.woff2` | 400 | latin |
| `play-400-latin-ext.woff2` | 400 | latin-ext |
| `play-700-latin.woff2` | 700 | latin |
| `play-700-latin-ext.woff2` | 700 | latin-ext |

Latin and Latin Extended only — about 36 KB in total, and only the subsets the
`unicode-range` declarations in `assets/css/admin-ui.css` actually reference. A
heading in Greek, Cyrillic or Vietnamese falls back to the system stack rather
than downloading a subset almost no installation will use; that is a deliberate
trade, and the fallback is the same stack the body copy uses, so such a heading
still looks intentional.

`font-display: swap` means headings paint immediately in the fallback face and
re-render when Play arrives; the page is never blank waiting on a font.

### Licence

Play is licensed under the **SIL Open Font License 1.1** — see `OFL.txt`, the
upstream licence file, copied here unmodified. The OFL permits bundling and
redistribution with software, which is what this directory does. The font files
themselves are unmodified.

Copyright (c) 2011, Jonas Hecksher, Playtypes, e-types AS, with Reserved Font
Name 'Play', 'Playtype', 'Playtype Sans'.

### Updating

Re-download from the Google Fonts CSS API rather than editing these files:

```sh
curl -A "Mozilla/5.0 … Chrome/120.0 Safari/537.36" \
  "https://fonts.googleapis.com/css2?family=Play:wght@400;700&display=swap"
```

The user-agent matters: Google serves woff2 only to a browser that says it
supports it. Take the `latin` and `latin-ext` URLs from the response, save them
under the names above, and bump `CVM_VERSION` so the cache busts.
