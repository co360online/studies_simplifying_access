<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$series_json = wp_json_encode( $series );
?>
<div class="co360-ssa-stats" data-series='<?php echo esc_attr( $series_json ); ?>' data-chart="<?php echo esc_attr( $chart ); ?>">
	<?php if ( $atts['show_totals'] ) : ?>
		<p class="co360-ssa-total">
			<?php echo esc_html( sprintf( __( 'Total inscripciones: %d', CO360_SSA_TEXT_DOMAIN ), $total ) ); ?>
		</p>
	<?php endif; ?>
	<?php if ( 'none' !== $chart ) : ?>
		<div class="co360-ssa-chart"></div>
	<?php endif; ?>
</div>
