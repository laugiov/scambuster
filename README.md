# scambuster.ai — static site

The one-page marketing site for ScamBuster, served by **GitHub Pages** at the
custom domain **scambuster.ai**.

## What it is

A plain static site. Vanilla HTML, CSS, and a touch of vanilla JS. No framework,
no bundler, no build step. Just push the files and GitHub Pages serves them.

```
index.html        the page
styles.css        all styling (design tokens at the top)
script.js         optional effects only (typewriter, scroll reveal)
404.html          on-brand not-found page
robots.txt        allow all
sitemap.xml       the single URL
CNAME             custom domain (scambuster.ai)
.nojekyll         tells Pages to skip Jekyll processing
assets/
  scambuster_logo_horizontal.svg   top bar + footer lockup
  scambuster_mark.svg              spider-on-radar hero mark
  scambuster_og.png                1200x630 social share image
  favicon.png                      favicon (generated from the mark)
  BHUS26-Giovannoni-Scambuster-Slides.pdf   the Black Hat USA 2026 deck
```

### The slide deck

`assets/BHUS26-Giovannoni-Scambuster-Slides.pdf` is the Black Hat USA 2026 deck
(90 slides), served straight off Pages.

The Google Slides export is **fully rasterised**: it embeds no fonts, and every
page is a single flattened JPEG. So there is no text layer — the deck is not
selectable, not searchable, and not readable by a screen reader, and resolution
is the only thing standing between a reader and blurry text. Any re-compression
is a real, visible quality trade, not a free win.

The export is ~17 MB at 137 ppi. The file committed here is downsampled to
130 ppi (~7.6 MB): a 5% linear reduction that is indistinguishable from the
original side by side, while cutting the download by more than half. Do not go
lower — at 120 ppi, code and terminal slides visibly soften.

Note that Ghostscript only re-encodes images when it also downsamples them. With
downsampling off it passes the JPEG streams through untouched and the file size
does not move, whatever `-dJPEGQ` says.

```
gs -sDEVICE=pdfwrite -dCompatibilityLevel=1.5 -dNOPAUSE -dQUIET -dBATCH \
   -dDetectDuplicateImages=true \
   -dDownsampleColorImages=true -dColorImageDownsampleType=/Bicubic \
   -dColorImageResolution=130 -dColorImageDownsampleThreshold=1.0 \
   -dDownsampleGrayImages=true -dGrayImageDownsampleType=/Bicubic \
   -dGrayImageResolution=130 -dGrayImageDownsampleThreshold=1.0 \
   -dAutoFilterColorImages=false -dColorImageFilter=/DCTEncode -dJPEGQ=92 \
   -dAutoFilterGrayImages=false -dGrayImageFilter=/DCTEncode \
   -sOutputFile=assets/BHUS26-Giovannoni-Scambuster-Slides.pdf original.pdf
```

If the deck is ever re-exported from a source that keeps live text, drop the
downsampling entirely — a vector deck would be both smaller and accessible.

## Enable GitHub Pages

1. Push these files to the branch you want to publish (this site lives on
   `web-site`; merge to `master`/`main` if you publish from the default branch).
2. In the repo: **Settings > Pages**.
3. **Source** = *Deploy from a branch*. Pick the branch and folder **/ (root)**.
4. Save. The first build takes a minute.

## Custom domain (scambuster.ai)

The `CNAME` file already contains exactly `scambuster.ai`, so GitHub picks up the
custom domain automatically. Then add DNS records at your registrar:

**Apex domain `scambuster.ai`** — four A records to GitHub Pages:

```
A   @   185.199.108.153
A   @   185.199.109.153
A   @   185.199.110.153
A   @   185.199.111.153
```

(Optional, IPv6 AAAA records if your registrar supports them:
`2606:50c0:8000::153`, `2606:50c0:8001::153`, `2606:50c0:8002::153`,
`2606:50c0:8003::153`.)

**`www` subdomain** — a CNAME to the GitHub Pages host:

```
CNAME   www   laugiov.github.io.
```

After DNS propagates, tick **Enforce HTTPS** in Settings > Pages.

## Local preview

`index.html` uses relative asset paths, so you can just double-click it to open
it in a browser. For a closer match to production (and to test `404.html`, which
uses root-relative paths), run a local server from the repo root:

```
python3 -m http.server 8000
```

Then open http://localhost:8000.

## Notes

- Black Hat USA 2026 is over, so the embargo is lifted. The three locked
  buttons are now live links: the demo (`demo.scambuster.ai`), the repository
  (`github.com/laugiov/scambuster`), and the slide deck (served from `assets/`).
  The `.embargo` / `.btn--locked` CSS was removed with them; button groups now
  use `.btnrow`.
- The Black Hat button points at the exact session URL on the us-26 schedule.
