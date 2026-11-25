<?php
/**
 * Admini seaded (Modellid → Seaded) ja import Fluent Formsi modellivormist.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'sam_admin_menu' );
function sam_admin_menu() {
	add_submenu_page( 'edit.php?post_type=' . SAM_CPT, 'Modellide seaded', 'Seaded', 'manage_options', 'sam-settings', 'sam_render_settings' );
}

add_action( 'admin_post_sam_save_settings', 'sam_save_settings' );
function sam_save_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Ligipääs puudub.' );
	}
	check_admin_referer( 'sam_settings' );
	$p = wp_unslash( $_POST ); // phpcs:ignore
	sam_update_setting( 'notify_email', sanitize_email( $p['notify_email'] ?? '' ) );
	sam_update_setting( 'retention_years', max( 1, min( 10, (int) ( $p['retention_years'] ?? 2 ) ) ) );
	sam_update_setting( 'practicum_form', (int) ( $p['practicum_form'] ?? 0 ) );
	wp_safe_redirect( admin_url( 'edit.php?post_type=' . SAM_CPT . '&page=sam-settings&saved=1' ) );
	exit;
}

add_action( 'admin_post_sam_import_ff', 'sam_import_ff' );
function sam_import_ff() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Ligipääs puudub.' );
	}
	check_admin_referer( 'sam_import' );
	$form_id = (int) ( $_POST['ff_form'] ?? 0 ); // phpcs:ignore
	$count   = 0;
	if ( $form_id ) {
		$done = (array) get_option( 'sam_imported_ff', array() );
		foreach ( sam_ff_get_submissions( $form_id, 1000 ) as $s ) {
			if ( in_array( (int) $s->id, $done, true ) ) {
				continue;
			}
			$r     = json_decode( $s->response, true );
			$name  = sam_ff_value_to_text( $r['nimi'] ?? ( $r['names'] ?? '' ) );
			$terms = array();
			$svc   = sam_ff_value_to_text( $r['teenus'] ?? '' );
			if ( $svc ) {
				$map = array(
					'Lõikus'            => 'Lõikus',
					'Värvimine'         => 'Värvimine',
					'Salgud / heledaks' => 'Triibutamine',
					'Soeng'             => 'Soeng',
					'Hooldus'           => 'Hooldus',
				);
				foreach ( $map as $from => $to ) {
					if ( false !== mb_stripos( $svc, $from ) ) {
						$t = get_term_by( 'name', $to, 'sam_teenus' );
						if ( $t ) {
							$terms['sam_teenus'][] = $t->term_id;
						}
					}
				}
			}
			$ts = strtotime( $s->created_at );
			$id = sam_save_model(
				array(
					'name'         => $name ? $name : 'Nimetu (#' . $s->id . ')',
					'email'        => sam_ff_value_to_text( $r['email'] ?? '' ),
					'phone'        => sam_ff_value_to_text( $r['telefon'] ?? '' ),
					'availability' => sam_ff_value_to_text( $r['aeg'] ?? '' ),
					'info'         => trim( sam_ff_value_to_text( $r['lisainfo'] ?? '' ) . ( $svc ? "\nVormis valitud teenus: " . $svc : '' ) ),
					'status'       => 'uus',
					'terms'        => $terms,
					'consent'      => $ts,
					'last_contact' => $ts,
					'source'       => 'vorm',
				)
			);
			if ( ! is_wp_error( $id ) ) {
				wp_update_post(
					array(
						'ID'            => $id,
						'post_date'     => $s->created_at,
						'post_date_gmt' => get_gmt_from_date( $s->created_at ),
					)
				);
				$done[] = (int) $s->id;
				$count++;
			}
		}
		update_option( 'sam_imported_ff', $done, false );
	}
	wp_safe_redirect( admin_url( 'edit.php?post_type=' . SAM_CPT . '&page=sam-settings&imported=' . $count ) );
	exit;
}

function sam_render_settings() {
	$s     = sam_settings();
	$forms = sam_ff_forms();
	$keys  = sam_turnstile_keys();
	?>
	<div class="wrap">
		<h1>Modellide seaded</h1>
		<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore ?>
			<div class="notice notice-success"><p>Seaded salvestatud.</p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['imported'] ) ) : // phpcs:ignore ?>
			<div class="notice notice-success"><p>Imporditud modelle: <?php echo (int) $_GET['imported']; // phpcs:ignore ?></p></div>
		<?php endif; ?>

		<h2>Kokkuvõte</h2>
		<ul>
			<li>Modellide andmebaas on osa broneerimissüsteemist ja asub salongi töölaual: <a href="<?php echo esc_url( sam_dashboard_url() ); ?>" target="_blank"><?php echo esc_html( sam_dashboard_url() ); ?></a></li>
			<li>Modelli registreerumisvorm: lühikood <code>[sam_modell_vorm]</code></li>
			<li>Turnstile: <?php echo $keys ? '<strong style="color:#008a20">kasutusel</strong> (võtmed Fluent Formsi seadetest)' : '<strong style="color:#b32d2e">võtmeid ei leitud</strong> — vorm kasutab ainult meepotti ja ajakontrolli'; ?></li>
			<li>Kasutajad loob omanik salongi töölaual vaates Kasutajad.</li>
			<li>Kategooriate muutmine: menüü Modellid → Sugu / Juuksevärvid / Juuste pikkused / Teenused.</li>
		</ul>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="sam_save_settings">
			<?php wp_nonce_field( 'sam_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="notify_email">Teavituse e-post</label></th>
					<td><input type="email" id="notify_email" name="notify_email" class="regular-text" value="<?php echo esc_attr( $s['notify_email'] ); ?>">
					<p class="description">Siia saadetakse teade iga uue modelli registreerumisel.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="retention_years">Andmete säilitamine (aastat)</label></th>
					<td><input type="number" min="1" max="10" id="retention_years" name="retention_years" value="<?php echo (int) $s['retention_years']; ?>">
					<p class="description">Modell ja tema pildid kustutatakse automaatselt, kui viimasest kontaktist on möödas nii palju aastaid.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="practicum_form">Juuksuriõpilaste avalduste vorm</label></th>
					<td><select id="practicum_form" name="practicum_form">
						<option value="0">— vali —</option>
						<?php foreach ( $forms as $f ) : ?>
							<option value="<?php echo (int) $f->id; ?>" <?php selected( (int) $s['practicum_form'], (int) $f->id ); ?>><?php echo esc_html( $f->title . ' (#' . $f->id . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">Fluent Formsi vorm, mille avaldusi juhendajad töölaual näevad.</p></td>
				</tr>
			</table>
			<?php submit_button( 'Salvesta seaded' ); ?>
		</form>

		<?php if ( $forms ) : ?>
			<hr>
			<h2>Impordi modellid Fluent Formsist</h2>
			<p>Toob vana modellivormi („Modelliks registreerumine“) postitused andmebaasi. Juba imporditud postitusi uuesti ei tooda.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sam_import_ff">
				<?php wp_nonce_field( 'sam_import' ); ?>
				<select name="ff_form">
					<?php foreach ( $forms as $f ) : ?>
						<option value="<?php echo (int) $f->id; ?>" <?php selected( false !== stripos( $f->title, 'modell' ) ); ?>><?php echo esc_html( $f->title . ' (#' . $f->id . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( 'Impordi', 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

/* Modelli muutmise ekraanil wp-adminis: viide töölauale. */
add_action( 'add_meta_boxes_' . SAM_CPT, 'sam_meta_box' );
function sam_meta_box() {
	add_meta_box(
		'sam_info',
		'Modelli andmed',
		function ( $post ) {
			$m = sam_get_model( $post );
			if ( ! $m ) {
				echo '<p>Uue modelli lisamiseks kasuta salongi töölauda.</p>';
				return;
			}
			echo '<p><strong>Telefon:</strong> ' . esc_html( $m['phone'] ) . '<br><strong>E-post:</strong> ' . esc_html( $m['email'] ) . '<br><strong>Pilte:</strong> ' . count( $m['photos'] ) . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url(
				sam_dashboard_url(
					array(
						'vaade' => 'modell',
						'id'    => $m['id'],
					)
				)
			) . '">Ava salongi töölaual</a></p>';
		},
		SAM_CPT,
		'normal',
		'high'
	);
}
