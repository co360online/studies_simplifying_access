<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$title = $atts['title'];
$empty_message = $atts['empty_message'];
?>
<div class="co360-ssa-investigator-data">
	<h2 class="co360-ssa-title"><?php echo esc_html( $title ); ?></h2>

	<?php if ( ! $user_id ) : ?>
		<p class="co360-ssa-warning"><?php esc_html_e( 'Debes iniciar sesión', CO360_SSA_TEXT_DOMAIN ); ?></p>
		<p><a class="button" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Iniciar sesión', CO360_SSA_TEXT_DOMAIN ); ?></a></p>
	<?php elseif ( empty( $entries ) ) : ?>
		<p class="co360-ssa-empty"><?php echo esc_html( $empty_message ); ?></p>
	<?php else : ?>
		<?php if ( 'table' === $layout ) : ?>
			<table class="co360-ssa-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Estudio', CO360_SSA_TEXT_DOMAIN ); ?></th>
						<th><?php esc_html_e( 'Centro', CO360_SSA_TEXT_DOMAIN ); ?></th>
						<th><?php esc_html_e( 'Código investigador', CO360_SSA_TEXT_DOMAIN ); ?></th>
						<th><?php esc_html_e( 'Fecha', CO360_SSA_TEXT_DOMAIN ); ?></th>
						<?php if ( $show_enter_button ) : ?>
							<th><?php esc_html_e( 'Acceso', CO360_SSA_TEXT_DOMAIN ); ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( $entry['study_title'] ); ?></td>
							<td><?php echo esc_html( $entry['center_label'] ); ?></td>
							<td>
								<span class="co360-ssa-code"><?php echo esc_html( $entry['investigator_code'] ); ?></span>
								<?php if ( $show_copy && 'Pendiente' !== $entry['investigator_code'] ) : ?>
									<button type="button" class="co360-ssa-copy-button" data-code="<?php echo esc_attr( $entry['investigator_code'] ); ?>">
										<?php esc_html_e( 'Copiar', CO360_SSA_TEXT_DOMAIN ); ?>
									</button>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $entry['created_at'] ); ?></td>
							<?php if ( $show_enter_button ) : ?>
								<td>
									<?php if ( ! empty( $entry['study_url'] ) ) : ?>
										<a class="co360-ssa-enter-button" href="<?php echo esc_url( $entry['study_url'] ); ?>"><?php esc_html_e( 'Entrar', CO360_SSA_TEXT_DOMAIN ); ?></a>
									<?php else : ?>
										<span class="co360-ssa-muted">-</span>
									<?php endif; ?>
								</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<div class="co360-ssa-cards">
				<?php foreach ( $entries as $entry ) : ?>
					<div class="co360-ssa-card">
						<h3 class="co360-ssa-card-title"><?php echo esc_html( $entry['study_title'] ); ?></h3>
						<p class="co360-ssa-card-meta">
							<strong><?php esc_html_e( 'Centro', CO360_SSA_TEXT_DOMAIN ); ?>:</strong>
							<?php echo esc_html( $entry['center_label'] ); ?>
						</p>
						<p class="co360-ssa-card-meta">
							<strong><?php esc_html_e( 'Código investigador', CO360_SSA_TEXT_DOMAIN ); ?>:</strong>
							<span class="co360-ssa-code"><?php echo esc_html( $entry['investigator_code'] ); ?></span>
							<?php if ( $show_copy && 'Pendiente' !== $entry['investigator_code'] ) : ?>
								<button type="button" class="co360-ssa-copy-button" data-code="<?php echo esc_attr( $entry['investigator_code'] ); ?>">
									<?php esc_html_e( 'Copiar', CO360_SSA_TEXT_DOMAIN ); ?>
								</button>
							<?php endif; ?>
						</p>
						<p class="co360-ssa-card-meta">
							<strong><?php esc_html_e( 'Fecha', CO360_SSA_TEXT_DOMAIN ); ?>:</strong>
							<?php echo esc_html( $entry['created_at'] ); ?>
						</p>
						<?php if ( $show_enter_button && ! empty( $entry['study_url'] ) ) : ?>
							<a class="co360-ssa-enter-button" href="<?php echo esc_url( $entry['study_url'] ); ?>"><?php esc_html_e( 'Entrar', CO360_SSA_TEXT_DOMAIN ); ?></a>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>

<?php if ( $user_id && $show_copy && ! empty( $entries ) ) : ?>
<script>
document.addEventListener('click', function (event) {
  var target = event.target;
  if (!target || !target.classList.contains('co360-ssa-copy-button')) {
    return;
  }
  var code = target.getAttribute('data-code') || '';
  if (!code) {
    return;
  }
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(code);
    return;
  }
  var tempInput = document.createElement('input');
  tempInput.value = code;
  tempInput.setAttribute('readonly', 'readonly');
  tempInput.style.position = 'absolute';
  tempInput.style.left = '-9999px';
  document.body.appendChild(tempInput);
  tempInput.select();
  document.execCommand('copy');
  document.body.removeChild(tempInput);
});
</script>
<?php endif; ?>
