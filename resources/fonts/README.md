# Bundled PDF fonts

Used only by PDF rendering (`config/pdf.php`, `HtmlToPdfRenderer::fontFaceCss()`),
never by the admin SPA — Vite does not touch this directory.

| File | Family | Licence |
|---|---|---|
| `NotoSans.ttf` | Noto Sans (variable, `wdth`/`wght`) | SIL Open Font License 1.1 — see `OFL.txt` |
| `NotoSansBengali.ttf` | Noto Sans Bengali (variable, `wdth`/`wght`) | SIL Open Font License 1.1 — see `OFL.txt` |

**Why bundled rather than installed into the image.** A ticket is printed and
checked at a gate, so it must look the same on a developer's machine and in
production. Leaving that to whatever font package a container base image happens
to ship is exactly the kind of difference that is invisible until someone prints
12,000 of them.

**Why variable fonts.** One file carries every weight, so bold Bangla works.
That is not cosmetic: the previous renderer used mpdf's bundled
`FreeSerifBold.ttf`, which has zero glyph coverage for the Bengali block, so
bold Bangla did not lose its weight — it disappeared from the page.

**Note for anyone tempted to swap these back to a static build:** current Noto
Bengali releases use an OpenType GPOS lookup (Type 5, Format 3) that mpdf's font
engine cannot parse. That was the original reason this project shipped FreeSerif
instead. It is a non-issue for HarfBuzz, which is what Chrome shapes with, so it
only matters if the renderer is ever moved back to a PHP library.
