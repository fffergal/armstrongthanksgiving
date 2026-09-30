# Block-editor content

The Home page is checked in as one ordinary block document at
`content/pages/home.html`. Its first block is a `core/html` block containing
page-specific CSS, followed by the editable hero and gathering-card blocks.
WordPress applies that inline `<style>` block in both the front end and the
editor iframe, so a human can edit layout and styling alongside the content.

The theme registers named block styles in `functions.php`: Display, Kicker,
Lead, and Secondary. They appear in the block editor’s Styles panel and
serialize as normal `is-style-*` classes. Their small reusable rules live in
the shared stylesheet. The homepage’s one-off date-card and card-link layout
classes, along with the hero and card layout, remain in the page-local CSS;
that is the CSS a human is most likely to tune for this page.

The homepage deliberately does not use code-backed patterns. This means a
manual edit is simply a manual edit to the checked-in page document, without a
pattern-expansion or live-sync boundary. It also means repeated markup is
acceptable where it keeps the source straightforward for humans to inspect and
change.
