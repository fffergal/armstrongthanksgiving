<?php
/**
 * Classic fallback used by bbPress for forum and topic routes.
 *
 * The rest of this project is a block theme, but bbPress still asks the
 * active theme for a PHP template on single topic pages. Keeping this small
 * bridge here lets those routes use the same header, footer, and card styles.
 */
if (!defined('ABSPATH')) {
    exit;
}
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php
wp_body_open();
echo do_blocks('<!-- wp:template-part {"slug":"header"} /-->');
?>
<main class="at-main at-shell">
  <header class="at-page-header">
    <h1><?php echo esc_html(function_exists('bbp_get_topic_title') ? bbp_get_topic_title(get_queried_object_id()) : get_the_title()); ?></h1>
  </header>
  <div class="at-content-card">
    <?php
    if (function_exists('bbp_is_single_topic') && bbp_is_single_topic()) {
        echo do_shortcode('[bbp-single-topic id="' . absint(get_queried_object_id()) . '"]');
    } else {
        the_content();
    }
    ?>
  </div>
</main>
<?php
echo do_blocks('<!-- wp:template-part {"slug":"footer"} /-->');
wp_footer();
?>
</body>
</html>
