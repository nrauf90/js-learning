# Mobile POS — design thinking

How the till should work on a phone, and why it is not the desktop screen made
narrower.

Status: proposal. Nothing here is built yet. The mobile app currently has splash,
login and a placeholder home (`mobile/`).

---

## 1. What we already have, and what has to be rewritten

This is the most important fact for planning, so it goes first.

| Module | Lines | DOM/network refs | Verdict |
|---|---|---|---|
| `js/cart.js` | 194 | **0** | **Reuse verbatim** — ticket lines, totals, discount clamp, whole-rupee rounding, change due |
| `js/units.js` | 171 | **0** | **Reuse verbatim** — base-unit conversion, per-gram pricing, weight↔rupee maths, quick chips |
| `js/pos.js` | 1,790 | throughout | **Rewrite** — this is the screen, and the screen is what changes |

`cart.js` and `units.js` were deliberately written free of DOM and network so the
arithmetic that decides what a customer is charged could be unit-tested. That
decision now pays a second time: **the entire money and measurement domain moves
to mobile untouched**, with the existing 70 unit tests still covering it.

So the mobile till is a UI problem, not a domain problem. That is a much smaller
and much safer piece of work than the 2,155-line total suggests.

---

## 2. The physical situation the screen is used in

Design constraints that a desktop POS never has to answer:

- **One hand.** The other is holding a scoop, a bag, the goods, or cash. The
  phone is in the left hand or flat on the counter.
- **Dirty fingers.** Flour, oil, ghee, water. Precision taps fail, and a
  fingertip on a greasy screen registers wider and lower than it looks.
- **A queue.** Speed beats every other consideration. A flow that takes two extra
  taps costs the shop real time at 5pm.
- **Constant interruption.** *"Bhai, anda kitne ka?"* arrives mid-ticket. There is
  no second screen to park the sale on.
- **Light.** Direct sun at a street-facing counter, or a single bulb after dark.
- **The device.** A cheap Android, 5–6", often with a cracked screen and a
  battery that will not survive an all-day animation loop.
- **The network.** Patchy, and worse inside a concrete shop.

None of this is hypothetical for a kiryana counter. All of it should visibly
shape the layout.

---

## 3. Why the desktop screen must not simply be made narrower

`pos.html` has **44 interactive controls** on one screen. Its model is:

> browse a grid of product tiles → tap one → it lands in a cart panel on the right

Three things break on a phone:

1. **The grid is the wrong primary.** 200 tiles at thumb-size means endless
   scrolling. And it solves a problem the shopkeeper does not have — *they know
   their own stock*. They are not browsing; they already know it is daal chana.
2. **Side-by-side does not collapse.** Tiles + cart panel stacked vertically means
   the ticket is off-screen while adding, and the tiles are off-screen while
   reviewing. Both halves become half-useful.
3. **Modal depth.** The weigh pad, the udhaar dialog and the close-day sheet are
   modals over a modal-ish layout. On a phone, two levels deep and the shopkeeper
   has lost the thread.

---

## 4. Proposed layout — read at the top, act at the bottom

The single most consequential decision. On a 6" phone held one-handed, the
comfortable thumb arc is roughly the **bottom 60%**. The desktop layout puts the
primary action top-right, which is the single hardest place to reach.

So the mobile till inverts it:

```
┌──────────────────────────────┐
│  Din khula · Rs 4,120 aaj    │ ← status strip, glance only
├──────────────────────────────┤
│                              │
│   Daal chana    250 g        │
│                  Rs 62.50    │ ← ticket, newest at the BOTTOM
│   Cooking oil     1 pc       │   (nearest the thumb and the eye)
│                  Rs 540.00   │   swipe a line to remove, with undo
│                              │
├──────────────────────────────┤
│  3 cheezein        Rs 1,180  │ ← total: big, readable at arm's length
├──────────────────────────────┤
│ [ Aata ] [ Daal ] [ Doodh ]  │ ← frequent items, one tap, no search
│ [ Chini ] [ Tel ] [ Anda  ]  │
├──────────────────────────────┤
│  🔍 Dhoondein…        [ 📷 ] │ ← search + camera scan
├──────────────────────────────┤
│   ┌──────────────────────┐   │
│   │   PAISE LEIN         │   │ ← primary action, full width, thumb arc
│   └──────────────────────┘   │
│   [ Udhaar ]   [ Rakh do ]   │ ← credit · park the ticket
└──────────────────────────────┘
```

Notes on why each band sits where it does:

- **Newest ticket line at the bottom**, not the top. It is the line most likely
  to be wrong, so it belongs where the eye and the thumb already are.
- **Total between the ticket and the controls** — it is the number being changed
  by the controls directly beneath it.
- **Udhaar keeps its own button**, exactly as on desktop. Taking goods on the
  book is a different act from taking money and must not be a mis-tap. That
  reasoning is even stronger on a touch screen.

---

## 5. Frequent items are the real speed win

A kiryana shop sells roughly the same 30 SKUs in most transactions. So the
default view should not be the catalogue at all — it should be **this shop's
own most-sold items**, as large tappable rows, ranked from its actual sales
history.

Done properly, the common sale needs **zero search**. This is worth more than
any animation on the screen.

Search stays, one tap away, for the tail. The catalogue grid — the desktop
primary — becomes the third-choice path.

---

## 6. Kill the mode switch on the weigh pad

The desktop weigh pad has two tabs: **By weight** and **By amount**. Switching
clears the pad, because the digits meant grams a moment ago and rupees now.

On a phone that tab is a mode error waiting to happen. The shopkeeper types `50`
meaning rupees while the pad is in grams, and 50 g of daal goes on the ticket
instead of Rs 50 of it. Nothing on screen looks wrong.

**Proposal: remove the mode. Show both readings at once.**

```
┌──────────────────────────────┐
│  Daal chana · Rs 250 / kg    │
│                              │
│            5 0               │
│                              │
│  ┌────────────────────────┐  │
│  │ 50 gram   =   Rs 12.50 │  │ ← tap this…
│  ├────────────────────────┤  │
│  │ Rs 50     =   200 gram │  │ ← …or this
│  └────────────────────────┘  │
│                              │
│  [pao] [adha] [3 pao] [1 kg] │
│                              │
│        7  8  9               │
│        4  5  6               │
│        1  2  3               │
│        .  0  ⌫               │
└──────────────────────────────┘
```

One number typed, both interpretations shown live, and the shopkeeper taps the
one they meant. The mode question disappears instead of being answered.

This is the single best mobile-specific improvement available, and it is not a
port of anything — it is better than the desktop screen.

---

## 7. Interaction rules

- **Targets ≥ 48px, primary ≥ 56px.** Greasy fingers register wide and low.
- **Add is instant. Remove is undoable.** Adding an item must never confirm;
  removing swipes with a 5-second undo toast rather than a dialog. Only actions
  that move money confirm.
- **Haptic on add.** A busy shop swallows sound, and the shopkeeper is looking at
  the scale, not the screen. A short vibration confirms the tap landed.
- **Big numerals.** The total should be legible with the phone flat on the
  counter — that is roughly 28–32px, not 16px.
- **No animation on the sell path.** Animation belongs on the landing page. Here
  it costs battery and delays the next tap. Reveals, parallax and gradient drift
  all stay out.
- **One sheet deep, never two.** Weigh, payment, udhaar and close-day are
  full-screen steps with an explicit back, not stacked modals.
- **The day gate stays hard**, as on desktop — but opening the day should be one
  tap with yesterday's float pre-filled.

---

## 8. Two blockers that are worse on mobile than on desktop

Both are already in `docs/audits/2026-08-11-pos-khata-audit.md`. Mobile raises
their priority.

### Held tickets — P1 on desktop, **P0 on mobile**
There is no way to park a ticket. On desktop a shopkeeper can leave it on screen
and use a second device. On a phone, an interruption means abandoning the ticket
or making the customer wait. Given how constant interruptions are at a counter,
the mobile till is not usable without this. The `[ Rakh do ]` button above is
that feature.

### Offline — already P0, and non-negotiable here
An installed app that cannot sell without signal is worse than the website,
because the user reasonably expects an app to work offline. `js/offline-db.js`
and `js/sync.js` are both written and imported by nothing; the server side is
finished and tested. **This should land before the mobile till, not after.**

---

## 9. Camera scanning — useful, but not the main path

A phone can scan barcodes and a desktop cannot, so it is worth having
(`@capacitor-mlkit/barcode-scanning`). But it should not be designed as the
primary input, because **loose goods have no barcode** — and loose goods are
half of what a kiryana sells. Scanning serves packaged stock only.

Frequent-items and search stay primary. Scan is a shortcut in the search bar.

---

## 10. Suggested order

1. **Offline + PWA wiring** — the prerequisite for any of this being worth
   installing (§8).
2. **Held tickets** — server-side concept plus UI; needed by the mobile flow.
3. **Sell screen** — the layout in §4, reusing `cart.js` and `units.js` as-is.
4. **The unified weigh pad** (§6) — ship it on mobile, then consider back-porting
   to desktop, since the mode error exists there too.
5. **Frequent items** — needs a "most sold" query the API does not have yet.
6. **Camera scan.**
7. **Udhaar step** — full-screen, recent khata customers as large rows, replacing
   the desktop combobox.

Steps 1 and 2 are backend-and-shared work. Step 3 onward is the mobile app
proper.

---

## 11. Open questions

- **Does the shop want the phone as the main till, or as a second terminal**
  alongside a laptop? A second terminal needs much less — mostly khata lookup
  and quick sales — and would reorder everything above.
- **Who holds the phone?** Owner or staff changes whether close-day and reports
  belong in the mobile app at all.
- **Bluetooth thermal printers.** Printing is `window.print()` only today, which
  is useless on a phone. If a printed slip matters on mobile, that is an ESC/POS
  integration and its own piece of work.
