<?php
if( !defined( 'Sht_STORE_COOKIE' ) )
	define( 'Sht_STORE_COOKIE', '_Sht_store' );
if ( !class_exists( 'Recursive_ArrayAccess' ) ) {
	class Recursive_ArrayAccess implements ArrayAccess {
		protected $container = array();
		protected $dirty = false;
		protected function __construct( $data = array() ) {
			foreach ( $data as $key => $value ) {
				$this[ $key ] = $value;
			}
		}
		public function __clone() {
			foreach ( $this->container as $key => $value ) {
				if ( $value instanceof self ) {
					$this[ $key ] = clone $value;
				}
			}
		}
		public function toArray() {
			$data = $this->container;
			foreach ( $data as $key => $value ) {
				if ( $value instanceof self ) {
					$data[ $key ] = $value->toArray();
				}
			}
			return $data;
		}
		public function offsetExists( $offset ) {
			return isset( $this->container[ $offset ]) ;
		}
		public function offsetGet( $offset ) {
			return isset( $this->container[ $offset ] ) ? $this->container[ $offset ] : null;
		}
		public function offsetSet( $offset, $data ) {
			if ( is_array( $data ) ) {
				$data = new self( $data );
			}
			if ( $offset === null ) { 
				$this->container[] = $data;
			}
			else {
				$this->container[ $offset ] = $data;
			}
			$this->dirty = true;
		}
		public function offsetUnset( $offset ) {
			unset( $this->container[ $offset ] );
			$this->dirty = true;
		}
	}
}
if ( !class_exists( 'Sht_Store' ) ) {
	final class Sht_Store extends Recursive_ArrayAccess implements Iterator, Countable {
		protected $store_id;
		protected $expires;
		protected $exp_variant;
		private static $instance = false;
		public static function get_instance() {
			if ( !self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}
		protected function __construct() {
			if ( isset( $_COOKIE[Sht_STORE_COOKIE] ) ) {
				$cookie = stripslashes( $_COOKIE[Sht_STORE_COOKIE] );
				$cookie_crumbs = explode( '||', $cookie );
				$this->store_id = $cookie_crumbs[0];
				$this->expires = $cookie_crumbs[1];
				$this->exp_variant = $cookie_crumbs[2];
				if ( time() > $this->exp_variant ) {
					$this->set_expiration();
					delete_option( "_Sht_store_expires_{$this->store_id}" );
					add_option( "_Sht_store_expires_{$this->store_id}", $this->expires, '', 'no' );
				}
			}
			else {
				$this->store_id = $this->generate_id();
				$this->set_expiration();
			}
			$this->read_data();
			$this->set_cookie();
		}
		protected function set_expiration() {
			$this->exp_variant = time() + (int) apply_filters( 'Sht_store_expiration_variant', 24 * 60 );
			$this->expires = time() + (int) apply_filters( 'Sht_store_expiration', 30 * 60 );
		}
		protected function set_cookie(){
			if( !headers_sent() )
				setcookie(Sht_STORE_COOKIE,$this->store_id.'||'.$this->expires.'||'.$this->exp_variant,$this->expires,COOKIEPATH,COOKIE_DOMAIN );
		}
		protected function generate_id() {
			require_once( ABSPATH . 'wp-includes/class-phpass.php');
			$hasher = new PasswordHash( 8, false );
			return md5( $hasher->get_random_bytes( 32 ) );
		}
		protected function read_data() {
			$this->container = get_option( "_Sht_store_{$this->store_id}", array() );
			return $this->container;
		}
		public function write_data() {
			$option_key = "_Sht_store_{$this->store_id}";
			if ( $this->dirty ) {
				if ( false === get_option( $option_key ) ) {
					add_option( "_Sht_store_{$this->store_id}", $this->container, '', 'no' );
					add_option( "_Sht_store_expires_{$this->store_id}", $this->expires, '', 'no' );
				} 
				else {
					delete_option( "_Sht_store_{$this->store_id}" );
					add_option( "_Sht_store_{$this->store_id}", $this->container, '', 'no' );
				}
			}
		}
		public function json_out() {
			return json_encode( $this->container );
		}
		public function json_in( $data ) {
			$array = json_decode( $data );
			if ( is_array( $array ) ) {
				$this->container = $array;
				return true;
			}
			return false;
		}
		public function regenerate_id( $delete_old = false ) {
			if ( $delete_old ) {
				delete_option( "_Sht_store_{$this->store_id}" );
			}
			$this->store_id = $this->generate_id();
			$this->set_cookie();
		}
		public function store_started() {
			return !!self::$instance;
		}
		public function cache_expiration() {
			return $this->expires;
		}
		public function reset() {
			$this->container = array();
		}
		public function current() {
			return current( $this->container );
		}
		public function key() {
			return key( $this->container );
		}
		public function next() {
			next( $this->container );
		}
		public function rewind() {
			reset( $this->container );
		}
		public function valid() {
			return $this->offsetExists( $this->key() );
		}
		public function count() {
			return count( $this->container );
		}
	}
	function Sht_store_cache_expire() {
		$Sht_store = Sht_Store::get_instance();
		return $Sht_store->cache_expiration();
	}
	function Sht_store_commit() {
		Sht_store_write_close();
	}
	function Sht_store_decode( $data ) {
		$Sht_store = Sht_Store::get_instance();
		return $Sht_store->json_in( $data );
	}
	function Sht_store_encode() {
		$Sht_store = Sht_Store::get_instance();
		return $Sht_store->json_out();
	}
	function Sht_store_regenerate_id( $delete_old_store = false ) {
		$Sht_store = Sht_Store::get_instance();
		$Sht_store->regenerate_id( $delete_old_store );
		return true;
	}
	function Sht_store_start() {
		$Sht_store = Sht_Store::get_instance();
		do_action( 'Sht_store_start' );
		return $Sht_store->store_started();
	}
	add_action( 'plugins_loaded', 'Sht_store_start' );
	function Sht_store_status() {
		$Sht_store = Sht_Store::get_instance();
		if ( $Sht_store->store_started() ) {
			return PHP_STORE_ACTIVE;
		}
		return PHP_STORE_NONE;
	}
	function Sht_store_unset() {
		$Sht_store = Sht_Store::get_instance();
		$Sht_store->reset();
	}
	function Sht_store_write_close() {
		$Sht_store = Sht_Store::get_instance();
		$Sht_store->write_data();
		do_action( 'Sht_store_commit' );
	}
	add_action( 'shutdown', 'Sht_store_write_close' );
	function Sht_store_cleanup() {
		global $wpdb;
		if ( defined( 'Sht_SETUP_CONFIG' ) ) {
			return;
		}
		if ( ! defined( 'Sht_INSTALLING' ) ) {
			$expiration_keys = $wpdb->get_results( "SELECT option_name, option_value FROM $wpdb->options WHERE option_name LIKE '_Sht_store_expires_%'" );
			$now = time();
			$expired_stores = array();
			foreach( $expiration_keys as $expiration ) {
				if ( $now > intval( $expiration->option_value ) ) {
					$store_id = substr( $expiration->option_name, 20 );
					$expired_stores[] = $expiration->option_name;
					$expired_stores[] = "_Sht_store_$store_id";
				}
			}
			if ( ! empty( $expired_stores ) ) {
				$option_names = implode( "','", $expired_stores );
				$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name IN ('$option_names')" );
			}
		}
		do_action( 'Sht_store_cleanup' );
	}
	add_action( 'Sht_store_garbage_collection', 'Sht_store_cleanup' );
	function Sht_store_register_garbage_collection() {
		if ( !wp_next_scheduled( 'Sht_store_garbage_collection' ) ) {
			wp_schedule_event( time(), 'hourly', 'Sht_store_garbage_collection' );
		}
	}
	add_action( 'wp', 'Sht_store_register_garbage_collection' );
}
?>