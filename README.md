# Radiance Coaching — Website

Single-page site for Radiance Coaching (radiancecoaching.co.ke), rebuilt as a clean,
build-step-free static site with Pesapal payment integration for paid sessions.

## Structure

```
radiance-coaching/
├── index.html              # The entire site (nav, hero, services, about, contact form)
├── favicon.png
├── .htaccess                # Blocks directory listing / log access at site root
├── images/
│   ├── anne-kibet.jpeg
│   ├── life-coaching.jpg
│   ├── cert-life-coach.png
│   └── cert-grief-coaching.png
└── pay/
    ├── initiate.php         # Starts a Pesapal payment from the contact form
    ├── callback.php         # Pesapal redirects here after payment
    ├── ipn.php              # Background payment status updates from Pesapal
    ├── register-ipn.php     # One-time helper to register the IPN URL — delete after use
    └── .htaccess             # Blocks directory listing / log access in /pay
```

**Not included in this repo (must live one level above `public_html` on the server):**
`pesapal-config.php` — holds the Pesapal API credentials. Never commit this file or place
it anywhere web-accessible. Get it from whoever holds the Pesapal credentials and follow
the deployment steps below.

## Why no build step

The original version used a compiled Tailwind CSS file, which meant any new class added to
the HTML wouldn't render without re-running the Tailwind CLI. This version loads Tailwind
from a CDN (`cdn.tailwindcss.com`) instead — you can edit `index.html` directly and the
styling just works, no terminal commands needed. Trade-off: slightly slower first load than
a purpose-built CSS file, which is a reasonable trade for a small site edited by hand.

## Deploying (cPanel / shared hosting)

1. Upload everything in this repo into `public_html/`.
2. Upload `pesapal-config.php` (not in this repo — see above) to `/home/yourusername/`,
   **one level above** `public_html`. It must never be inside `public_html`.
3. In the Pesapal Merchant Dashboard, register the IPN URL:
   `https://radiancecoaching.co.ke/pay/ipn.php`, then paste the returned `ipn_id` into
   `pesapal-config.php`.
4. Test a payment in `sandbox` mode before switching `PESAPAL_ENV` to `production`.

## Known things to check before going live

- **Pricing:** `index.html` currently has all three paid services set to a flat KES 500
  (search for `SERVICE_PRICES` near the bottom of the file). Confirm the real rates with
  Anne and update both there and in `pay/initiate.php`.
- **Two stock photos:** the Grief & End of Life and Betrayal Trauma sections currently
  hotlink generic Unsplash stock images rather than real photos. Search `unsplash` in
  `index.html` to find and replace them when real photos are available.

## Editing content

Everything is in `index.html` — no templating, no build. Section comments
(`<!-- HERO -->`, `<!-- SERVICES -->`, `<!-- CONTACT -->`, etc.) mark each part of the page.
