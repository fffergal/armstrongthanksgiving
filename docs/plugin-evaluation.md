# Plugin evaluation

No plugin template or stylesheet is edited in place. Overrides live in the custom theme so updates remain possible.

| Concern | Candidate | Theming surface | Acceptance gate |
| --- | --- | --- | --- |
| Forum | bbPress | Theme template hierarchy, copied template parts, CSS classes and hooks | Forum index, topic, reply form, profile and email subscription flows at desktop/mobile widths |
| Albums | WP Photo Album Plus | Plugin display settings, shortcodes and theme-level custom CSS | Mobile multi-image upload, album ownership, captions, lightbox, thumbnail stability and original-file privacy |
| Passwordless login | Magic Login | Block, shortcode attributes, filters and theme CSS | Request, unknown email, expired link, successful login and redirect states |
| Whole-site privacy | My Private Site | Custom login-page redirect and block-theme compatibility mode | Anonymous checks across HTML, feeds, REST, sitemaps, search, attachments, originals and thumbnails |

WP Photo Album Plus is the least structurally overrideable of the candidates, but its stable selectors and CSS controls make it viable. It remains provisional until the representative gallery and upload flows pass visual regression tests.

## Findings from the first integration run

- My Private Site 4.2.3 must use its `ELEMENTOR` compatibility value (labelled Theme Fix in the UI) with this block theme. Standard mode returned an empty HTTP 200 instead of redirecting; the privacy test caught this and the fixture now records the working mode.
- WP Photo Album Plus currently enqueues gallery assets on the WordPress login screen. This is unnecessary transfer and strengthens the case for keeping the album choice provisional until authenticated album flows and production-like performance are measured.
- bbPress exposes full theme template overrides. Magic Login exposes a block, shortcode attributes and filters. Neither requires modifying vendor files.

## Update policy

Plugin updates are first applied locally. The complete acceptance suite and privacy probes must pass before the same pinned version is deployed. Major upgrades are reviewed separately and never automatically promoted.
