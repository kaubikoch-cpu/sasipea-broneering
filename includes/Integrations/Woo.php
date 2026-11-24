<?php
namespace SB\Integrations;

use SB\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Sidumine WooCommerce'i veebipoega (nt sasipea.ee) selle REST API kaudu (wc/v3).
 *
 * - Toodete otsing nime, triipkoodi (WooCommerce'i GTIN/EAN väli) või tootekoodi (SKU) järgi koos
 *   hinna ja laoseisuga. Otsing käib kohalikust tootenimekirja koopiast (sync), sest REST API
 *   ei luba kõigis WooCommerce'i versioonides GTIN välja järgi filtreerida; hind ja laoseis
 *   küsitakse alati värskelt poest.
 * - Salongis müüdud toode salvestatakse poodi lõpetatud tellimusena, nii et WooCommerce
 *   arvab koguse ise laost maha ja müük on poe aruannetes.
 * - Müügirea eemaldamisel tellimus tühistatakse ja WooCommerce taastab laoseisu.
 *
 * API-võti luuakse poe adminis (WooCommerce → Seaded → Täpsemalt → REST API, õigus Read/Write)
 * ja sisestatakse plugina seadetesse. Ühendus käib ainult üle HTTPS-i.
 */
final class Woo {

	public static function enabled(): bool {
		$s = Settings::get();
		return '' !== (string) $s['woo_url'] && '' !== (string) $s['woo_key'] && '' !== (string) $s['woo_secret'];
	}

	/** Lubatud on ainult https; kohalik arendusserver (localhost) võib olla http. */
	public static function valid_url( string $url ): bool {
		$p = wp_parse_url( $url );
		if ( empty( $p['host'] ) || empty( $p['scheme'] ) ) {
			return false;
		}
		if ( 'https' === $p['scheme'] ) {
			return true;
		}
		return 'http' === $p['scheme'] && in_array( $p['host'], array( 'localhost', '127.0.0.1' ), true );
	}

	/**
	 * @return array|\WP_Error dekodeeritud JSON
	 */
	private static function request( string $method, string $path, array $query = array(), ?array $body = null, ?array &$meta = null ) {
		$s = Settings::get();
		if ( ! self::enabled() || ! self::valid_url( (string) $s['woo_url'] ) ) {
			return new \WP_Error( 'sb_woo_off', __( 'Veebipoe ühendus pole seadistatud.', 'sasipea-broneering' ), array( 'status' => 400 ) );
		}
		$url  = untrailingslashit( (string) $s['woo_url'] ) . '/wp-json/wc/v3/' . ltrim( $path, '/' );
		$url  = $query ? add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), $url ) : $url;
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $s['woo_key'] . ':' . $s['woo_secret'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- HTTP Basic auth.
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'sb_woo_down', __( 'Veebipoega ei saanud ühendust. Proovi uuesti.', 'sasipea-broneering' ), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$msg = is_array( $data ) && ! empty( $data['message'] ) ? wp_strip_all_tags( (string) $data['message'] ) : 'HTTP ' . $code;
			/* translators: %s: error message from the shop */
			return new \WP_Error( 'sb_woo_error', sprintf( __( 'Veebipood vastas veaga: %s', 'sasipea-broneering' ), $msg ), array( 'status' => 502 ) );
		}
		$meta = array(
			'total' => (int) wp_remote_retrieve_header( $res, 'x-wp-total' ),
			'pages' => (int) wp_remote_retrieve_header( $res, 'x-wp-totalpages' ),
		);
		return $data;
	}

	/** Ühenduse kontroll seadete lehel: loeb ühe toote. */
	public static function test() {
		$r = self::request( 'GET', 'products', array( 'per_page' => 1 ) );
		return is_wp_error( $r ) ? $r : true;
	}

	private static function cents( $price ): int {
		return (int) round( (float) $price * 100 );
	}

	/** Poe toode või variatsioon ühtsel kujul. */
	private static function item( array $p, ?array $parent = null ): array {
		$is_var = null !== $parent || 'variation' === ( $p['type'] ?? '' );
		$name   = (string) ( $p['name'] ?? '' );
		if ( $parent ) {
			$opts = array_filter( array_map( static fn( $a ) => (string) ( $a['option'] ?? '' ), (array) ( $p['attributes'] ?? array() ) ) );
			$name = $parent['name'] . ( $opts ? ' – ' . implode( ', ', $opts ) : '' );
		}
		$pid   = $is_var ? (int) ( $parent['id'] ?? $p['parent_id'] ?? 0 ) : (int) $p['id'];
		$vid   = $is_var ? (int) $p['id'] : 0;
		$qty   = isset( $p['stock_quantity'] ) && null !== $p['stock_quantity'] ? (int) $p['stock_quantity'] : null;
		$in    = 'outofstock' !== ( $p['stock_status'] ?? 'instock' );
		$price = self::cents( $p['price'] ?? 0 );
		return array(
			'key'         => 'woo:' . $pid . ':' . $vid,
			'name'        => wp_strip_all_tags( $name ),
			'sku'         => (string) ( $p['sku'] ?? '' ),
			'gtin'        => (string) ( $p['global_unique_id'] ?? '' ),
			'price_cents' => $price,
			'price_label' => Settings::money( $price ),
			'stock'       => $qty,
			/* translators: %d: pieces in stock */
			'stock_label' => null !== $qty ? sprintf( __( 'laos %d', 'sasipea-broneering' ), $qty ) : ( $in ? __( 'laos', 'sasipea-broneering' ) : __( 'otsas', 'sasipea-broneering' ) ),
			'in_stock'    => $in && ( null === $qty || $qty > 0 ),
		);
	}

	public const INDEX = 'sb_woo_index';

	/** @return array{time:int,items:list<array{p:int,v:int,n:string,s:string,g:string}>} */
	public static function index(): array {
		$i = get_option( self::INDEX );
		return is_array( $i ) && isset( $i['items'] ) ? $i : array( 'time' => 0, 'items' => array() );
	}

	/**
	 * Laeb poe tootenimekirja (nimi, tootekood, triipkood) kohalikku koopiasse. Ainult loeb poest.
	 *
	 * @return int|\WP_Error kirjete arv
	 */
	public static function sync() {
		$items = array();
		$entry = static function ( array $p, ?array $parent = null ) use ( &$items ): void {
			$it      = self::item( $p, $parent );
			[ , $pid, $vid ] = explode( ':', $it['key'] );
			$items[] = array( 'p' => (int) $pid, 'v' => (int) $vid, 'n' => $it['name'], 's' => $it['sku'], 'g' => $it['gtin'] );
		};
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- suur pood võtab aega.
		}
		// Lehti loetakse seni, kuni pood annab tühja lehe või ütleb, et lehed on otsas. Lehe suurusele
		// ei saa loota: mõni pood või lisaplugin annab vähem ridu, kui küsiti.
		$total = 0;
		$seen  = array();
		for ( $page = 1; $page <= 200; $page++ ) {
			$meta = null;
			$rows = self::request( 'GET', 'products', array( 'per_page' => 100, 'page' => $page, 'status' => 'publish', 'orderby' => 'id', 'order' => 'asc' ), null, $meta );
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			if ( ! $rows ) {
				break;
			}
			$first = (int) ( $rows[0]['id'] ?? 0 );
			if ( isset( $seen[ $first ] ) ) {
				// Pood eirab lehe numbrit ja annab sama lehe uuesti: ei jää lõputult kordama.
				return new \WP_Error( 'sb_woo_paging', __( 'Veebipood ei luba tootenimekirja lehekaupa lugeda (sama leht kordub).', 'sasipea-broneering' ), array( 'status' => 502 ) );
			}
			$seen[ $first ] = true;
			$total          = max( $total, (int) ( $meta['total'] ?? 0 ) );
			foreach ( $rows as $p ) {
				if ( 'variable' === ( $p['type'] ?? '' ) ) {
					$vars = self::request( 'GET', 'products/' . (int) $p['id'] . '/variations', array( 'per_page' => 100 ) );
					if ( is_wp_error( $vars ) ) {
						return $vars;
					}
					foreach ( $vars as $v ) {
						$entry( $v, $p );
					}
				} elseif ( in_array( $p['type'] ?? 'simple', array( 'simple', 'external' ), true ) ) {
					$entry( $p );
				}
			}
			if ( ! empty( $meta['pages'] ) && $page >= $meta['pages'] ) {
				break;
			}
		}
		update_option( self::INDEX, array( 'time' => time(), 'items' => $items, 'shop_total' => $total, 'pages' => count( $seen ) ), false );
		return count( $items );
	}

	/** Triipkoodi võrdlus: ainult numbrid ja tähed, ilma tühikute ja kriipsudeta. */
	private static function code( string $s ): string {
		return strtolower( (string) preg_replace( '/[^0-9a-z]/i', '', $s ) );
	}

	/**
	 * Otsing kohalikust koopiast: täpne triipkood, siis täpne tootekood, siis nimi ja koodi algus.
	 * Puhas funktsioon, et seda saaks eraldi testida.
	 *
	 * @param list<array{p:int,v:int,n:string,s:string,g:string}> $items
	 * @return list<array> koopia kirjed koos väljaga 'exact' (bool)
	 */
	public static function match( array $items, string $q, int $limit = 10 ): array {
		$code  = self::code( $q );
		$words = array_filter( preg_split( '/\s+/', mb_strtolower( trim( $q ) ) ) );
		$rank  = array();
		foreach ( $items as $i => $it ) {
			$g = self::code( $it['g'] );
			$s = self::code( $it['s'] );
			if ( '' !== $code && $g === $code ) {
				$rank[ $i ] = 0;
			} elseif ( '' !== $code && $s === $code ) {
				$rank[ $i ] = 1;
			} else {
				$name = mb_strtolower( $it['n'] );
				$all  = (bool) $words;
				foreach ( $words as $w ) {
					if ( false === mb_strpos( $name, $w ) ) {
						$all = false;
						break;
					}
				}
				if ( $all ) {
					$rank[ $i ] = 2;
				} elseif ( strlen( $code ) >= 3 && ( str_starts_with( $s, $code ) || str_starts_with( $g, $code ) ) ) {
					$rank[ $i ] = 3;
				}
			}
		}
		asort( $rank );
		$out = array();
		foreach ( array_slice( $rank, 0, $limit, true ) as $i => $r ) {
			$out[] = $items[ $i ] + array( 'exact' => $r <= 1 );
		}
		return $out;
	}

	/**
	 * Otsib tooteid nime, triipkoodi või tootekoodi järgi. Variatsioonidega toote iga variatsioon
	 * on eraldi vaste. Täpne koodivaste on esimene ja märgitud väljaga 'exact'.
	 *
	 * @return list<array>|\WP_Error
	 */
	public static function search( string $q ) {
		$q = trim( $q );
		if ( mb_strlen( $q ) < 2 ) {
			return array();
		}
		$index = self::index();
		if ( $index['items'] ) {
			$hits = self::match( $index['items'], $q );
			$out  = array();
			// Hind ja laoseis värskelt poest: lihttooted ühe päringuga, variatsioonid ükshaaval.
			$simple = array_values( array_unique( array_column( array_filter( $hits, static fn( $h ) => ! $h['v'] ), 'p' ) ) );
			$live   = array();
			if ( $simple ) {
				$rows = self::request( 'GET', 'products', array( 'include' => implode( ',', $simple ), 'per_page' => 100 ) );
				if ( is_wp_error( $rows ) ) {
					return $rows;
				}
				foreach ( $rows as $p ) {
					$live[ (int) $p['id'] ] = $p;
				}
			}
			foreach ( $hits as $h ) {
				if ( $h['v'] ) {
					$v = self::request( 'GET', 'products/' . $h['p'] . '/variations/' . $h['v'] );
					if ( is_wp_error( $v ) ) {
						continue;
					}
					$item         = self::item( $v, array( 'id' => $h['p'], 'name' => $h['n'] ) );
					$item['name'] = $h['n'];
				} elseif ( isset( $live[ $h['p'] ] ) ) {
					$item = self::item( $live[ $h['p'] ] );
				} else {
					continue; // toode on poest vahepeal kadunud
				}
				$out[] = $item + array( 'exact' => $h['exact'] );
			}
			return $out;
		}
		$out  = array();
		$seen = array();
		$add  = static function ( array $item ) use ( &$out, &$seen ): void {
			if ( ! isset( $seen[ $item['key'] ] ) ) {
				$seen[ $item['key'] ] = true;
				$out[]                = $item;
			}
		};
		$exact = self::request( 'GET', 'products', array( 'sku' => $q, 'per_page' => 5 ) );
		if ( is_wp_error( $exact ) ) {
			return $exact;
		}
		$found = self::request( 'GET', 'products', array( 'search' => $q, 'per_page' => 10, 'status' => 'publish' ) );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		$expanded = 0;
		foreach ( array_merge( $exact, $found ) as $p ) {
			if ( 'variable' === ( $p['type'] ?? '' ) ) {
				if ( $expanded++ >= 3 ) {
					continue;
				}
				$vars = self::request( 'GET', 'products/' . (int) $p['id'] . '/variations', array( 'per_page' => 50 ) );
				foreach ( is_wp_error( $vars ) ? array() : $vars as $v ) {
					$add( self::item( $v, $p ) );
				}
				continue;
			}
			if ( 'variation' === ( $p['type'] ?? '' ) ) {
				$parent = self::request( 'GET', 'products/' . (int) $p['parent_id'] );
				$add( self::item( $p, is_wp_error( $parent ) ? array( 'id' => (int) $p['parent_id'], 'name' => (string) $p['name'] ) : $parent ) );
				continue;
			}
			if ( in_array( $p['type'] ?? 'simple', array( 'simple', 'external' ), true ) ) {
				$add( self::item( $p ) );
			}
		}
		// Koopiata varuvariant leiab ainult nime ja tootekoodi järgi. Täpne koodivaste ettepoole.
		foreach ( $out as $k => $it ) {
			$out[ $k ]['exact'] = $it['sku'] === $q || ( '' !== $it['gtin'] && $it['gtin'] === $q );
		}
		usort( $out, static fn( $a, $b ) => $b['exact'] <=> $a['exact'] );
		return $out;
	}

	/**
	 * Loob poodi lõpetatud tellimuse ühe tootega. WooCommerce arvab koguse laost maha.
	 *
	 * @return array{order_id:int,name:string,sku:string,total_cents:int}|\WP_Error
	 */
	public static function sell( int $product_id, int $variation_id, int $qty, string $note ) {
		$path = $variation_id ? 'products/' . $product_id . '/variations/' . $variation_id : 'products/' . $product_id;
		$p    = self::request( 'GET', $path );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$parent = null;
		if ( $variation_id ) {
			$parent = self::request( 'GET', 'products/' . $product_id );
			if ( is_wp_error( $parent ) ) {
				return $parent;
			}
		}
		$item = self::item( $p, $parent );
		if ( null !== $item['stock'] && $item['stock'] < $qty ) {
			/* translators: 1: product name, 2: pieces in stock */
			return new \WP_Error( 'sb_woo_stock', sprintf( __( '%1$s: laos on ainult %2$d tk.', 'sasipea-broneering' ), $item['name'], $item['stock'] ), array( 'status' => 409 ) );
		}
		$line = array( 'product_id' => $product_id, 'quantity' => $qty );
		if ( $variation_id ) {
			$line['variation_id'] = $variation_id;
		}
		$order = self::request(
			'POST',
			'orders',
			array(),
			array(
				'status'               => 'completed',
				'set_paid'             => true,
				'payment_method'       => 'other',
				'payment_method_title' => __( 'Makstud salongis', 'sasipea-broneering' ),
				'customer_note'        => $note,
				'line_items'           => array( $line ),
				'meta_data'            => array( array( 'key' => '_sb_salon_sale', 'value' => '1' ) ),
			)
		);
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$total = isset( $order['total'] ) ? self::cents( $order['total'] ) : $item['price_cents'] * $qty;
		return array(
			'order_id'    => (int) ( $order['id'] ?? 0 ),
			'name'        => $item['name'],
			'sku'         => $item['sku'],
			'total_cents' => $total,
		);
	}

	/** Tühistab tellimuse; WooCommerce taastab laoseisu. */
	public static function cancel( int $order_id ) {
		$r = self::request( 'PUT', 'orders/' . $order_id, array(), array( 'status' => 'cancelled' ) );
		return is_wp_error( $r ) ? $r : true;
	}
}
