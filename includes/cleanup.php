<?php
/**
 * Kontroll & städning av importerade E-tidningar.
 *
 * Rör ENDAST E-tidning-poster som tillägget själv skapat. Dubletter flyttas
 * till papperskorgen (går att återställa). Gamla sidor ändras aldrig.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Alla E-tidningar grupperade per år-nummer. */
function met_issue_groups() {
	$posts  = get_posts( array(
		'post_type'      => MET_CPT,
		'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
		'posts_per_page' => -1,
		'orderby'        => 'ID',
		'order'          => 'ASC',
	) );
	$covers = met_cover_map( met_default_archive_page() );
	$groups = array();
	foreach ( $posts as $p ) {
		$nr  = (int) get_post_meta( $p->ID, 'nummer', true );
		$ar  = (int) get_post_meta( $p->ID, 'ar', true );
		$key = ( $nr && $ar ) ? $ar . '-' . $nr : 'x-' . $p->ID;
		$groups[ $key ][] = array(
			'post'  => $p,
			'nr'    => $nr,
			'ar'    => $ar,
			'score' => met_post_score( $p, $covers ),
		);
	}
	foreach ( $groups as &$g ) {
		usort( $g, function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );
	}
	krsort( $groups, SORT_NATURAL );
	return $groups;
}

function met_post_score( $post, $covers ) {
	$score = 0;
	$src   = (int) get_post_meta( $post->ID, '_met_source_page', true );
	if ( $src && 'page' === get_post_type( $src ) ) {
		$score += met_page_score( $src, $covers ) * 2; // Källsidans kvalitet väger tyngst.
	}
	if ( get_post_meta( $post->ID, 'issuu_url', true ) ) {
		$score += 3;
	}
	if ( has_post_thumbnail( $post->ID ) ) {
		$score += 2;
	}
	if ( 'publish' === $post->post_status ) {
		$score += 1;
	}
	return $score;
}

function met_cleanup_section() {
	$groups  = met_issue_groups();
	$dupes   = array_filter( $groups, function ( $g ) { return count( $g ) > 1; } );
	$missing = array();
	foreach ( $groups as $g ) {
		foreach ( $g as $row ) {
			if ( ! get_post_meta( $row['post']->ID, 'issuu_url', true ) ) {
				$missing[] = $row['post'];
			}
		}
	}
	?>
	<h2>0. Kontroll &amp; städning</h2>
	<?php if ( ! $dupes && ! $missing ) : ?>
		<p>✓ Inga dubletter och alla nummer har en ISSUU-länk.</p>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'met_cleanup' ); ?>
		<input type="hidden" name="action" value="met_cleanup">

		<?php if ( $dupes ) : ?>
			<h3>Dubletter (<?php echo count( $dupes ); ?> nummer)</h3>
			<p>Välj vilken version som ska behållas. Förvalt är den som länkas från nuvarande e-tidningssida och har ISSUU-länk och omslag. Övriga flyttas till papperskorgen.</p>
			<table class="widefat striped" style="max-width:1100px">
				<thead><tr><th>Behåll</th><th>Nummer</th><th>Omslag</th><th>ISSUU</th><th>Status</th><th>Importerad från</th></tr></thead>
				<tbody>
				<?php foreach ( $dupes as $key => $g ) :
					foreach ( $g as $i => $row ) :
						$p     = $row['post'];
						$src   = (int) get_post_meta( $p->ID, '_met_source_page', true );
						$issuu = get_post_meta( $p->ID, 'issuu_url', true );
						?>
						<tr<?php echo 0 === $i ? ' style="border-top:2px solid #c3c4c7"' : ''; ?>>
							<td><input type="radio" name="keep[<?php echo esc_attr( $key ); ?>]" value="<?php echo (int) $p->ID; ?>" <?php checked( 0 === $i ); ?>></td>
							<td><a href="<?php echo esc_url( get_edit_post_link( $p->ID ) ); ?>"><?php echo esc_html( $p->post_title ); ?></a></td>
							<td><?php echo has_post_thumbnail( $p->ID ) ? get_the_post_thumbnail( $p->ID, array( 40, 56 ) ) : '—'; ?></td>
							<td><?php echo $issuu ? '<a href="' . esc_url( $issuu ) . '" target="_blank">' . esc_html( basename( $issuu ) ) . '</a>' : '<span style="color:#b32d2e">saknas</span>'; ?></td>
							<td><?php echo esc_html( get_post_status_object( $p->post_status )->label ); ?></td>
							<td><?php echo $src ? '<a href="' . esc_url( get_permalink( $src ) ) . '" target="_blank">' . esc_html( wp_parse_url( get_permalink( $src ), PHP_URL_PATH ) ) . '</a> (' . esc_html( get_post_status( $src ) ) . ')' : '—'; ?></td>
						</tr>
					<?php endforeach;
				endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( $missing ) : ?>
			<h3>Saknar ISSUU-länk (<?php echo count( $missing ); ?>)</h3>
			<p>Städningen söker igen i de gamla sidorna med bättre igenkänning. Det som fortfarande saknas fyller du i för hand: öppna numret och klistra in länken från ISSUU.</p>
			<ul style="columns:3;max-width:900px">
				<?php foreach ( $missing as $p ) :
					$src = (int) get_post_meta( $p->ID, '_met_source_page', true ); ?>
					<li><a href="<?php echo esc_url( get_edit_post_link( $p->ID ) ); ?>"><?php echo esc_html( $p->post_title ); ?></a>
					<?php if ( $src ) : ?> · <a href="<?php echo esc_url( get_permalink( $src ) ); ?>" target="_blank">gamla sidan</a><?php endif; ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<p><label><input type="checkbox" name="tidy" value="1" checked> Snygga även till titlar och adresser (”Nr3 2023” → ”Nr 3 2023”, /e-tidning/nr-1-2024-2/ → /e-tidning/nr-1-2024/)</label></p>
		<?php submit_button( 'Kör städning', 'primary', 'submit', false ); ?>
	</form>
	<hr>
	<?php
}

add_action( 'admin_post_met_cleanup', function () {
	check_admin_referer( 'met_cleanup' );
	if ( ! current_user_can( 'edit_pages' ) ) {
		wp_die( 'Behörighet saknas.' );
	}
	@set_time_limit( 300 );
	$GLOBALS['met_importing'] = true; // Ingen automatisk omslagshämtning vid uppdatering här.

	$keep    = isset( $_POST['keep'] ) ? array_map( 'intval', (array) $_POST['keep'] ) : array();
	$groups  = met_issue_groups();
	$cleaned = 0;
	$found   = 0;
	$renamed = 0;

	// 1. Dubletter → papperskorgen.
	foreach ( $groups as $key => $g ) {
		if ( count( $g ) < 2 ) {
			continue;
		}
		$keep_id     = isset( $keep[ $key ] ) ? $keep[ $key ] : $g[0]['post']->ID;
		$any_publish = false;
		foreach ( $g as $row ) {
			if ( 'publish' === $row['post']->post_status ) {
				$any_publish = true;
			}
		}
		foreach ( $g as $row ) {
			if ( (int) $row['post']->ID !== (int) $keep_id && MET_CPT === get_post_type( $row['post']->ID ) ) {
				wp_trash_post( $row['post']->ID );
				$cleaned++;
			}
		}
		// Var någon version publicerad ska den som behålls också vara det (om den har ISSUU-länk).
		if ( $any_publish && 'publish' !== get_post_status( $keep_id ) && get_post_meta( $keep_id, 'issuu_url', true ) ) {
			wp_update_post( array( 'ID' => $keep_id, 'post_status' => 'publish' ) );
		}
	}

	// 2. Sök ISSUU-länk igen + 3. snygga till titel/adress.
	$all = get_posts( array(
		'post_type'      => MET_CPT,
		'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
		'posts_per_page' => -1,
	) );
	foreach ( $all as $p ) {
		if ( ! get_post_meta( $p->ID, 'issuu_url', true ) ) {
			$src   = (int) get_post_meta( $p->ID, '_met_source_page', true );
			$issuu = $src ? met_find_issuu_in_page( $src ) : '';
			if ( $issuu ) {
				update_post_meta( $p->ID, 'issuu_url', $issuu );
				if ( ! has_post_thumbnail( $p->ID ) ) {
					met_fetch_cover( $p->ID );
				}
				$found++;
			}
		}
		if ( ! empty( $_POST['tidy'] ) ) {
			$nr = (int) get_post_meta( $p->ID, 'nummer', true );
			$ar = (int) get_post_meta( $p->ID, 'ar', true );
			if ( $nr && $ar ) {
				$title = sprintf( 'Nr %d %d', $nr, $ar );
				$slug  = sanitize_title( $title );
				if ( $title !== $p->post_title || $slug !== $p->post_name ) {
					wp_update_post( array(
						'ID'         => $p->ID,
						'post_title' => $title,
						'post_name'  => wp_unique_post_slug( $slug, $p->ID, $p->post_status, MET_CPT, 0 ),
					) );
					$renamed++;
				}
			}
		}
	}

	wp_safe_redirect( add_query_arg( array(
		'post_type' => MET_CPT,
		'page'      => 'met-import',
		'cleaned'   => $cleaned,
		'renamed'   => $renamed,
		'found'     => $found,
	), admin_url( 'edit.php' ) ) );
	exit;
} );

/* -------------------------------------------------------------------------
 * Omslag: hämta alla från ISSUU / återställ tidigare
 * ---------------------------------------------------------------------- */

function met_cover_section() {
	$all     = get_posts( array( 'post_type' => MET_CPT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
	$issuu   = 0;
	$restore = 0;
	foreach ( $all as $id ) {
		$t = (int) get_post_thumbnail_id( $id );
		if ( $t && get_post_meta( $t, '_met_issuu_cover', true ) ) {
			$issuu++;
		}
		if ( get_post_meta( $id, '_met_prev_thumb', true ) ) {
			$restore++;
		}
	}
	?>
	<h2>Omslag</h2>
	<p><?php printf( '%d av %d nummer har omslag hämtat från ISSUU.', $issuu, count( $all ) ); ?>
	Hämtningen ger alla nummer ett omslag i full storlek direkt från ISSUU, med samma utseende för alla. Nuvarande omslag raderas inte. De sparas och kan återställas.</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
		<?php wp_nonce_field( 'met_covers' ); ?>
		<input type="hidden" name="action" value="met_covers">
		<input type="hidden" name="mode" value="fetch">
		<label style="margin-right:8px"><input type="checkbox" name="force" value="1"> Hämta igen även för nummer som redan har ISSUU-omslag</label>
		<?php submit_button( 'Hämta alla omslag från ISSUU', 'primary', 'submit', false ); ?>
	</form>
	<?php if ( $restore ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
			<?php wp_nonce_field( 'met_covers' ); ?>
			<input type="hidden" name="action" value="met_covers">
			<input type="hidden" name="mode" value="restore">
			<?php submit_button( sprintf( 'Återställ tidigare omslag (%d)', $restore ), 'secondary', 'submit', false ); ?>
		</form>
	<?php endif; ?>
	<hr>
	<?php
}

/**
 * Bearbetar några nummer per anrop och fortsätter sedan automatiskt,
 * så att det inte blir tidsgräns på servern.
 */
add_action( 'admin_post_met_covers', function () {
	check_admin_referer( 'met_covers' );
	if ( ! current_user_can( 'edit_pages' ) ) {
		wp_die( 'Behörighet saknas.' );
	}
	@set_time_limit( 120 );

	$mode  = ( isset( $_REQUEST['mode'] ) && 'restore' === $_REQUEST['mode'] ) ? 'restore' : 'fetch';
	$force = ! empty( $_REQUEST['force'] );
	$done  = isset( $_REQUEST['d'] ) ? (int) $_REQUEST['d'] : 0;
	$fail  = isset( $_REQUEST['f'] ) ? (int) $_REQUEST['f'] : 0;
	$skip  = isset( $_REQUEST['skip'] ) ? array_filter( array_map( 'intval', explode( ',', (string) $_REQUEST['skip'] ) ) ) : array();

	$ids = get_posts( array( 'post_type' => MET_CPT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) );

	if ( 'restore' === $mode ) {
		$restored = 0;
		foreach ( $ids as $id ) {
			$prev = (int) get_post_meta( $id, '_met_prev_thumb', true );
			if ( $prev && wp_attachment_is_image( $prev ) ) {
				set_post_thumbnail( $id, $prev );
				delete_post_meta( $id, '_met_prev_thumb' );
				$restored++;
			}
		}
		wp_safe_redirect( add_query_arg( array( 'post_type' => MET_CPT, 'page' => 'met-import', 'covers_restored' => $restored ), admin_url( 'edit.php' ) ) );
		exit;
	}

	// Kvar att göra: har ISSUU-länk, inte redan ISSUU-omslag (om inte force), inte redan försökt i denna körning.
	$todo = array();
	foreach ( $ids as $id ) {
		if ( in_array( $id, $skip, true ) || ! get_post_meta( $id, 'issuu_url', true ) ) {
			continue;
		}
		$t = (int) get_post_thumbnail_id( $id );
		if ( ! $force && $t && get_post_meta( $t, '_met_issuu_cover', true ) ) {
			continue;
		}
		$todo[] = $id;
	}

	$batch = array_slice( $todo, 0, 4 );
	foreach ( $batch as $id ) {
		$skip[] = $id;
		if ( is_wp_error( met_fetch_cover( $id ) ) ) {
			$fail++;
		} else {
			$done++;
		}
	}

	if ( count( $todo ) > count( $batch ) ) {
		// Fortsätt med nästa omgång.
		// OBS: wp_nonce_url() HTML-kodar & till &amp; och fungerar därför inte i en omdirigering.
		wp_safe_redirect( add_query_arg( array(
			'action'   => 'met_covers',
			'mode'     => 'fetch',
			'force'    => $force ? 1 : 0,
			'd'        => $done,
			'f'        => $fail,
			'skip'     => implode( ',', $skip ),
			'_wpnonce' => wp_create_nonce( 'met_covers' ),
		), admin_url( 'admin-post.php' ) ) );
		exit;
	}

	wp_safe_redirect( add_query_arg( array( 'post_type' => MET_CPT, 'page' => 'met-import', 'covers_done' => $done, 'covers_failed' => $fail ), admin_url( 'edit.php' ) ) );
	exit;
} );

/* -------------------------------------------------------------------------
 * Gamla nummersidor → papperskorgen (med bestående omdirigering)
 * ---------------------------------------------------------------------- */

/** Gamla sidor som motsvaras av en publicerad E-tidning. */
function met_old_issue_pages() {
	$archive = met_default_archive_page();
	$out     = array();
	foreach ( met_find_candidates() as $c ) {
		if ( ! $c['is_issue'] || (int) $c['id'] === $archive || 'page' !== get_post_type( $c['id'] ) ) {
			continue;
		}
		$target = met_find_issue_post( $c['nummer'], $c['ar'] );
		$out[]  = array(
			'id'     => (int) $c['id'],
			'title'  => $c['title'],
			'path'   => met_norm_url( get_permalink( $c['id'] ) ),
			'status' => get_post_status( $c['id'] ),
			'target' => ( $target && 'publish' === get_post_status( $target ) ) ? $target : 0,
		);
	}
	return $out;
}

function met_trash_section() {
	$pages = met_old_issue_pages();
	$map   = (array) get_option( 'met_redirect_map', array() );
	?>
	<h2>Gamla nummersidor</h2>
	<?php if ( ! $pages ) : ?>
		<p>✓ Inga gamla nummersidor kvar. <?php printf( '%d gamla adresser omdirigeras till de nya nummersidorna.', count( $map ) ); ?></p>
		<hr>
		<?php return; ?>
	<?php endif; ?>
	<p>De här sidorna har ett publicerat nummer i den nya funktionen. Knappen sparar en omdirigering från varje gammal adress till det nya numret och flyttar sedan sidan till papperskorgen. Omdirigeringen fortsätter att fungera även när papperskorgen töms. Sidor utan publicerat nummer hoppas över.</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'met_trash_old' ); ?>
		<input type="hidden" name="action" value="met_trash_old">
		<table class="widefat striped" style="max-width:900px">
			<thead><tr><th>Gammal sida</th><th>Adress</th><th>Status</th><th>Omdirigeras till</th></tr></thead>
			<tbody>
			<?php foreach ( $pages as $p ) : ?>
				<tr>
					<td><?php echo esc_html( $p['title'] ); ?></td>
					<td><code><?php echo esc_html( $p['path'] ); ?></code></td>
					<td><?php echo esc_html( $p['status'] ); ?></td>
					<td><?php echo $p['target'] ? '<a href="' . esc_url( get_permalink( $p['target'] ) ) . '" target="_blank">' . esc_html( wp_parse_url( get_permalink( $p['target'] ), PHP_URL_PATH ) ) . '</a>' : '<span style="color:#b32d2e">Inget publicerat nummer – hoppas över</span>'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php submit_button( 'Flytta gamla nummersidor till papperskorgen', 'primary', 'submit', false ); ?>
	</form>
	<hr>
	<?php
}

add_action( 'admin_post_met_trash_old', function () {
	check_admin_referer( 'met_trash_old' );
	if ( ! current_user_can( 'delete_pages' ) ) {
		wp_die( 'Behörighet saknas.' );
	}
	$map     = (array) get_option( 'met_redirect_map', array() );
	$trashed = 0;
	foreach ( met_old_issue_pages() as $p ) {
		if ( ! $p['target'] ) {
			continue;
		}
		$map[ $p['path'] ] = (int) $p['target']; // Spara omdirigeringen FÖRST.
		update_option( 'met_redirect_map', $map, false );
		if ( wp_trash_post( $p['id'] ) ) {
			$trashed++;
		}
	}
	wp_safe_redirect( add_query_arg( array( 'post_type' => MET_CPT, 'page' => 'met-import', 'trashed' => $trashed ), admin_url( 'edit.php' ) ) );
	exit;
} );

// Bestående omdirigering från gamla adresser (fungerar även när sidan ligger i papperskorgen eller är raderad).
add_action( 'template_redirect', function () {
	if ( ! is_404() ) {
		return;
	}
	$map = (array) get_option( 'met_redirect_map', array() );
	if ( ! $map ) {
		return;
	}
	$path = met_norm_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '' );
	if ( ! empty( $map[ $path ] ) && 'publish' === get_post_status( $map[ $path ] ) ) {
		wp_safe_redirect( get_permalink( $map[ $path ] ), 301 );
		exit;
	}
}, 1 );
