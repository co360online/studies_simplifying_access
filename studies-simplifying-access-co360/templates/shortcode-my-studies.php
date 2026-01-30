<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="co360-ssa-my-studies co360-ssa-my-studies--<?php echo esc_attr( $layout ); ?>">
	<?php if ( ! empty( $atts['title'] ) ) : ?>
		<h2><?php echo esc_html( $atts['title'] ); ?></h2>
	<?php endif; ?>

	<?php if ( ! $user || ! $user->ID ) : ?>
		<p class="co360-ssa-my-studies__message"><?php esc_html_e( 'Debes iniciar sesión para ver tus estudios.', CO360_SSA_TEXT_DOMAIN ); ?></p>
		<a class="button button-primary" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Ir al login', CO360_SSA_TEXT_DOMAIN ); ?></a>
	<?php elseif ( empty( $entries ) ) : ?>
		<p class="co360-ssa-my-studies__message"><?php esc_html_e( 'No tienes estudios inscritos todavía.', CO360_SSA_TEXT_DOMAIN ); ?></p>
	<?php else : ?>
		<ul class="co360-ssa-my-studies__list">
			<?php foreach ( $entries as $entry ) : ?>
				<li class="co360-ssa-my-studies__item<?php echo $entry['is_active'] ? '' : ' is-inactive'; ?>">
					<div class="co360-ssa-my-studies__info">
						<h3 class="co360-ssa-my-studies__title"><?php echo esc_html( $entry['title'] ); ?></h3>
						<?php if ( ! empty( $entry['date'] ) ) : ?>
							<p class="co360-ssa-my-studies__date">
								<?php echo esc_html( sprintf( __( 'Inscrito el %s', CO360_SSA_TEXT_DOMAIN ), $entry['date'] ) ); ?>
							</p>
						<?php endif; ?>
					</div>
					<?php if ( ! empty( $entry['url'] ) ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( $entry['url'] ); ?>">
							<?php esc_html_e( 'Entrar', CO360_SSA_TEXT_DOMAIN ); ?>
						</a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
