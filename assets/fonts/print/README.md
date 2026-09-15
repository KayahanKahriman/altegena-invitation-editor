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
| ChristmasWish-TR.ttf | ChristmasWish-TR.otf | 272df431eab1a763465d7316828e647cb66987c4 | 2221929be51312f431209848a104fc75ae954b0c |
| Marquette-TR.ttf | Marquette-TR.otf | 21d710864451682a8bb01e24330cede11bba60cc | d80831dabdca0319a4749945fee518fa6e06030c |

If a source `.otf` changes (for example to add missing Turkish glyphs),
re-run the conversion and update this table. Otherwise the print output will
not match the website.
