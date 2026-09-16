# Bundled rendering fonts

Used only by headless-Chrome rendering (`config/pdf.php`,
`HtmlToPdfRenderer::fontFaceCss()`) — the ticket and directory PDFs and the
"আমি থাকছি!" share card — never by the admin SPA; Vite does not touch this
directory.

| File | Family | Used by | Licence |
|---|---|---|---|
| `NotoSans.ttf` | Noto Sans (variable, `wdth`/`wght`) | PDFs | SIL Open Font License 1.1 — see `OFL.txt` |
| `NotoSansBengali.ttf` | Noto Sans Bengali (variable, `wdth`/`wght`) | PDFs | SIL Open Font License 1.1 — see `OFL.txt` |
| `NotoSerifBengali.ttf` | Noto Serif Bengali (variable, `wdth`/`wght`) | share card | SIL Open Font License 1.1 — see `OFL.txt` |
| `JetBrainsMono.ttf` | JetBrains Mono (variable, `wght`) | share card (registration number) | SIL Open Font License 1.1 — see `OFL.txt` |

The share card's two faces are the ones its Figma frame is set in
("Event Ticket — v7", node 227:731); they are not substitutes.

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
