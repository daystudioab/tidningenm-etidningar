<?php
/**
 * Korsordsfacit.
 *
 * Varje E-tidning kan ha ett facit (en bild). Facit laddas upp under
 * E-tidningar → Korsordsfacit eller direkt på numret. PDF-filer görs om till
 * bild i webbläsaren innan de laddas upp, så att facit alltid kan visas i full
 * storlek på sajten.
 *
 * Sajten: [korsordsfacit] visar senaste facit överst och tidigare facit i ett
 * rutnät som förstoras vid klick.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MET_FACIT_META', 'facit_id' );

/** Facitbildens ID för ett nummer, eller 0. */
function met_facit_id( $post_id ) {
	$id = (int) get_post_meta( $post_id, MET_FACIT_META, true );
	return ( $id && wp_attachment_is_image( $id ) ) ? $id : 0;
}

/** Sidan där facit visas (standard: /facit-korsord/). */
function met_facit_page_id() {
	$saved = (int) get_option( 'met_facit_page' );
	if ( $saved && 'page' === get_post_type( $saved ) ) {
		return $saved;
	}
	$p = get_page_by_path( 'facit-korsord' );
	return $p ? (int) $p->ID : 0;
}

/* -------------------------------------------------------------------------
 * Admin: sidan Korsordsfacit + ruta på nummersidan
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', function () {
	add_submenu_page(
		'edit.php?post_type=' . MET_CPT,
		'Korsordsfacit',
		'Korsordsfacit',
		'upload_files',
		'met-facit',
		'met_facit_admin_page',
		2
	);
} );

add_action( 'add_meta_boxes_' . MET_CPT, function ( $post ) {
	add_meta_box( 'met_facit', 'Korsordsfacit', function ( $post ) {
		echo met_facit_slot_html( $post, 'compact' ); // phpcs:ignore WordPress.Security.EscapeOutput -- byggs med esc_* nedan.
	}, MET_CPT, 'side', 'default' );
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	$screen = get_current_screen();
	$on_page = ( MET_CPT . '_page_met-facit' === $hook );
	$on_edit = $screen && MET_CPT === $screen->post_type && 'post' === $screen->base;
	if ( ! $on_page && ! $on_edit ) {
		return;
	}
	$url = plugin_dir_url( dirname( __FILE__ ) );
	wp_enqueue_media();
	wp_enqueue_style( 'met-facit-admin', $url . 'assets/facit-admin.css', array(), MET_VERSION );
	wp_enqueue_script( 'met-pdfjs', $url . 'assets/vendor/pdfjs/pdf.min.js', array(), '3.11.174', true );
	wp_enqueue_script( 'met-facit-admin', $url . 'assets/facit-admin.js', array( 'jquery', 'met-pdfjs' ), MET_VERSION, true );
	wp_localize_script( 'met-facit-admin', 'METFACIT', array(
		'ajaxurl'   => admin_url( 'admin-ajax.php' ),
		'nonce'     => wp_create_nonce( 'met_facit' ),
		'pdfWorker' => $url . 'assets/vendor/pdfjs/pdf.worker.min.js',
	) );
} );

/** Förhandsvisningen i en facitruta. */
function met_facit_preview_html( $post_id ) {
	$id = met_facit_id( $post_id );
	if ( ! $id ) {
		return '<span class="met-facit-empty">Inget facit än</span>';
	}
	$full = wp_get_attachment_image_url( $id, 'full' );
	return sprintf(
		'<a href="%s" target="_blank" rel="noopener" title="Öppna i full storlek">%s</a>',
		esc_url( $full ),
		wp_get_attachment_image( $id, 'medium', false, array( 'alt' => '' ) )
	);
}

/** En ruta för att ladda upp, byta eller ta bort facit för ett nummer. */
function met_facit_slot_html( $post, $variant = 'row' ) {
	$post  = get_post( $post );
	$has   = (bool) met_facit_id( $post->ID );
	$title = get_the_title( $post );
	ob_start();
	?>
	<div class="met-facit-slot met-facit-slot--<?php echo esc_attr( $variant ); ?><?php echo $has ? ' has-facit' : ''; ?>" data-post="<?php echo (int) $post->ID; ?>" data-title="<?php echo esc_attr( $title ); ?>">
		<div class="met-facit-preview"><?php echo met_facit_preview_html( $post->ID ); // phpcs:ignore ?></div>
		<div class="met-facit-body">
			<?php if ( 'compact' !== $variant ) : ?>
				<p class="met-facit-title"><strong><?php echo esc_html( $title ); ?></strong>
				<?php if ( 'publish' !== $post->post_status ) : ?><span class="met-facit-state">(<?php echo esc_html( get_post_status_object( $post->post_status )->label ); ?>)</span><?php endif; ?></p>
			<?php endif; ?>
			<div class="met-facit-actions">
				<label class="button button-primary met-facit-upload">
					<span class="met-facit-upload-label"><?php echo $has ? 'Byt facit' : 'Ladda upp facit'; ?></span>
					<input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" class="screen-reader-text">
				</label>
				<button type="button" class="button met-facit-library">Välj i mediabiblioteket</button>
				<button type="button" class="button-link button-link-delete met-facit-remove"<?php echo $has ? '' : ' hidden'; ?>>Ta bort</button>
			</div>
			<p class="met-facit-hint">Bild (JPG, PNG) eller PDF. Du kan också dra filen hit.</p>
			<p class="met-facit-status" aria-live="polite"></p>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

function met_facit_admin_page() {
	$issues = get_posts( array_merge( array(
		'post_type'      => MET_CPT,
		'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
		'posts_per_page' => -1,
	), met_order_args() ) );
	$page_id = met_facit_page_id();
	$latest  = $issues ? array_shift( $issues ) : null;
	?>
	<div class="wrap met-facit-wrap">
		<h1>Korsordsfacit</h1>
		<p class="met-facit-intro">Ladda upp facit till ett nummer så visas det direkt på
			<?php if ( $page_id ) : ?>
				<a href="<?php echo esc_url( get_permalink( $page_id ) ); ?>" target="_blank"><?php echo esc_html( wp_parse_url( get_permalink( $page_id ), PHP_URL_PATH ) ); ?></a>.
			<?php else : ?>
				facitsidan (lägg kortkoden <code>[korsordsfacit]</code> på en sida).
			<?php endif; ?>
			Det senaste facit visas överst i full storlek, tidigare facit i ett rutnät under. En PDF görs automatiskt om till en bild.</p>

		<?php if ( $latest ) : ?>
			<h2>Senaste numret</h2>
			<?php echo met_facit_slot_html( $latest, 'featured' ); // phpcs:ignore ?>
		<?php endif; ?>

		<?php if ( $issues ) : ?>
			<h2>Tidigare nummer</h2>
			<div class="met-facit-list">
				<?php foreach ( $issues as $issue ) {
					echo met_facit_slot_html( $issue, 'row' ); // phpcs:ignore
				} ?>
			</div>
		<?php endif; ?>

		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<hr>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="met-facit-settings">
				<?php wp_nonce_field( 'met_facit_settings' ); ?>
				<input type="hidden" name="action" value="met_facit_settings">
				<label for="met_facit_page"><strong>Facitsida</strong></label>
				<?php wp_dropdown_pages( array( 'name' => 'met_facit_page', 'id' => 'met_facit_page', 'selected' => $page_id, 'show_option_none' => '— Välj —', 'option_none_value' => 0 ) ); ?>
				<?php submit_button( 'Spara', 'secondary', 'submit', false ); ?>
				<p class="description">Sidan som visar facit med kortkoden <code>[korsordsfacit]</code>. Används för länken här ovanför.</p>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

add_action( 'admin_post_met_facit_settings', function () {
	check_admin_referer( 'met_facit_settings' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Behörighet saknas.' );
	}
	update_option( 'met_facit_page', isset( $_POST['met_facit_page'] ) ? (int) $_POST['met_facit_page'] : 0 );
	wp_safe_redirect( add_query_arg( array( 'post_type' => MET_CPT, 'page' => 'met-facit' ), admin_url( 'edit.php' ) ) );
	exit;
} );

/* -------------------------------------------------------------------------
 * AJAX: ladda upp, välj befintlig, ta bort
 * ---------------------------------------------------------------------- */

/** Kontrollerar behörighet och returnerar numrets ID. */
function met_facit_ajax_issue() {
	check_ajax_referer( 'met_facit', 'nonce' );
	$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
	if ( MET_CPT !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'upload_files' ) ) {
		wp_send_json_error( array( 'message' => 'Behörighet saknas.' ), 403 );
	}
	return $post_id;
}

function met_facit_assign( $post_id, $att_id ) {
	$title = get_the_title( $post_id );
	update_post_meta( $post_id, MET_FACIT_META, (int) $att_id );
	update_post_meta( $att_id, '_met_facit_for', (int) $post_id );
	if ( ! get_post_meta( $att_id, '_wp_attachment_image_alt', true ) ) {
		update_post_meta( $att_id, '_wp_attachment_image_alt', 'Facit till korsordet i ' . $title );
	}
}

function met_facit_ajax_done( $post_id, $message ) {
	wp_send_json_success( array(
		'message' => $message,
		'preview' => met_facit_preview_html( $post_id ),
		'has'     => (bool) met_facit_id( $post_id ),
	) );
}

add_action( 'wp_ajax_met_facit_upload', function () {
	$post_id = met_facit_ajax_issue();
	if ( empty( $_FILES['file'] ) || ! empty( $_FILES['file']['error'] ) ) {
		wp_send_json_error( array( 'message' => 'Filen kom inte fram. Försök igen.' ) );
	}
	$check   = wp_check_filetype_and_ext( $_FILES['file']['tmp_name'], $_FILES['file']['name'] );
	$allowed = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );
	if ( empty( $check['type'] ) || ! in_array( $check['type'], $allowed, true ) ) {
		wp_send_json_error( array( 'message' => 'Facit måste vara en bild (JPG eller PNG) eller en PDF.' ) );
	}

	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$title                   = get_the_title( $post_id );
	$_FILES['file']['name'] = 'facit-' . sanitize_title( $title ) . '.' . $check['ext'];
	$att_id                  = media_handle_upload( 'file', $post_id, array( 'post_title' => 'Facit ' . $title ) );
	if ( is_wp_error( $att_id ) ) {
		wp_send_json_error( array( 'message' => $att_id->get_error_message() ) );
	}
	$source = isset( $_POST['source_pdf'] ) ? (int) $_POST['source_pdf'] : 0;
	if ( $source ) {
		update_post_meta( $att_id, '_met_facit_source', $source );
	}
	met_facit_assign( $post_id, $att_id );
	met_facit_ajax_done( $post_id, 'Facit för ' . $title . ' är uppladdat och visas på sajten.' );
} );

add_action( 'wp_ajax_met_facit_set', function () {
	$post_id = met_facit_ajax_issue();
	$att_id  = isset( $_POST['attachment_id'] ) ? (int) $_POST['attachment_id'] : 0;
	if ( ! $att_id || ! wp_attachment_is_image( $att_id ) ) {
		wp_send_json_error( array( 'message' => 'Välj en bild.' ) );
	}
	met_facit_assign( $post_id, $att_id );
	met_facit_ajax_done( $post_id, 'Facit för ' . get_the_title( $post_id ) . ' är valt och visas på sajten.' );
} );

add_action( 'wp_ajax_met_facit_remove', function () {
	$post_id = met_facit_ajax_issue();
	delete_post_meta( $post_id, MET_FACIT_META ); // Bilden ligger kvar i mediabiblioteket.
	met_facit_ajax_done( $post_id, 'Facit för ' . get_the_title( $post_id ) . ' visas inte längre. Bilden finns kvar i mediabiblioteket.' );
} );

/* Facit-kolumn i listan över nummer. */
add_filter( 'manage_' . MET_CPT . '_posts_columns', function ( $cols ) {
	$cols['met_facit'] = 'Facit';
	return $cols;
}, 20 );
add_action( 'manage_' . MET_CPT . '_posts_custom_column', function ( $col, $post_id ) {
	if ( 'met_facit' === $col ) {
		echo met_facit_id( $post_id ) ? '✓' : '<span aria-hidden="true">—</span>';
	}
}, 10, 2 );

/* -------------------------------------------------------------------------
 * Sajten: [korsordsfacit]
 *
 * Attribut:
 *   kolumner="4"   Antal kolumner för tidigare facit på dator (standard 4)
 * ---------------------------------------------------------------------- */

add_shortcode( 'korsordsfacit', 'met_facit_shortcode' );
function met_facit_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'kolumner' => '4' ), $atts, 'korsordsfacit' );

	$q = new WP_Query( array_merge( array(
		'post_type'      => MET_CPT,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'no_found_rows'  => true,
	), met_order_args() ) );

	$items = array();
	foreach ( $q->posts as $p ) {
		$fid = met_facit_id( $p->ID );
		if ( $fid ) {
			$items[] = array( 'post' => $p, 'img' => $fid, 'title' => get_the_title( $p ) );
		}
	}
	if ( ! $items ) {
		return '<p class="met-facit-none">Inget facit publicerat ännu.</p>';
	}

	$url = plugin_dir_url( dirname( __FILE__ ) );
	wp_enqueue_style( 'met-facit', $url . 'assets/facit.css', array(), MET_VERSION );
	wp_enqueue_script( 'met-facit', $url . 'assets/facit.js', array(), MET_VERSION, true );

	$latest = array_shift( $items );
	$cols   = max( 1, min( 6, (int) $atts['kolumner'] ) );
	// Visningsbredd: bildens egen bredd, högst 680 px (samma som i facit.css); på mindre skärmar hela bredden.
	$meta    = wp_get_attachment_metadata( $latest['img'] );
	$natural = ! empty( $meta['width'] ) ? min( 680, (int) $meta['width'] ) : 680;

	$out  = '<div class="met-facit">';
	$out .= '<section class="met-facit-latest">';
	$out .= sprintf( '<h2 class="met-facit-heading">Facit till korsordet i %s</h2>', esc_html( $latest['title'] ) );
	$out .= '<figure class="met-facit-latest__figure">' . wp_get_attachment_image( $latest['img'], 'full', false, array(
		'class'    => 'met-facit-latest__img',
		'alt'      => 'Facit till korsordet i ' . $latest['title'],
		'loading'  => 'eager',
		'sizes'    => sprintf( '(min-width: %1$dpx) %1$dpx, 100vw', $natural ),
	) ) . '</figure>';
	$out .= '</section>';

	if ( $items ) {
		$out .= '<section class="met-facit-earlier">';
		$out .= sprintf( '<div class="met-facit-grid" style="--met-facit-cols:%d">', $cols );
		foreach ( $items as $item ) {
			$full = wp_get_attachment_image_src( $item['img'], 'full' );
			$out .= sprintf(
				'<button type="button" class="met-facit-item" data-full="%s" data-title="%s" aria-label="%s">%s<span class="met-facit-item__title">%s</span></button>',
				esc_url( $full[0] ),
				esc_attr( $item['title'] ),
				esc_attr( 'Förstora facit för ' . $item['title'] ),
				wp_get_attachment_image( $item['img'], 'medium_large', false, array(
					'class'   => 'met-facit-item__img',
					'alt'     => '',
					'loading' => 'lazy',
					'sizes'   => '(min-width: 1024px) 280px, (min-width: 768px) 33vw, 50vw',
				) ),
				esc_html( $item['title'] )
			);
		}
		$out .= '</div></section>';
		$out .= '<div class="met-facit-lightbox" role="dialog" aria-modal="true" aria-label="Facit" hidden>'
			. '<button type="button" class="met-facit-lightbox__close" aria-label="Stäng">&times;</button>'
			. '<button type="button" class="met-facit-lightbox__nav met-facit-lightbox__prev" aria-label="Föregående">&lsaquo;</button>'
			. '<figure class="met-facit-lightbox__figure"><img class="met-facit-lightbox__img" alt=""><figcaption class="met-facit-lightbox__caption"></figcaption></figure>'
			. '<button type="button" class="met-facit-lightbox__nav met-facit-lightbox__next" aria-label="Nästa">&rsaquo;</button>'
			. '</div>';
	}
	$out .= '</div>';
	return $out;
}
