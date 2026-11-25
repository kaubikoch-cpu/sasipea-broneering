<?php
/**
 * Modellide vaated salongi töölaual (/salong/): andmebaas filtritega, modelli kaart, piltide lisamine
 * ja juuksuriõpilaseks soovijate avaldused. Sisselogimine, menüü ja konto on töölaua enda osad.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sam_messages() {
	return array(
		'saved'      => 'Muudatused salvestatud.',
		'added'      => 'Modell lisatud.',
		'photos'     => 'Pildid lisatud.',
		'photo_del'  => 'Pilt kustutatud.',
		'contacted'  => 'Märgitud: täna oldi ühenduses.',
		'deleted'    => 'Modell kustutatud.',
		'read'       => 'Avaldus märgitud loetuks.',
		'unread'     => 'Avaldus märgitud lugemata.',
		'account'    => 'Konto andmed salvestatud.',
		'password'   => 'Parool vahetatud. Kinnituse saatsime ka e-postile.',
	);
}

/* ------------------------------------------------------------------ */
/* POST-tegevused                                                      */
/* ------------------------------------------------------------------ */

add_action( 'template_redirect', 'sam_handle_dashboard_post', -5 );
function sam_handle_dashboard_post() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['sam_dash_action'] ) ) { // phpcs:ignore
		return;
	}
	if ( ! is_user_logged_in() || ! current_user_can( SAM_CAP ) ) {
		wp_die( 'Ligipääs puudub.', 403 );
	}
	check_admin_referer( 'sam_dash', 'sam_nonce' );

	$post   = wp_unslash( $_POST ); // phpcs:ignore
	$action = sanitize_key( $post['sam_dash_action'] );
	$id     = isset( $post['model_id'] ) ? (int) $post['model_id'] : 0;

	if ( $id && SAM_CPT !== get_post_type( $id ) ) {
		wp_die( 'Modellit ei leitud.', 404 );
	}

	$back = function ( $args ) {
		wp_safe_redirect( sam_dashboard_url( $args ) );
		exit;
	};

	switch ( $action ) {
		case 'save_model':
		case 'add_model':
			$data = array(
				'name'         => $post['name'] ?? '',
				'email'        => $post['email'] ?? '',
				'phone'        => $post['phone'] ?? '',
				'availability' => $post['availability'] ?? '',
				'info'         => $post['info'] ?? '',
				'notes'        => $post['notes'] ?? '',
				'status'       => $post['status'] ?? 'aktiivne',
				'terms'        => isset( $post['terms'] ) ? (array) $post['terms'] : array(),
			);
			if ( 'add_model' === $action ) {
				if ( empty( $post['consent'] ) ) {
					$GLOBALS['sam_dash_error'] = 'Märgi, et modell andis nõusoleku andmete ja piltide hoidmiseks.';
					$GLOBALS['sam_dash_values'] = $data;
					return;
				}
				$data['consent']      = time();
				$data['last_contact'] = time();
				$data['source']       = 'salong';
				$id                   = 0;
			}
			$res = sam_save_model( $data, $id );
			if ( is_wp_error( $res ) ) {
				$GLOBALS['sam_dash_error']  = $res->get_error_message();
				$GLOBALS['sam_dash_values'] = $data;
				return;
			}
			$errors = sam_dash_store_uploads( $res );
			$args   = array(
				'vaade' => 'modell',
				'id'    => $res,
				'msg'   => 'add_model' === $action ? 'added' : 'saved',
			);
			if ( $errors ) {
				$args['perr'] = rawurlencode( implode( ' ', array_unique( $errors ) ) );
			}
			$back( $args );
			break;

		case 'upload_photos':
			$errors = sam_dash_store_uploads( $id );
			$args   = array(
				'vaade' => 'modell',
				'id'    => $id,
				'msg'   => 'photos',
			);
			if ( $errors ) {
				$args['perr'] = rawurlencode( implode( ' ', array_unique( $errors ) ) );
				unset( $args['msg'] );
			}
			$back( $args );
			break;

		case 'delete_photo':
			sam_delete_photo( $id, sanitize_key( $post['photo'] ?? '' ) );
			$back(
				array(
					'vaade' => 'modell',
					'id'    => $id,
					'msg'   => 'photo_del',
				)
			);
			break;

		case 'contacted':
			update_post_meta( $id, '_sam_last_contact', time() );
			if ( 'uus' === get_post_meta( $id, '_sam_status', true ) ) {
				update_post_meta( $id, '_sam_status', 'aktiivne' );
			}
			$back(
				array(
					'vaade' => 'modell',
					'id'    => $id,
					'msg'   => 'contacted',
				)
			);
			break;

		case 'delete_model':
			sam_delete_model( $id );
			$back( array( 'msg' => 'deleted' ) );
			break;

		case 'app_status':
			$sid    = (int) ( $post['submission_id'] ?? 0 );
			$status = 'unread' === ( $post['status'] ?? '' ) ? 'unread' : 'read';
			sam_ff_set_status( $sid, $status );
			$back(
				array(
					'vaade' => 'praktikandid',
					'msg'   => $status,
				)
			);
			break;
	}
}

/** Salvestab töölaual valitud pildid (väli "photos[]"). Tagastab veateated. */
function sam_dash_store_uploads( $model_id ) {
	$errors = array();
	foreach ( sam_files_array( 'photos' ) as $f ) {
		$r = sam_store_photo( $model_id, $f, 'salong' );
		if ( is_wp_error( $r ) ) {
			$errors[] = $r->get_error_message();
		}
	}
	return $errors;
}

/* ------------------------------------------------------------------ */
/* Renderdamine                                                        */
/* ------------------------------------------------------------------ */

// Vana juhendaja lehe lühikood viib nüüd salongi töölauale.
add_shortcode( 'sam_juhendaja', 'sam_render_dashboard' );
function sam_render_dashboard() {
	return '<p><a href="' . esc_url( sam_dashboard_url() ) . '">Modellide andmebaas asub nüüd salongi töölaual. Ava töölaud</a></p>';
}

/** Üks modellide vaade töölaua sisus. $view on vana plugina vaate nimi. */
function sam_render_view( $view ) {
	echo '<div class="wrap sam sam-dash">';
	sam_render_flash();
	switch ( $view ) {
		case 'modell':
			sam_render_model_view( (int) ( $_GET['id'] ?? 0 ) ); // phpcs:ignore
			break;
		case 'lisa':
			sam_render_model_form( null );
			break;
		case 'praktikandid':
			sam_render_applications();
			break;
		case 'avaldus':
			sam_render_application( (int) ( $_GET['id'] ?? 0 ) ); // phpcs:ignore
			break;
		default:
			sam_render_model_list();
	}
	echo '</div>';
}

/** Lugemata avalduste arv menüü jaoks. */
function sam_unread_applications() {
	$n   = 0;
	$fid = (int) sam_setting( 'practicum_form' );
	if ( $fid ) {
		foreach ( sam_ff_get_submissions( $fid, 200 ) as $s ) {
			if ( 'unread' === $s->status ) {
				$n++;
			}
		}
	}
	return $n;
}

function sam_render_flash() {
	$msgs = sam_messages();
	$msg  = isset( $_GET['msg'] ) ? sanitize_key( $_GET['msg'] ) : ''; // phpcs:ignore
	if ( $msg && isset( $msgs[ $msg ] ) && empty( $GLOBALS['sam_dash_error'] ) ) {
		echo '<div class="sam-notice sam-ok">' . esc_html( $msgs[ $msg ] ) . '</div>';
	}
	if ( ! empty( $_GET['perr'] ) ) { // phpcs:ignore
		echo '<div class="sam-notice sam-err">' . esc_html( sanitize_text_field( rawurldecode( wp_unslash( $_GET['perr'] ) ) ) ) . '</div>'; // phpcs:ignore
	}
	if ( ! empty( $GLOBALS['sam_dash_error'] ) ) {
		echo '<div class="sam-notice sam-err">' . esc_html( $GLOBALS['sam_dash_error'] ) . '</div>';
	}
}

function sam_nonce_field() {
	wp_nonce_field( 'sam_dash', 'sam_nonce' );
}

/* ---------------------------- Modellide nimekiri ---------------------------- */

function sam_render_model_list() {
	$g      = wp_unslash( $_GET ); // phpcs:ignore
	$q      = isset( $g['q'] ) ? sanitize_text_field( $g['q'] ) : '';
	$status = isset( $g['staatus'] ) ? sanitize_key( $g['staatus'] ) : '';
	$photo  = ! empty( $g['pildiga'] );
	$paged  = max( 1, (int) ( $g['lk'] ?? 1 ) );

	$tax_query = array( 'relation' => 'AND' );
	$selected  = array();
	foreach ( sam_taxonomies() as $tax => $cfg ) {
		$param = str_replace( 'sam_', '', $tax );
		$tid   = isset( $g[ $param ] ) ? (int) $g[ $param ] : 0;
		$selected[ $tax ] = $tid;
		if ( $tid ) {
			$tax_query[] = array(
				'taxonomy' => $tax,
				'field'    => 'term_id',
				'terms'    => array( $tid ),
			);
		}
	}

	$meta_query = array( 'relation' => 'AND' );
	if ( $status && array_key_exists( $status, sam_statuses() ) ) {
		if ( 'uus' === $status ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'   => '_sam_status',
					'value' => 'uus',
				),
				array(
					'key'     => '_sam_status',
					'compare' => 'NOT EXISTS',
				),
			);
		} else {
			$meta_query[] = array(
				'key'   => '_sam_status',
				'value' => $status,
			);
		}
	} else {
		// Vaikimisi peidame arhiveeritud.
		$meta_query[] = array(
			'relation' => 'OR',
			array(
				'key'     => '_sam_status',
				'value'   => 'arhiiv',
				'compare' => '!=',
			),
			array(
				'key'     => '_sam_status',
				'compare' => 'NOT EXISTS',
			),
		);
	}
	if ( $photo ) {
		$meta_query[] = array(
			'key'     => '_sam_photos',
			'value'   => 'a:0:{}',
			'compare' => '!=',
		);
	}

	$args = array(
		'post_type'      => SAM_CPT,
		'post_status'    => 'publish',
		'posts_per_page' => 24,
		'paged'          => $paged,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'tax_query'      => $tax_query, // phpcs:ignore
		'meta_query'     => $meta_query, // phpcs:ignore
	);
	if ( '' !== $q ) {
		$digits = preg_replace( '/\D/', '', $q );
		if ( false !== strpos( $q, '@' ) ) {
			$args['meta_query'][] = array(
				'key'     => '_sam_email',
				'value'   => $q,
				'compare' => 'LIKE',
			);
		} elseif ( strlen( $digits ) >= 3 && strlen( $digits ) >= strlen( preg_replace( '/\s/', '', $q ) ) - 1 ) {
			$args['meta_query'][] = array(
				'key'     => '_sam_phone',
				'value'   => $digits,
				'compare' => 'LIKE',
			);
		} else {
			$args['s'] = $q;
		}
	}
	$query = new WP_Query( $args );

	$base = sam_dashboard_url();
	?>
	<div class="sam-toolbar">
		<h1>Modellid</h1>
		<a class="sam-btn sam-btn-primary" href="<?php echo esc_url( sam_dashboard_url( array( 'vaade' => 'lisa' ) ) ); ?>">+ Lisa modell</a>
	</div>
	<form class="sam-filters" method="get" action="<?php echo esc_url( $base ); ?>">
		<?php
		// Ilma püsilinkideta on töölaud aadressil ?sb_toolaud=1.
		if ( false !== strpos( $base, 'sb_toolaud=' ) ) {
			echo '<input type="hidden" name="sb_toolaud" value="1">';
		}
		?>
		<input type="hidden" name="vaade" value="modellid">
		<input type="search" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="Otsi nime, telefoni või e-posti järgi">
		<?php foreach ( sam_taxonomies() as $tax => $cfg ) : ?>
			<?php $param = str_replace( 'sam_', '', $tax ); ?>
			<select name="<?php echo esc_attr( $param ); ?>" aria-label="<?php echo esc_attr( $cfg['label'] ); ?>">
				<option value=""><?php echo esc_html( $cfg['label'] ); ?>: kõik</option>
				<?php foreach ( sam_terms( $tax ) as $t ) : ?>
					<option value="<?php echo (int) $t->term_id; ?>" <?php selected( $selected[ $tax ], $t->term_id ); ?>><?php echo esc_html( $t->name ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php endforeach; ?>
		<select name="staatus" aria-label="Staatus">
			<option value="">Staatus: uued ja aktiivsed</option>
			<?php foreach ( sam_statuses() as $k => $label ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $status, $k ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<label class="sam-check"><input type="checkbox" name="pildiga" value="1" <?php checked( $photo ); ?>> Ainult pildiga</label>
		<button type="submit" class="sam-btn sam-btn-primary">Filtreeri</button>
		<a class="sam-btn sam-btn-ghost" href="<?php echo esc_url( sam_dashboard_url( array( 'vaade' => 'modellid' ) ) ); ?>">Tühjenda</a>
	</form>

	<p class="sam-muted sam-count">Leitud: <?php echo (int) $query->found_posts; ?> modelli</p>

	<?php if ( ! $query->have_posts() ) : ?>
		<div class="sam-empty">Ühtegi modelli ei leitud nende filtritega.</div>
	<?php else : ?>
		<div class="sam-grid">
			<?php
			foreach ( $query->posts as $p ) {
				sam_render_model_card( sam_get_model( $p ) );
			}
			?>
		</div>
		<?php
		if ( $query->max_num_pages > 1 ) {
			echo '<div class="sam-pager">';
			for ( $i = 1; $i <= $query->max_num_pages; $i++ ) {
				$url = add_query_arg( 'lk', $i, remove_query_arg( array( 'lk', 'msg' ), sam_current_url() ) );
				printf( '<a class="%s" href="%s">%d</a>', $i === $paged ? 'is-active' : '', esc_url( $url ), (int) $i );
			}
			echo '</div>';
		}
	endif;
}

function sam_status_badge( $status ) {
	$labels = sam_statuses();
	$label  = $labels[ $status ] ?? $status;
	return '<span class="sam-status sam-status-' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
}

function sam_initials( $name ) {
	$parts = preg_split( '/\s+/', trim( $name ) );
	$i     = '';
	foreach ( array_slice( $parts, 0, 2 ) as $p ) {
		$i .= mb_strtoupper( mb_substr( $p, 0, 1 ) );
	}
	return $i ?: '?';
}

function sam_render_model_card( $m ) {
	$url   = sam_dashboard_url(
		array(
			'vaade' => 'modell',
			'id'    => $m['id'],
		)
	);
	$photo = $m['photos'] ? reset( $m['photos'] ) : null;
	?>
	<a class="sam-card" href="<?php echo esc_url( $url ); ?>">
		<div class="sam-card-img">
			<?php if ( $photo ) : ?>
				<img src="<?php echo esc_url( sam_photo_url( $m['id'], $photo['key'], true ) ); ?>" alt="" loading="lazy">
				<?php if ( count( $m['photos'] ) > 1 ) : ?>
					<span class="sam-card-count"><?php echo count( $m['photos'] ); ?> pilti</span>
				<?php endif; ?>
			<?php else : ?>
				<span class="sam-initials"><?php echo esc_html( sam_initials( $m['name'] ) ); ?></span>
			<?php endif; ?>
		</div>
		<div class="sam-card-body">
			<div class="sam-card-top">
				<strong><?php echo esc_html( $m['name'] ); ?></strong>
				<?php echo sam_status_badge( $m['status'] ); // phpcs:ignore ?>
			</div>
			<div class="sam-card-phone"><?php echo esc_html( $m['phone'] ); ?></div>
			<div class="sam-tags">
				<?php
				foreach ( $m['terms'] as $tax => $terms ) {
					foreach ( $terms as $t ) {
						echo '<span class="sam-tag sam-tag-' . esc_attr( $tax ) . '">' . esc_html( $t->name ) . '</span>';
					}
				}
				?>
			</div>
			<div class="sam-muted sam-small">Lisatud <?php echo esc_html( sam_date( $m['created'] ) ); ?></div>
		</div>
	</a>
	<?php
}

/* ---------------------------- Modelli kaart ---------------------------- */

function sam_render_model_view( $id ) {
	$m = sam_get_model( $id );
	if ( ! $m ) {
		echo '<div class="sam-notice sam-err">Modellit ei leitud.</div>';
		return;
	}
	$tel = preg_replace( '/[^0-9+]/', '', $m['phone'] );
	?>
	<p><a href="<?php echo esc_url( sam_dashboard_url() ); ?>">← Tagasi nimekirja</a></p>
	<div class="sam-model-head">
		<div>
			<h2><?php echo esc_html( $m['name'] ); ?> <?php echo sam_status_badge( $m['status'] ); // phpcs:ignore ?></h2>
			<div class="sam-contact">
				<?php if ( $m['phone'] ) : ?>
					<a class="sam-btn sam-btn-primary" href="tel:<?php echo esc_attr( $tel ); ?>">Helista <?php echo esc_html( $m['phone'] ); ?></a>
				<?php endif; ?>
				<?php if ( $m['email'] ) : ?>
					<a class="sam-btn" href="mailto:<?php echo esc_attr( $m['email'] ); ?>"><?php echo esc_html( $m['email'] ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<form method="post" class="sam-inline">
			<?php sam_nonce_field(); ?>
			<input type="hidden" name="sam_dash_action" value="contacted">
			<input type="hidden" name="model_id" value="<?php echo (int) $m['id']; ?>">
			<button class="sam-btn" type="submit">✓ Olime täna ühenduses</button>
		</form>
	</div>

	<dl class="sam-meta">
		<div><dt>Registreerus</dt><dd><?php echo esc_html( sam_date( $m['created'] ) ); ?> (<?php echo 'salong' === $m['source'] ? 'lisati salongis' : 'kodulehe vormiga'; ?>)</dd></div>
		<div><dt>Nõusolek</dt><dd><?php echo esc_html( sam_date( $m['consent'] ) ); ?></dd></div>
		<div><dt>Viimane kontakt</dt><dd><?php echo esc_html( sam_date( $m['last_contact'] ) ); ?></dd></div>
		<div><dt>Andmed kustuvad</dt><dd><?php echo esc_html( sam_date( sam_expiry_ts( $m ) ) ); ?></dd></div>
	</dl>

	<section class="sam-section">
		<h3>Pildid</h3>
		<?php if ( $m['photos'] ) : ?>
			<div class="sam-photos">
				<?php foreach ( $m['photos'] as $p ) : ?>
					<figure class="sam-photo">
						<a href="<?php echo esc_url( sam_photo_url( $m['id'], $p['key'] ) ); ?>" target="_blank" rel="noopener">
							<img src="<?php echo esc_url( sam_photo_url( $m['id'], $p['key'], true ) ); ?>" alt="" loading="lazy">
						</a>
						<figcaption>
							<?php echo 'salong' === $p['source'] ? 'Salongis' : 'Modell saatis'; ?>, <?php echo esc_html( sam_date( $p['added'] ) ); ?>
							<form method="post" class="sam-inline" data-confirm="Kas kustutada see pilt?">
								<?php sam_nonce_field(); ?>
								<input type="hidden" name="sam_dash_action" value="delete_photo">
								<input type="hidden" name="model_id" value="<?php echo (int) $m['id']; ?>">
								<input type="hidden" name="photo" value="<?php echo esc_attr( $p['key'] ); ?>">
								<button type="submit" class="sam-link-danger">Kustuta</button>
							</form>
						</figcaption>
					</figure>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<p class="sam-muted">Pilte veel pole.</p>
		<?php endif; ?>

		<form method="post" enctype="multipart/form-data" class="sam-upload">
			<?php sam_nonce_field(); ?>
			<input type="hidden" name="sam_dash_action" value="upload_photos">
			<input type="hidden" name="model_id" value="<?php echo (int) $m['id']; ?>">
			<label class="sam-btn sam-btn-primary sam-file-btn">
				📷 Tee pilt / lisa pilt
				<input type="file" name="photos[]" accept="image/*" capture="environment" multiple data-autosubmit="1">
			</label>
			<label class="sam-btn sam-file-btn">
				Vali galeriist
				<input type="file" name="photos[]" accept="image/*" multiple data-autosubmit="1">
			</label>
			<noscript><button type="submit" class="sam-btn">Laadi üles</button></noscript>
			<span class="sam-upload-status sam-muted"></span>
		</form>
	</section>

	<section class="sam-section">
		<h3>Andmed ja kategooriad</h3>
		<?php sam_render_model_form( $m ); ?>
	</section>

	<section class="sam-section sam-danger-zone">
		<form method="post" data-confirm="Kas kustutada modell <?php echo esc_attr( $m['name'] ); ?> koos kõigi piltidega jäädavalt?">
			<?php sam_nonce_field(); ?>
			<input type="hidden" name="sam_dash_action" value="delete_model">
			<input type="hidden" name="model_id" value="<?php echo (int) $m['id']; ?>">
			<button type="submit" class="sam-btn sam-btn-danger">Kustuta modell</button>
			<span class="sam-muted sam-small">Kasuta, kui modell palub oma andmed kustutada.</span>
		</form>
	</section>
	<?php
}

/** Modelli lisamise/muutmise vorm. $m = null → uus modell. */
function sam_render_model_form( $m ) {
	$is_new = ! $m;
	$vals   = ! empty( $GLOBALS['sam_dash_values'] ) ? $GLOBALS['sam_dash_values'] : null;
	$get    = function ( $k ) use ( $m, $vals ) {
		if ( $vals && isset( $vals[ $k ] ) ) {
			return $vals[ $k ];
		}
		return $m ? $m[ $k ] : '';
	};
	$has_term = function ( $tax, $tid ) use ( $m, $vals ) {
		if ( $vals ) {
			return ! empty( $vals['terms'][ $tax ] ) && in_array( (string) $tid, array_map( 'strval', (array) $vals['terms'][ $tax ] ), true );
		}
		if ( ! $m ) {
			return false;
		}
		return in_array( (int) $tid, wp_list_pluck( $m['terms'][ $tax ], 'term_id' ), true );
	};
	if ( $is_new ) {
		echo '<h2>Lisa modell</h2><p class="sam-muted">Kasuta, kui modell on salongis või helistas. Pildi saad teha kohe telefoniga.</p>';
	}
	?>
	<form method="post" enctype="multipart/form-data" class="sam-form sam-edit">
		<?php sam_nonce_field(); ?>
		<input type="hidden" name="sam_dash_action" value="<?php echo $is_new ? 'add_model' : 'save_model'; ?>">
		<?php if ( ! $is_new ) : ?>
			<input type="hidden" name="model_id" value="<?php echo (int) $m['id']; ?>">
		<?php endif; ?>

		<div class="sam-row">
			<div class="sam-field"><label>Nimi *</label><input type="text" name="name" required value="<?php echo esc_attr( $get( 'name' ) ); ?>"></div>
			<div class="sam-field"><label>Telefon</label><input type="tel" name="phone" value="<?php echo esc_attr( $get( 'phone' ) ); ?>"></div>
			<div class="sam-field"><label>E-post</label><input type="email" name="email" value="<?php echo esc_attr( $get( 'email' ) ); ?>"></div>
		</div>

		<?php foreach ( sam_taxonomies() as $tax => $cfg ) : ?>
			<fieldset class="sam-field sam-chips">
				<legend><?php echo esc_html( $cfg['plural'] ); ?></legend>
				<div class="sam-chip-list">
					<?php foreach ( sam_terms( $tax ) as $t ) : ?>
						<label class="sam-chip">
							<input type="<?php echo $cfg['multi'] ? 'checkbox' : 'radio'; ?>" name="terms[<?php echo esc_attr( $tax ); ?>][]" value="<?php echo (int) $t->term_id; ?>" <?php checked( $has_term( $tax, $t->term_id ) ); ?>>
							<span><?php echo esc_html( $t->name ); ?></span>
						</label>
					<?php endforeach; ?>
					<?php if ( ! $cfg['multi'] ) : ?>
						<label class="sam-chip sam-chip-clear"><input type="radio" name="terms[<?php echo esc_attr( $tax ); ?>][]" value="0"><span>—</span></label>
					<?php endif; ?>
				</div>
			</fieldset>
		<?php endforeach; ?>

		<div class="sam-row">
			<div class="sam-field">
				<label>Staatus</label>
				<select name="status">
					<?php foreach ( sam_statuses() as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $get( 'status' ) ?: ( $is_new ? 'aktiivne' : 'uus' ), $k ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="sam-field"><label>Millal saab tulla</label><input type="text" name="availability" value="<?php echo esc_attr( $get( 'availability' ) ); ?>"></div>
		</div>

		<div class="sam-field"><label>Modelli lisainfo</label><textarea name="info" rows="3"><?php echo esc_textarea( $get( 'info' ) ); ?></textarea></div>
		<div class="sam-field"><label>Juhendaja märkmed <span class="sam-muted">(näevad ainult juhendajad)</span></label><textarea name="notes" rows="3"><?php echo esc_textarea( $get( 'notes' ) ); ?></textarea></div>

		<?php if ( $is_new ) : ?>
			<div class="sam-field">
				<label>Pildid</label>
				<input type="file" name="photos[]" accept="image/*" capture="environment" multiple>
				<div class="sam-preview"></div>
			</div>
			<label class="sam-consent">
				<input type="checkbox" name="consent" value="1" required>
				<span>Modell on nõus, et tema andmeid ja pilte hoitakse modellide andmebaasis kuni <?php echo (int) sam_setting( 'retention_years' ); ?> aastat viimasest kontaktist.</span>
			</label>
		<?php endif; ?>

		<button type="submit" class="sam-btn sam-btn-primary"><?php echo $is_new ? 'Lisa modell' : 'Salvesta muudatused'; ?></button>
	</form>
	<?php
}

/* ---------------------------- Praktikandid ---------------------------- */

function sam_render_applications() {
	$fid = (int) sam_setting( 'practicum_form' );
	echo '<h1>Juuksuriõpilaste avaldused</h1><p class="sam-muted">Siia jõuavad kodulehe vormi kaudu saadetud sooviavaldused.</p>';
	if ( ! $fid || ! sam_ff_available() ) {
		echo '<div class="sam-notice sam-err">Avalduste vorm pole seadistatud. Administraator saab selle valida WordPressi halduses: Modellid → Seaded.</div>';
		return;
	}
	$subs = sam_ff_get_submissions( $fid, 200 );
	if ( ! $subs ) {
		echo '<div class="sam-empty">Avaldusi veel pole.</div>';
		return;
	}
	echo '<div class="sam-table-wrap"><table class="sam-table"><thead><tr><th>Kuupäev</th><th>Nimi</th><th>Telefon</th><th>E-post</th><th></th></tr></thead><tbody>';
	foreach ( $subs as $s ) {
		$sum = sam_ff_summary( json_decode( $s->response, true ) );
		$url = sam_dashboard_url(
			array(
				'vaade' => 'avaldus',
				'id'    => $s->id,
			)
		);
		printf(
			'<tr class="%s"><td>%s</td><td><a href="%s">%s</a>%s</td><td>%s</td><td>%s</td><td><a class="sam-btn sam-btn-small" href="%s">Ava</a></td></tr>',
			'unread' === $s->status ? 'is-unread' : '',
			esc_html( sam_ff_date( $s->created_at ) ),
			esc_url( $url ),
			esc_html( $sum['name'] ?: 'Avaldus #' . $s->id ),
			'unread' === $s->status ? ' <span class="sam-badge">uus</span>' : '',
			esc_html( $sum['phone'] ),
			esc_html( $sum['email'] ),
			esc_url( $url )
		);
	}
	echo '</tbody></table></div>';
}

function sam_render_application( $id ) {
	$fid = (int) sam_setting( 'practicum_form' );
	$s   = sam_ff_get_submission( $fid, $id );
	if ( ! $s ) {
		echo '<div class="sam-notice sam-err">Avaldust ei leitud.</div>';
		return;
	}
	if ( 'unread' === $s->status ) {
		sam_ff_set_status( $s->id, 'read' );
	}
	$resp   = json_decode( $s->response, true );
	$labels = sam_ff_field_labels( $fid );
	$skip   = array( '_wp_http_referer', '__fluent_form_embded_post_id', 'cf-turnstile-response', 'terms-n-condition', 'g-recaptcha-response', 'h-captcha-response' );
	$sum    = sam_ff_summary( $resp );
	?>
	<p><a href="<?php echo esc_url( sam_dashboard_url( array( 'vaade' => 'praktikandid' ) ) ); ?>">← Tagasi avalduste juurde</a></p>
	<h2><?php echo esc_html( $sum['name'] ?: 'Avaldus #' . $s->id ); ?></h2>
	<p class="sam-muted">Saadetud <?php echo esc_html( sam_ff_date( $s->created_at ) ); ?></p>
	<dl class="sam-answers">
		<?php
		foreach ( (array) $resp as $k => $v ) {
			if ( in_array( $k, $skip, true ) || 0 === strpos( $k, '_' ) || 0 === strpos( $k, 'item_' ) ) {
				continue;
			}
			$txt = sam_ff_value_to_text( $v );
			if ( '' === $txt ) {
				continue;
			}
			$label = $labels[ $k ] ?? $k;
			echo '<div><dt>' . esc_html( $label ) . '</dt><dd>';
			if ( is_email( $txt ) ) {
				echo '<a href="mailto:' . esc_attr( $txt ) . '">' . esc_html( $txt ) . '</a>';
			} elseif ( preg_match( '/^\+?[0-9 ]{7,}$/', $txt ) ) {
				echo '<a href="tel:' . esc_attr( preg_replace( '/\s/', '', $txt ) ) . '">' . esc_html( $txt ) . '</a>';
			} else {
				echo nl2br( esc_html( $txt ) );
			}
			echo '</dd></div>';
		}
		?>
	</dl>
	<form method="post" class="sam-inline">
		<?php sam_nonce_field(); ?>
		<input type="hidden" name="sam_dash_action" value="app_status">
		<input type="hidden" name="submission_id" value="<?php echo (int) $s->id; ?>">
		<input type="hidden" name="status" value="unread">
		<button type="submit" class="sam-btn sam-btn-ghost">Märgi lugemata</button>
	</form>
	<?php
}


/* ---------------------------- Minu konto ---------------------------- */
