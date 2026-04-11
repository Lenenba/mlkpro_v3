# Email Sender Logo / BIMI Setup

This document prepares the repo side for showing a Malikia Pro logo next to outgoing emails in supporting inboxes such as Gmail.

Important distinction:

- The logo inside the email body is already handled by the Blade email layout.
- The logo shown in the inbox sender avatar is not controlled by the email HTML alone.
- That inbox avatar is generally driven by `SPF`, `DKIM`, `DMARC`, and `BIMI`, with Gmail often requiring a `VMC` or `CMC`.

## Repo Assets Prepared

- BIMI candidate logo: `public/brand/bimi-logo.svg`
- Email layout: `resources/views/emails/layouts/base.blade.php`
- Mail config: `config/mail.php`

The BIMI logo is a simple square mark prepared for inbox/avatar usage. It is intentionally much simpler than the full email wordmark because inbox logos are rendered at very small sizes.

## Important Legal / Certificate Note

The prepared SVG is a strong technical starting point, but for Gmail-style BIMI display you may need:

- a logo that matches the brand mark you are legally allowed to validate
- a `VMC` or `CMC` issued for that mark

So treat `public/brand/bimi-logo.svg` as the prepared deployment asset or a draft to finalize with the brand/certificate team if the certificate issuer asks for a stricter trademark match or SVG normalization.

## App-Side Requirements

Before touching DNS, make sure the visible sender domain matches the domain you want to brand with BIMI.

### 1. Confirm the visible `From:` domain

Laravel uses:

- `config/mail.php`
- `MAIL_FROM_ADDRESS`
- `MAIL_FROM_NAME`

Example:

```env
MAIL_FROM_NAME="Malikia Pro"
MAIL_FROM_ADDRESS="hello@malikiapro.com"
```

Best practice:

- use a stable branded sender address
- avoid mixing multiple unrelated sender domains
- use the same organizational domain for the visible `From:` that you will publish `DMARC` and `BIMI` on

## DNS / Provider Checklist

You said you will handle the client DNS side, so this is the checklist to execute there.

### 1. SPF

Authorise the actual email sender/provider.

Example only:

```txt
malikiapro.com  TXT  "v=spf1 include:mailgun.org ~all"
```

Replace `include:mailgun.org` with the real provider used by the mailbox service.

### 2. DKIM

Configure DKIM with the real mail provider and ensure the signature aligns with the visible `From:` domain.

This is provider-specific, so use the exact CNAME/TXT values from the provider dashboard.

### 3. DMARC

For BIMI, `DMARC` must normally be in enforcement, not monitoring only.

Recommended starting point:

```txt
_dmarc.malikiapro.com  TXT  "v=DMARC1; p=quarantine; pct=100; rua=mailto:dmarc@malikiapro.com; adkim=s; aspf=s"
```

Later, if the domain is healthy, many teams move to:

```txt
_dmarc.malikiapro.com  TXT  "v=DMARC1; p=reject; pct=100; rua=mailto:dmarc@malikiapro.com; adkim=s; aspf=s"
```

### 4. Host the BIMI SVG

Host the prepared SVG at a stable HTTPS URL, for example:

```text
https://malikiapro.com/brand/bimi-logo.svg
```

If you deploy this repo as-is and expose `/public`, the file path will be:

```text
/brand/bimi-logo.svg
```

Requirements:

- public HTTPS URL
- no auth wall
- no hotlink blocking
- correct MIME type: `image/svg+xml`
- stable path

### 5. Publish the BIMI record

Example:

```txt
default._bimi.malikiapro.com  TXT  "v=BIMI1; l=https://malikiapro.com/brand/bimi-logo.svg"
```

If you receive a `VMC` or `CMC`, add the certificate URL:

```txt
default._bimi.malikiapro.com  TXT  "v=BIMI1; l=https://malikiapro.com/brand/bimi-logo.svg; a=https://malikiapro.com/brand/bimi-cert.pem"
```

## Gmail / Apple Expectation

Even with everything technically correct:

- logo display is provider-specific
- inbox UI may cache results
- Gmail commonly expects a valid mark certificate for broad logo display
- reputation and authentication alignment still matter

So the flow is usually:

1. fix sender domain alignment
2. publish SPF / DKIM / DMARC
3. host the SVG
4. publish BIMI
5. add VMC or CMC if needed for Gmail-style display
6. send fresh test emails and wait for provider refresh

## Validation Checklist

Before testing in Gmail:

- `MAIL_FROM_ADDRESS` uses the branded domain
- SPF passes
- DKIM passes
- DMARC passes with alignment
- DMARC policy is `quarantine` or `reject`
- `https://your-domain/brand/bimi-logo.svg` opens publicly
- the server returns `Content-Type: image/svg+xml`
- the BIMI TXT record resolves correctly

## Suggested Test Flow

1. Send a fresh email from production or staging using the real sender domain.
2. Check headers in Gmail:
   - SPF: pass
   - DKIM: pass
   - DMARC: pass
3. Verify the BIMI record with an external BIMI checker.
4. If Gmail still shows no logo, check:
   - certificate missing
   - wrong visible `From:` domain
   - DKIM not aligned
   - DMARC not enforced
   - SVG not publicly retrievable
   - provider caching / reputation delays

## References

- Google Workspace Admin Help: BIMI
  - https://support.google.com/a/answer/10911320
- Google Workspace Blog: Bringing BIMI to Gmail
  - https://workspace.google.com/blog/product-announcements/bringing-bimi-to-gmail-in-google-workspace
- BIMI Group FAQ for senders / ESPs
  - https://bimigroup.org/faqs-for-senders-esps/
