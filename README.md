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
```

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

## Notes / TODO

- The Black Hat link points to the conference site. Swap it for the exact
  session URL once the schedule is live (see the `TODO` in `index.html`).
- The GitHub link points to the preview repo. Point it at the public framework
  repo if that differs.
