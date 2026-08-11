# Brand source assets (native icons + splash)

Drop the new logo files here and run:

```bash
npm run mobile:assets   # generates every Android + iOS icon and splash size
npm run mobile:sync     # copies them into android/ and ios/
```

`@capacitor/assets` reads this folder and writes the ~40 sized variants each
platform wants (mipmap densities, adaptive icons, launch storyboards). Nothing
in `android/` or `ios/` should be edited by hand — it is regenerated.

## What to put here

| File | Size | Notes |
|---|---|---|
| `icon.png` | **1024×1024** | The app icon. Square, no rounded corners and no transparency — both platforms apply their own mask, and a pre-rounded icon gets clipped twice. |
| `icon-foreground.png` | 1024×1024 | *Optional.* Android adaptive-icon foreground. Keep the mark inside the **middle 66%** — the outer third is cropped on round/squircle launchers. |
| `icon-background.png` | 1024×1024 | *Optional.* Flat colour or simple texture behind the foreground. |
| `splash.png` | **2732×2732** | Light-theme splash. Centre the logo in roughly the middle **40%**: this one square is centre-cropped to every aspect ratio from a tall phone to a landscape tablet, so anything near an edge is cut. |
| `splash-dark.png` | 2732×2732 | Dark-theme splash. |

PNG throughout. Transparency is fine on the splash files (the background colour
below shows through), but not on `icon.png`.

## Why these sizes and not the web logo

`mobile/assets/logo.png` is the in-app lockup — currently **277×87**. That is
fine rendered in the DOM at 4.5rem, and far too small to upscale into a 1024px
icon or a 2732px splash. These need to come from vector source, exported at the
sizes above.

Note the shape mismatch too: the lockup is **3.18:1 wide** (mark + wordmark +
tagline) while both files here are **square**. Downscaled into a 1024px square
the wordmark would occupy a ~320px band with two-thirds of the icon empty, and
the tagline would be illegible well before that. Expect to supply a **mark-only**
variant (the PK monogram, or the monogram over the flame) for `icon.png`, and
keep the full lockup for `splash.png` where the width is available.

## Background colour

`#071a3d` — set in three places that must agree, or the splash flashes a
different colour as it hands over:

1. `capacitor.config.json` → `plugins.SplashScreen.backgroundColor`
2. `mobile/css/mobile.css` → `--splash-bg`
3. the `--splashBackgroundColor` flags in the `mobile:assets` script

Change it in all three together.

## Web favicon

Separate from this folder. The web app uses `favicon.svg` at the repo root, and
the mobile screens use `mobile/assets/favicon.svg`. Both are currently the
generated PK monogram tile; replace both if a new favicon arrives.
