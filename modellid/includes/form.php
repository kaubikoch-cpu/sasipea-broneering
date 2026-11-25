<?php
/**
 * Avalik modelliks registreerumise vorm: [sam_modell_vorm]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['sam_form_state'] = array(
	'errors' => array(),
	'values' => array(),
);

add_action( 'wp_enqueue_scripts', 'sam_register_assets' );
function sam_register_assets() {
	wp_register_style( 'sam-style', SAM_URL . 'assets/style.css', array(), SB_VERSION );
	// Väike stiilifail; laeme alati, sest Elementori lehtedel ei saa lühikoodi ette tuvastada.
	wp_enqueue_style( 'sam-style' );
	wp_register_script( 'sam-app', SAM_URL . 'assets/app.js', array(), SB_VERSION, true );
}

add_action( 'template_redirect', 'sam_handle_public_form' );
function sam_handle_public_form() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['sam_public_form'] ) ) { // phpcs:ignore
		return;
	}
	$state  = &$GLOBALS['sam_form_state'];
	$post   = wp_unslash( $_POST ); // phpcs:ignore
	$values = array(
		'name'         => sanitize_text_field( $post['sam_name'] ?? '' ),
		'email'        => sanitize_email( $post['sam_email'] ?? '' ),
		'phone'        => sam_clean_phone( $post['sam_phone'] ?? '' ),
		'availability' => sanitize_text_field( $post['sam_availability'] ?? '' ),
		'info'         => sanitize_textarea_field( $post['sam_info'] ?? '' ),
		'terms'        => array(),
		'consent'      => ! empty( $post['sam_consent'] ),
	);
	foreach ( sam_taxonomies() as $tax => $cfg ) {
		if ( empty( $cfg['public'] ) ) {
			continue;
		}
		$values['terms'][ $tax ] = array_map( 'intval', (array) ( $post['sam_terms'][ $tax ] ?? array() ) );
	}
	$state['values'] = $values;

	$sec = sam_security_check();
	if ( true !== $sec ) {
		if ( 'spam' === $sec ) {
			// Botile vaikne "edu", et ta ei prooviks uuesti.
			wp_safe_redirect( add_query_arg( 'sam', 'ok', sam_current_url() ) . '#sam-vorm' );
			exit;
		}
		$state['errors'][] = $sec;
		return;
	}

	if ( '' === $values['name'] ) {
		$state['errors'][] = 'Palun sisesta oma nimi.';
	}
	if ( '' === $values['phone'] || strlen( preg_replace( '/\D/', '', $values['phone'] ) ) < 7 ) {
		$state['errors'][] = 'Palun sisesta korrektne telefoninumber.';
	}
	if ( '' !== $values['email'] && ! is_email( $values['email'] ) ) {
		$state['errors'][] = 'E-posti aadress ei ole korrektne.';
	}
	if ( empty( $values['terms']['sam_teenus'] ) ) {
		$state['errors'][] = 'Palun vali vähemalt üks teenus, milleks oled valmis.';
	}
	if ( ! $values['consent'] ) {
		$state['errors'][] = 'Palun nõustu andmete töötlemisega.';
	}
	$files = sam_files_array( 'sam_photos' );
	if ( count( $files ) > 3 ) {
		$state['errors'][] = 'Lisa kuni 3 pilti.';
	}
	foreach ( $files as $f ) {
		$v = sam_validate_photo( $f );
		if ( true !== $v ) {
			$state['errors'][] = $v;
			break;
		}
	}
	if ( $state['errors'] ) {
		return;
	}

	$id = sam_save_model(
		array(
			'name'         => $values['name'],
			'email'        => $values['email'],
			'phone'        => $values['phone'],
			'availability' => $values['availability'],
			'info'         => $values['info'],
			'status'       => 'uus',
			'terms'        => $values['terms'],
			'consent'      => time(),
			'last_contact' => time(),
			'source'       => 'vorm',
		)
	);
	if ( is_wp_error( $id ) ) {
		$state['errors'][] = 'Salvestamine ebaõnnestus. Palun proovi uuesti või helista meile.';
		return;
	}
	$photo_count = 0;
	foreach ( $files as $f ) {
		$r = sam_store_photo( $id, $f, 'modell' );
		if ( ! is_wp_error( $r ) ) {
			$photo_count++;
		}
	}

	sam_notify_new_model( $id, $photo_count );
	sam_confirm_new_model( $id );

	wp_safe_redirect( add_query_arg( 'sam', 'ok', remove_query_arg( 'sam', sam_current_url() ) ) . '#sam-vorm' );
	exit;
}

function sam_current_url() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
	return home_url( $uri );
}

function sam_notify_new_model( $id, $photo_count ) {
	$to = sam_setting( 'notify_email' );
	if ( ! $to || ! is_email( $to ) ) {
		return;
	}
	$m     = sam_get_model( $id );
	$lines = array(
		'Uus modell registreerus Sasipea Akadeemia kodulehel.',
		'',
		'Nimi: ' . $m['name'],
		'Telefon: ' . $m['phone'],
		'E-post: ' . ( $m['email'] ?: '–' ),
	);
	foreach ( sam_taxonomies() as $tax => $cfg ) {
		$names = wp_list_pluck( $m['terms'][ $tax ], 'name' );
		$lines[] = $cfg['label'] . ': ' . ( $names ? implode( ', ', $names ) : '–' );
	}
	$lines[] = 'Millal saab tulla: ' . ( $m['availability'] ?: '–' );
	$lines[] = 'Lisainfo: ' . ( $m['info'] ?: '–' );
	$lines[] = 'Pilte: ' . $photo_count;
	$lines[] = '';
	$lines[] = 'Vaata salongi töölaual: ' . sam_dashboard_url(
		array(
			'vaade' => 'modell',
			'id'    => $id,
		)
	);
	$headers = array();
	if ( $m['email'] ) {
		$headers[] = 'Reply-To: ' . $m['name'] . ' <' . $m['email'] . '>';
	}
	wp_mail( $to, 'Uus modell: ' . $m['name'], implode( "\n", $lines ), $headers );
}

function sam_confirm_new_model( $id ) {
	$m = sam_get_model( $id );
	if ( ! $m || empty( $m['email'] ) || ! is_email( $m['email'] ) ) {
		return;
	}
	$years = (int) sam_setting( 'retention_years' );
	if ( $years < 1 ) {
		$years = 2;
	}
	$lines = array(
		'Tere, ' . $m['name'] . '!',
		'',
		'Aitäh, et andsid endast märku. Lisasime sinu andmed Sasipea Akadeemia modellide andmebaasi.',
		'',
		'Kui meil on tulemas sinu soovile sobiv töö ja aeg, võtame sinuga ühendust telefoni või e-posti teel. Enne töö algust lepime alati kokku, mida täpselt teeme, ja ütleme hinna.',
		'',
		'Kui sinu andmed või soovid muutuvad, vasta sellele kirjale või helista 58588007.',
		'',
		'Sinu andmeid hoiame kuni ' . $years . ' aastat viimasest kontaktist.',
		'',
		'Kohtumiseni!',
		'Sasipea Akadeemia õppesalong',
		'Pikk 39, Tartu · 58588007 · info@oppesalong.ee',
	);
	$headers = array(
		'Content-Type: text/plain; charset=UTF-8',
		'From: Sasipea Akadeemia <wordpress@sasipeaakadeemia.ee>',
	);
	$reply = sam_setting( 'notify_email' );
	if ( $reply && is_email( $reply ) ) {
		$headers[] = 'Reply-To: Sasipea Akadeemia <' . $reply . '>';
	}
	wp_mail( $m['email'], 'Täname, et registreerusid Sasipea Akadeemia modelliks!', implode( "\n", $lines ), $headers );
}

add_shortcode( 'sam_modell_vorm', 'sam_render_public_form' );
function sam_render_public_form() {
	wp_enqueue_style( 'sam-style' );
	wp_enqueue_script( 'sam-app' );

	if ( isset( $_GET['sam'] ) && 'ok' === $_GET['sam'] ) { // phpcs:ignore
		return '<div id="sam-vorm" class="sam sam-form-wrap"><div class="sam-notice sam-ok"><strong>Aitäh, said modelliks kirja!</strong><br>Võtame sinuga ühendust, kui meil on sulle sobiv aeg ja teenus.</div></div>';
	}

	$state  = $GLOBALS['sam_form_state'];
	$v      = $state['values'];
	$val    = function ( $k ) use ( $v ) {
		return isset( $v[ $k ] ) ? $v[ $k ] : '';
	};
	$picked = function ( $tax, $tid ) use ( $v ) {
		return ! empty( $v['terms'][ $tax ] ) && in_array( (int) $tid, $v['terms'][ $tax ], true );
	};

	ob_start();
	?>
	<div id="sam-vorm" class="sam sam-form-wrap">
		<?php if ( $state['errors'] ) : ?>
			<div class="sam-notice sam-err" role="alert">
				<?php foreach ( $state['errors'] as $e ) : ?>
					<div><?php echo esc_html( $e ); ?></div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<form class="sam-form" method="post" enctype="multipart/form-data" action="#sam-vorm" novalidate>
			<input type="hidden" name="sam_public_form" value="1">

			<div class="sam-field">
				<label for="sam_name">Nimi <span class="sam-req">*</span></label>
				<input type="text" id="sam_name" name="sam_name" required autocomplete="name" value="<?php echo esc_attr( $val( 'name' ) ); ?>">
			</div>
			<div class="sam-row">
				<div class="sam-field">
					<label for="sam_phone">Telefon <span class="sam-req">*</span></label>
					<input type="tel" id="sam_phone" name="sam_phone" required autocomplete="tel" value="<?php echo esc_attr( $val( 'phone' ) ); ?>">
				</div>
				<div class="sam-field">
					<label for="sam_email">E-post</label>
					<input type="email" id="sam_email" name="sam_email" autocomplete="email" value="<?php echo esc_attr( $val( 'email' ) ); ?>">
				</div>
			</div>

			<?php foreach ( sam_taxonomies() as $tax => $cfg ) : ?>
				<?php
				if ( empty( $cfg['public'] ) ) {
					continue;
				}
				$terms = sam_terms( $tax );
				if ( ! $terms ) {
					continue;
				}
				$type = $cfg['multi'] ? 'checkbox' : 'radio';
				$req  = 'sam_teenus' === $tax;
				?>
				<fieldset class="sam-field sam-chips">
					<legend><?php echo esc_html( $cfg['ask'] ); ?><?php echo $req ? ' <span class="sam-req">*</span>' : ''; ?></legend>
					<div class="sam-chip-list">
						<?php foreach ( $terms as $t ) : ?>
							<label class="sam-chip">
								<input type="<?php echo esc_attr( $type ); ?>" name="sam_terms[<?php echo esc_attr( $tax ); ?>][]" value="<?php echo (int) $t->term_id; ?>" <?php checked( $picked( $tax, $t->term_id ) ); ?>>
								<span><?php echo esc_html( $t->name ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</fieldset>
			<?php endforeach; ?>

			<div class="sam-field">
				<label for="sam_availability">Millal saad tulla?</label>
				<input type="text" id="sam_availability" name="sam_availability" placeholder="nt teisipäeviti pärastlõunal" value="<?php echo esc_attr( $val( 'availability' ) ); ?>">
			</div>
			<div class="sam-field">
				<label for="sam_info">Lisainfo</label>
				<textarea id="sam_info" name="sam_info" rows="4" placeholder="Varasem värv, soovid jms"><?php echo esc_textarea( $val( 'info' ) ); ?></textarea>
			</div>

			<div class="sam-field">
				<label for="sam_photos">Pilt sinu juustest (valikuline, kuni 3)</label>
				<input type="file" id="sam_photos" name="sam_photos[]" accept="image/jpeg,image/png,image/webp" multiple data-max="3">
				<small class="sam-help">Päevavalges tehtud pilt eest ja tagant aitab meil sobiva teenuse valida. JPG, PNG või WEBP, kuni 12 MB.</small>
				<div class="sam-preview" data-for="sam_photos"></div>
			</div>

			<label class="sam-consent">
				<input type="checkbox" name="sam_consent" value="1" <?php checked( ! empty( $v['consent'] ) ); ?> required>
				<span>Nõustun, et Sasipea Akadeemia kasutab minu andmeid ja pilte modelliks sobivuse hindamiseks ning minuga ühenduse võtmiseks. Andmeid hoitakse kuni <?php echo (int) sam_setting( 'retention_years' ); ?> aastat viimasest kontaktist, mille järel need kustutatakse.<?php
				$pp = get_privacy_policy_url();
				if ( $pp ) {
					echo ' <a href="' . esc_url( $pp ) . '" target="_blank" rel="noopener">Privaatsuspoliitika</a>.';
				}
				?> <span class="sam-req">*</span></span>
			</label>

			<?php echo sam_security_fields(); // phpcs:ignore ?>

			<button type="submit" class="sam-btn sam-btn-primary">Registreeru modelliks</button>
		</form>
	</div>
	<?php
	return ob_get_clean();
}
