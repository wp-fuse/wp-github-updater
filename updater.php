<?php

defined( 'ABSPATH' ) || exit;

if( ! class_exists( 'WPFuse_GitHub_Updater' ) ) {

	class WPFuse_GitHub_Updater {
		
		const CACHE_TTL = 21600; // 6 horas
		const ERROR_TTL = 1800;  // 30 minutos
		
		protected $plugin_file;
		protected $plugin_slug;
		protected $plugin_data;
		protected $github_repo;
		protected $github_token;
		protected $memory_cache = null;
		public $cache_key;
		
		public function __construct( $plugin_file, $github_token = '' ) {
			
			$this->plugin_file = $plugin_file;
			$this->plugin_slug = basename( dirname( $plugin_file ) );
			$this->cache_key   = $this->plugin_slug . '_upd';
			$this->github_token = $github_token;
			
			// Se o token não for passado no construtor, tenta pegar de uma constante global do wp-config.php
			if ( empty( $this->github_token ) && defined( 'GITHUB_UPDATER_TOKEN' ) ) {
				$this->github_token = GITHUB_UPDATER_TOKEN;
			}
			
			// Load plugin data natively and lightly without relying on wp-admin functions
			$this->plugin_data = get_file_data( $plugin_file, array(
				'Version'     => 'Version',
				'Name'        => 'Plugin Name',
				'Author'      => 'Author',
				'AuthorURI'   => 'Author URI',
				'RequiresWP'  => 'Requires at least',
				'RequiresPHP' => 'Requires PHP',
				'GitHubURI'   => 'GitHub Plugin URI',
			), 'plugin' );
			
			// Obtém o repositório exclusivamente pelo cabeçalho do plugin principal
			if ( ! empty( $this->plugin_data['GitHubURI'] ) ) {
				$repo = str_replace( array( 'https://github.com/', 'http://github.com/' ), '', $this->plugin_data['GitHubURI'] );
				$this->github_repo = trim( $repo, '/' );
			}
			
			add_filter( 'plugins_api', array( $this, 'info' ), 20, 3 );
			add_filter( 'site_transient_update_plugins', array( $this, 'update' ) );
			add_action( 'upgrader_process_complete', array( $this, 'purge' ), 10, 2 );
			add_filter( 'upgrader_source_selection', array( $this, 'rename_github_dir' ), 10, 4 );
			
			// Se tiver token, injeta na requisição nativa de download do WordPress (passa o token pro pacote zip privado)
			if ( ! empty( $this->github_token ) ) {
				add_filter( 'http_request_args', array( $this, 'inject_github_token' ), 10, 2 );
			}
		}
		
		public function inject_github_token( $parsed_args, $url ) {
			// Injeta o cabeçalho Bearer somente se a requisição for pro nosso repo do Github 
			if ( strpos( $url, 'api.github.com/repos/' . $this->github_repo ) !== false ) {
				$parsed_args['headers']['Authorization'] = 'Bearer ' . $this->github_token;
			}
			return $parsed_args;
		}
		
		public function rename_github_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
			global $wp_filesystem;
			
			if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== plugin_basename( $this->plugin_file ) ) {
				return $source;
			}
			
			if ( strpos( basename( $source ), $this->plugin_slug ) === false || basename( $source ) === $this->plugin_slug ) {
				return $source;
			}
			
			if ( ! is_object( $wp_filesystem ) ) {
				return $source;
			}
			
			$new_source = trailingslashit( $remote_source ) . $this->plugin_slug . '/';
			
			if ( ! $wp_filesystem->move( $source, $new_source ) ) {
				return new WP_Error( 'rename_failed', 'Não foi possível renomear a pasta do plugin baixado do GitHub.' );
			}
			
			return $new_source;
		}
		
		public function request() {
			
			if ( $this->memory_cache !== null ) {
				return $this->memory_cache === 'error' ? false : $this->memory_cache;
			}
			
			$remote = get_transient( $this->cache_key );
			
			if ( 'error' === $remote ) {
				$this->memory_cache = 'error';
				return false;
			}
			
			if( false === $remote ) {
				
				if ( empty( $this->github_repo ) ) {
					return false;
				}
				
				$url = 'https://api.github.com/repos/' . $this->github_repo . '/releases/latest';
				
				$args = array(
					'headers' => array( 'Accept' => 'application/vnd.github.v3+json' )
				);
				
				if ( ! empty( $this->github_token ) ) {
					$args['headers']['Authorization'] = 'Bearer ' . $this->github_token;
				}
				
				$response = wp_remote_get( $url, $args );
				
				if( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
					set_transient( $this->cache_key, 'error', self::ERROR_TTL );
					$this->memory_cache = 'error';
					return false;
				}
				
				$github_data = json_decode( wp_remote_retrieve_body( $response ) );
				
				if( empty( $github_data ) || empty( $github_data->tag_name ) ) {
					set_transient( $this->cache_key, 'error', self::ERROR_TTL );
					$this->memory_cache = 'error';
					return false;
				}
				
				$remote = new stdClass();
				
				$remote->name           = $this->plugin_data['Name'];
				$remote->slug           = $this->plugin_slug;
				$remote->version        = ltrim( $github_data->tag_name, 'v' );
				$remote->tested         = ''; // Pode ficar vazio
				$remote->requires       = $this->plugin_data['RequiresWP'];
				$remote->requires_php   = $this->plugin_data['RequiresPHP'];
				$remote->author         = $this->plugin_data['Author'];
				$remote->author_profile = $this->plugin_data['AuthorURI'];
				$remote->download_url   = $github_data->zipball_url;
				$remote->last_updated   = $github_data->published_at;
				
				$remote->sections = new stdClass();
				$remote->sections->description = 'Atualização do plugin ' . $this->plugin_data['Name'];
				$remote->sections->changelog = nl2br( $github_data->body );
				
				set_transient( $this->cache_key, $remote, self::CACHE_TTL );
			}
			
			$this->memory_cache = $remote;
			return $remote;
		}
		
		function info( $res, $action, $args ) {
			
			if( 'plugin_information' !== $action ) {
				return $res;
			}
			
			if( empty( $args->slug ) || $this->plugin_slug !== $args->slug ) {
				return $res;
			}
			
			$remote = $this->request();
			
			if( ! $remote ) {
				return $res;
			}
			
			$res = new stdClass();
			
			$res->name           = $remote->name;
			$res->slug           = $remote->slug;
			$res->version        = $remote->version;
			$res->tested         = $remote->tested;
			$res->requires       = $remote->requires;
			$res->author         = $remote->author;
			$res->author_profile = $remote->author_profile;
			$res->download_link  = $remote->download_url;
			$res->trunk          = $remote->download_url;
			$res->requires_php   = $remote->requires_php;
			$res->last_updated   = $remote->last_updated;
			
			$res->sections = array(
				'description' => $remote->sections->description,
				'changelog'   => $remote->sections->changelog
			);
			
			return $res;
		}
		
		public function update( $transient ) {
			
			if ( empty($transient->checked ) ) {
				return $transient;
			}
			
			$remote = $this->request();
			
			if( $remote && version_compare( $this->plugin_data['Version'], $remote->version, '<' ) ) {
				
				$res = new stdClass();
				$res->slug        = $this->plugin_slug;
				$res->plugin      = plugin_basename( $this->plugin_file );
				$res->new_version = $remote->version;
				$res->tested      = $remote->tested;
				$res->package     = $remote->download_url;
				
				$transient->response[ $res->plugin ] = $res;
			}
			
			return $transient;
		}
		
		public function purge( $upgrader, $options = array() ) {
			if ( is_array( $options ) && isset( $options['action'], $options['type'] ) ) {
				if ( 'update' === $options['action'] && 'plugin' === $options['type'] ) {
					$plugins = isset( $options['plugins'] ) ? $options['plugins'] : array();
					$plugin  = isset( $options['plugin'] ) ? array( $options['plugin'] ) : array();
					$updated = array_merge( $plugins, $plugin );
					
					if ( in_array( plugin_basename( $this->plugin_file ), $updated ) ) {
						delete_transient( $this->cache_key );
					}
				}
			}
		}
	}
}
