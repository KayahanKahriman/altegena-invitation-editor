# Print-only TrueType conversions

The print engine reads TrueType (`glyf`) outlines. These three fonts ship as
CFF-outline OpenType (`.otf`) for the website, so they were converted once,
offline, with fontTools 4.65.0 (the `otf2ttf` recipe: cu2qu, max error 1 font
unit, reversed contour direction, post format 2).

`Altegena_Print_Font_Registry::print_file()` uses a file from this folder
whenever one exists with the same name as the web font. The website keeps
loading the original `.otf` files.

The conversion was verified to keep glyph order (so glyph ids), advance widths,
cmap and the GSUB/GPOS/GDEF tables identical to the source.

| Print file | Source (`assets/fonts/`) | Source SHA-1 | Print file SHA-1 |
|---|---|---|---|
| Champignon.ttf | Champignon.otf | 8179653f6c751a1e5cf2999592c14aa1c789b305 | e116fc0972d47379b4c3fc96686e04cc84a1525c |
| ChristmasWishCalligraphy.ttf | ChristmasWishCalligraphy.otf | f0e7100f39072c6fc870b950cda9155ad93ca188 | 83e42c465450dad405a09b5f0a4002acc17d1e5a |
| Marquette.ttf | Marquette.otf | bef285331f87bc89b8be6f22fb4161cb5370d96f | f6715c9c3f86751ad5889d04be52509e49b58ae4 |

If a source `.otf` changes (for example to add missing Turkish glyphs),
re-run the conversion and update this table. Otherwise the print output will
not match the website.
