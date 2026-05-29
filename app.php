<?php
/*
Plugin Name: MDMAP
Description: Point any extra domain or subdomain at a WordPress page, post, or archive — without redirects. The mapped domain always stays in the visitor's address bar.
Version:     2.0
Author:      Jeff Caldwell
License:     GPL3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: mdmap_app
Domain Path: /languages

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
*/

// If this file is called directly, abort.
if( !defined( 'ABSPATH' ) ){
	die('...');
}
// support for older php versions
if( !defined( 'PHP_INT_MIN' ) ){
	define('PHP_INT_MIN', ~PHP_INT_MAX);
}

if( !class_exists( 'MultipleDomainMapper' ) ){
	class MultipleDomainMapper{

		//The unique instance of the plugin.
    private static $instance;

	//Gets an instance of our plugin.
    public static function get_instance(){
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

		//variables
		private $mappings = false;
		private $settings = false;
		private $originalRequestURI = false;
		private $currentURI = false;
		private $currentMapping = array(
			'match' => false,
			'factor' => PHP_INT_MIN
		);
		private $saveMappingsButtonDisabled = false;
		private $pluginVersion = '2.0';
		private $pluginBasename;
		private $menuHookSuffix;
		private $homeURLMatchLength;
		private $siteHost;
		private $mappedHost = null;

		//constructor
	  private function __construct(){
			$this->pluginBasename     = plugin_basename(__FILE__);
			$this->homeURLMatchLength = strlen(str_ireplace('http://', '', str_ireplace('https://', '', str_ireplace('www.', '', get_home_url()))));
			$this->siteHost           = parse_url(get_site_url(), PHP_URL_HOST);

			//retrieve options
			$this->setMappings(get_option('mdmap_app_mappings'));
			$this->setSettings(get_option('mdmap_app_settings'));

			//backend
	  	add_action( 'plugins_loaded', array( $this, 'set_textdomain' ) );
			add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );

			//set current uri
			$phpServerVar = (!empty($this->getSettings()) && isset($this->getSettings()['php_server'])) ? $this->getSettings()['php_server'] : 'HTTP_HOST';
		// Fall back to HTTP_HOST when SERVER_NAME doesn't reflect the actual requested host (e.g. behind a proxy or with domain aliases)
		if( $phpServerVar === 'SERVER_NAME' && !empty($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== $_SERVER['SERVER_NAME'] ){
			$phpServerVar = 'HTTP_HOST';
		}
		$this->setCurrentURI($_SERVER[$phpServerVar] . $_SERVER['REQUEST_URI']);

			//process request
			add_filter( 'do_parse_request', array( $this, 'parse_request' ), 10, 3 );
			add_filter( 'redirect_canonical', array( $this, 'check_canonical_redirect' ), 10, 2 );
			add_action( 'template_redirect', array( $this, 'handle_redirect' ), 1 );

			//some hooks to change occurences of orignal domain to mapped domain
			$this->replace_uris();

			//hook some stuff into our own actions
			add_action( 'plugins_loaded', array( $this, 'hookMDMAction'), 20);

			//html head
			add_action('wp_head', array( $this, 'output_custom_head_code' ), 20);
			//canonical tag, noindex + per-mapping tracking snippet
			add_action('wp_head', array( $this, 'output_canonical_tag' ), 1);
			add_action('wp_head', array( $this, 'output_noindex_tag' ), 2);
			add_action('wp_head', array( $this, 'output_tracking_snippet' ), 5);
			//admin bar badge showing the active mapped domain
			add_action('admin_bar_menu', array( $this, 'admin_bar_badge' ), 100);
			//open graph url replacement (Yoast + RankMath)
			add_filter('wpseo_opengraph_url', array( $this, 'replace_og_url' ), 10);
			add_filter('rank_math/opengraph/facebook/og_url', array( $this, 'replace_og_url' ), 10);
			//per-mapping site name / tagline / og image overrides (only fire when on a mapped domain)
			add_filter('pre_option_blogname', array( $this, 'override_blogname' ));
			add_filter('pre_option_blogdescription', array( $this, 'override_blogdescription' ));
			add_filter('wpseo_replacements', array( $this, 'override_yoast_replacements' ));
			add_filter('wpseo_opengraph_site_name', array( $this, 'override_og_site_name' ));
			add_filter('rank_math/opengraph/facebook/og_site_name', array( $this, 'override_og_site_name' ));
			add_filter('wpseo_opengraph_image', array( $this, 'override_og_image' ));
			add_filter('rank_math/opengraph/facebook/og_image', array( $this, 'override_og_image' ));
			add_filter('wpseo_twitter_image', array( $this, 'override_og_image' ));
			add_filter('rank_math/opengraph/twitter/twitter_image', array( $this, 'override_og_image' ));
			//canonical url replacement for SEO plugins (we suppress our own tag when one of these is active)
			add_filter('wpseo_canonical', array( $this, 'replace_canonical' ), 10);
			add_filter('rank_math/frontend/canonical', array( $this, 'replace_canonical' ), 10);
			//keep home/search links on the mapped domain (front-end of a mapped page only; self-guarded)
			add_filter('home_url', array( $this, 'replace_home_url' ), 10, 3);
			//rest api response domain replacement
			add_filter('rest_post_dispatch', array( $this, 'rest_response_replace' ), 10, 3);
			//flush all supported page caches when mappings or settings change
			add_action('updated_option', array( $this, 'maybe_flush_caches' ), 10, 3);
			//per-mapping robots.txt sitemap override
			add_filter('robots_txt', array( $this, 'filter_robots_txt' ), 10, 2);
			//ajax endpoints
			add_action('wp_ajax_mdmap_health_check', array( $this, 'ajax_health_check' ));
			add_action('wp_ajax_mdmap_export_mappings', array( $this, 'ajax_export_mappings' ));
			add_action('wp_ajax_mdmap_import_mappings', array( $this, 'ajax_import_mappings' ));
			//settings link on the plugins list row
			add_filter('plugin_action_links_' . $this->pluginBasename, array( $this, 'add_settings_link' ));
		  }

		//setters/getters
		private function setMappings($mappings){
			$this->mappings = $mappings;
		}
		public function getMappings(){
			return $this->mappings;
		}
		private function setSettings($settings){
			$this->settings = $settings;
		}
		public function getSettings(){
			return $this->settings;
		}
		private function setCurrentURI($uri){
			// Strip port from host portion (HTTP_HOST can include port e.g. example.com:8080)
			$uri = preg_replace('/^([^\/]+):\d+(\/|$)/', '$1$2', $uri);
			$this->currentURI = trailingslashit( $uri );
		}
		public function getCurrentURI(){
			return $this->currentURI;
		}
		private function setCurrentMapping($mapping){
			$this->currentMapping = $mapping;
			$this->mappedHost = !empty($mapping['match']['domain'])
				? (parse_url('dummyprotocol://' . $mapping['match']['domain'], PHP_URL_HOST) ?? $mapping['match']['domain'])
				: null;
		}
		public function getCurrentMapping(){
			return $this->currentMapping;
		}
		private function setOriginalRequestURI($uri){
			$this->originalRequestURI = $uri;
		}
		public function getOriginalRequestURI(){
			return $this->originalRequestURI;
		}
		public function getOriginalURI(){
			global $wp;
			return home_url( $wp->request );
		}

		//set textdomain
	  public function set_textdomain(){
			load_plugin_textdomain( 'mdmap_app', false, dirname( $this->pluginBasename ) . '/languages/' );
	  }

		//enqueue scripts and styles in admin
		public function admin_scripts($hook){
			if($hook !== $this->menuHookSuffix) return;
			//custom assets
			wp_enqueue_style( 'mdmap_app_adminstyle', plugin_dir_url( __FILE__ ) . 'assets/css/admin.css', array(), $this->pluginVersion );
			wp_register_script( 'mdmap_app_adminscript', plugin_dir_url( __FILE__ ) . 'assets/js/admin.js', array('jquery', 'jquery-ui-accordion', 'jquery-ui-sortable'), $this->pluginVersion, true );
			wp_localize_script( 'mdmap_app_adminscript', 'localizedObj', array(
				'removedMessage'  => esc_html__('This mapping will be deleted when you save. Click Undo to keep it.', 'mdmap_app'),
				'undoMessage'     => esc_html__('Undo', 'mdmap_app'),
				'dismissMessage'  => __( 'Dismiss this notice.', 'mdmap_app' ),
				'healthOk'        => esc_html__('Reachable', 'mdmap_app'),
				'healthFail'      => esc_html__('Unreachable', 'mdmap_app'),
				'healthError'     => esc_html__('Check failed', 'mdmap_app'),
				'exportLabel'     => esc_html__('Export JSON', 'mdmap_app'),
				'importSuccess'   => esc_html__('Mappings imported! Reloading…', 'mdmap_app'),
				'importError'     => esc_html__('Import failed — please check the file and try again.', 'mdmap_app'),
				'ajaxUrl'         => admin_url('admin-ajax.php'),
				'healthNonce'     => wp_create_nonce('mdmap_health_check'),
				'exportNonce'     => wp_create_nonce('mdmap_export'),
				'importNonce'     => wp_create_nonce('mdmap_import'),
			) );
			wp_enqueue_script( 'mdmap_app_adminscript' );
		}

		//generate menu entry
		public function add_menu_page(){
			// check user capabilities
	    if (!current_user_can('manage_options')) {
	        return;
	    }
			$this->menuHookSuffix = add_submenu_page( 'tools.php', esc_html__('MDMAP', 'mdmap_app'), esc_html__('MDMAP', 'mdmap_app'), 'manage_options', $this->pluginBasename, array( $this, 'output_menu_page') );
			$this->register_settings();
		}

		//add a "Settings" link to the plugin's row on the Plugins screen
		public function add_settings_link($links){
			$url = admin_url('tools.php?page=' . $this->pluginBasename);
			array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'mdmap_app') . '</a>');
			return $links;
		}

		//generate menu page output
		public function output_menu_page(){
			// check user capabilities
	    if (!current_user_can('manage_options')) {
	        return;
	    }

			//find out active tab
			$valid_tabs = array('settings', 'advanced', 'help');
			$raw_tab = isset($_GET['tab']) ? $_GET['tab'] : '';
			$active_tab = in_array($raw_tab, $valid_tabs, true) ? $raw_tab : 'mappings';
			$active_tab_name = $active_tab === 'mappings' ? esc_html__('Mappings', 'mdmap_app') : ucfirst($active_tab);

			echo '<div class="wrap mdmap_app_wrap">';

				//page title
				echo '<h1>' . get_admin_page_title() . '</h1>';

				//updated notices
				if ( isset( $_GET['settings-updated'] ) ) {
					add_settings_error( 'mdmap_app_messages', 'mdmap_app_message', sprintf(esc_html__( '%s saved successfully', 'mdmap_app' ), esc_html($active_tab_name)), 'updated' );
				}
				settings_errors( 'mdmap_app_messages' );

				//page intro
				echo '<p>' . esc_html__('Point any extra domain or subdomain at a page, post, or archive on this site — no redirects. Visitors always see the mapped domain in their address bar. New here? Check the Help tab for setup instructions.', 'mdmap_app') . '</p>';

				//tabs
				echo '<h2 class="nav-tab-wrapper">';
					echo '<a href="?page='. $this->pluginBasename .'&amp;tab=mappings" class="nav-tab ' . ($active_tab == 'mappings' ? 'nav-tab-active ' : '') . '">' . esc_html__('Mappings', 'mdmap_app') . '</a>';
					echo '<a href="?page='. $this->pluginBasename .'&amp;tab=settings" class="nav-tab ' . ($active_tab == 'settings' ? 'nav-tab-active ' : '') . '">' . esc_html__('Settings', 'mdmap_app') . '</a>';
					echo '<a href="?page='. $this->pluginBasename .'&amp;tab=advanced" class="nav-tab nav-tab-featured ' . ($active_tab == 'advanced' ? 'nav-tab-active ' : '') . '">' . esc_html__('Advanced', 'mdmap_app') . '</a>';
					echo '<a href="?page='. $this->pluginBasename .'&amp;tab=help" class="nav-tab ' . ($active_tab == 'help' ? 'nav-tab-active ' : '') . '">' . esc_html__('Help', 'mdmap_app') . '</a>';
				echo '</h2>';

				//main form
				echo '<form action="options.php" method="post">';

					//inputs based on current tab
					switch($active_tab){
						case 'settings':{
							add_settings_section(
								'mdmap_app_section_settings',
								esc_html__('Domain mapping settings', 'mdmap_app'),
								array($this, 'section_settings_callback'),
								$this->pluginBasename
							);

							add_settings_field(
								'mdmap_app_field_settings_phpserver',
								esc_html__('PHP Server Variable:', 'mdmap_app'),
								array($this, 'field_settings_phpserver_callback'),
								$this->pluginBasename,
								'mdmap_app_section_settings'
							);

							add_settings_field(
								'mdmap_app_field_settings_compatibilitymode',
								esc_html__('Compatibility mode:', 'mdmap_app'),
								array($this, 'field_settings_compatibilitymode_callback'),
								$this->pluginBasename,
								'mdmap_app_section_settings'
							);

					add_settings_field(
						'mdmap_app_field_settings_excluded_domains',
						esc_html__('Excluded domains:', 'mdmap_app'),
						array($this, 'field_settings_excluded_domains_callback'),
						$this->pluginBasename,
						'mdmap_app_section_settings'
					);

							do_action('mdmap_appa_settings_tab');

							settings_fields('mdmap_app_settings_group');
							do_settings_sections( $this->pluginBasename );
							break 1;
						}
					case 'advanced':{
						echo '<h2>' . esc_html__('Developer Hooks', 'mdmap_app') . '</h2>';
						echo '<p>' . esc_html__('MDMAP provides action and filter hooks for developers to extend its behaviour.', 'mdmap_app') . '</p>';
						echo '<ul>';
							echo '<li>' . esc_html__('Actions prefix:', 'mdmap_app') . ' <code>mdmap_appa_</code></li>';
							echo '<li>' . esc_html__('Filters prefix:', 'mdmap_app') . ' <code>mdmap_appf_</code></li>';
						echo '</ul>';
						echo '<p>' . esc_html__('Search for these prefixes in the plugin source to see all available hooks.', 'mdmap_app') . '</p>';
						break 1;
					}
					case 'help':{
						echo '<h2>' . esc_html__('Setup', 'mdmap_app') . '</h2>';
						echo '<p>' . esc_html__('Before adding any mappings, each extra domain needs to point to the same web root as your main WordPress site. Two things to set up:', 'mdmap_app') . '</p>';
						echo '<ol>';
							echo '<li>' . esc_html__('Set the A-record of each extra domain to your main site\'s IP address (done through your domain registrar\'s DNS settings).', 'mdmap_app') . '</li>';
							echo '<li>' . esc_html__('Configure your hosting to route all domains to the same WordPress directory (virtual host, domain alias, or parked domain).', 'mdmap_app') . '</li>';
						echo '</ol>';
						echo '<p>' . esc_html__('Quick test: drop a file in your WordPress root and confirm it\'s accessible from both domains before adding any mappings.', 'mdmap_app') . '</p>';
						echo '<p>' . esc_html__('Using nginx? Switch the PHP Server Variable to HTTP_HOST in the Settings tab.', 'mdmap_app') . '</p>';
						break 1;
					}
					default:{ //default is our mappings tab

							add_settings_section(
								'mdmap_app_section_mappings',
								esc_html__('Domain mappings', 'mdmap_app'),
								array($this, 'section_mappings_callback'),
								$this->pluginBasename
							);

							add_settings_field(
								'mdmap_app_field_mappings_uris',
								esc_html__('Your domain mappings:', 'mdmap_app'),
								array($this, 'field_mappings_uris_callback'),
								$this->pluginBasename,
								'mdmap_app_section_mappings'
							);
							settings_fields('mdmap_app_mappings_group');
							do_settings_sections( $this->pluginBasename );

							break 1;
						}
					}

					//dynamic submit button
					if($active_tab != 'help' && $active_tab != 'advanced'){
						if($active_tab != 'mappings' || $this->saveMappingsButtonDisabled == false){
							submit_button(sprintf(esc_html__('Save %s', 'mdmap_app'), $active_tab_name));
						}
					}

				echo '</form>';
			echo '</div>';
		}

		//register settings
		private function register_settings(){
			register_setting( 'mdmap_app_settings_group', 'mdmap_app_settings', array(
				'sanitize_callback' => array($this, 'sanitize_settings_group'),
				'show_in_rest' => false
			) );
			register_setting( 'mdmap_app_mappings_group', 'mdmap_app_mappings', array(
				'sanitize_callback' => array($this, 'sanitize_mappings_group'),
				'show_in_rest' => false
			) );
		}

		//generate options fields output for the settings tab
		public function section_settings_callback(){
			echo esc_html__('Advanced server settings — most sites can leave these at their defaults.', 'mdmap_app');
		}
		public function field_settings_phpserver_callback(){
			$options = $this->getSettings();
			if(empty($options)) $options = array();

			$options['php_server'] = isset($options['php_server']) ? $options['php_server'] : 'SERVER_NAME';

			echo sprintf('<p>%s <a target="_blank" href="https://wordpress.org/support/topic/server_name-instead-of-http_host/">%s</a>.</p>',
				esc_html__('Most sites work fine with the default. If your mappings aren\'t resolving correctly, try HTTP_HOST — see', 'mdmap_app'),
				esc_html__('this support thread', 'mdmap_app')
			);
			echo '<p><label><input type="radio" name="mdmap_app_settings[php_server]" value="SERVER_NAME" '. checked('SERVER_NAME', $options['php_server'], false ) . ' />$_SERVER["SERVER_NAME"] ('. esc_html__('Default', 'mdmap_app') .')</label></p>';
			echo '<p><label><input type="radio" name="mdmap_app_settings[php_server]" value="HTTP_HOST" '. checked('HTTP_HOST', $options['php_server'], false ) .' />$_SERVER["HTTP_HOST"] ('. esc_html__('recommended for nginx', 'mdmap_app') .')</label></p>';
		}
		public function field_settings_compatibilitymode_callback(){
			$options = $this->getSettings();
			if(empty($options)) $options = array();

			$options['compatibilitymode'] = isset($options['compatibilitymode']) ? $options['compatibilitymode'] : 0;

			echo sprintf('<p>%s</p>',
				esc_html__('Disables domain replacement inside wp-admin. Useful if a page builder or visual editor has trouble loading mapped pages.', 'mdmap_app')
			);
			echo '<p><label><input type="radio" name="mdmap_app_settings[compatibilitymode]" value="0" '. checked('0', $options['compatibilitymode'], false ) . ' />Off ('. esc_html__('Default', 'mdmap_app') .')</label></p>';
			echo '<p><label><input type="radio" name="mdmap_app_settings[compatibilitymode]" value="1" '. checked('1', $options['compatibilitymode'], false ) .' />On</label></p>';
		}
		public function field_settings_excluded_domains_callback(){
			$options  = $this->getSettings();
			$excluded = !empty($options['excluded_domains']) ? $options['excluded_domains'] : '';
			if( defined('ICL_SITEPRESS_VERSION') || defined('POLYLANG_VERSION') ){
				echo '<p class="description">' . esc_html__('A multilingual plugin (WPML or Polylang) is active. Add the language-specific domains it manages here to prevent the mapper from processing them.', 'mdmap_app') . '</p>';
			}
			echo '<textarea name="mdmap_app_settings[excluded_domains]" rows="4" class="large-text code">' . esc_textarea($excluded) . '</textarea>';
			echo '<p class="description">' . esc_html__('One domain per line. The mapper ignores requests arriving on these domains.', 'mdmap_app') . '</p>';
		}

		//generate options fields output for the mappings tab
		public function section_mappings_callback(){
			echo '<strong>' . esc_html__('Left field', 'mdmap_app') . '</strong>: ';
			echo esc_html__('enter the domain you want to use. http/https and www/non-www are handled automatically — one entry per domain is all you need.', 'mdmap_app');
			echo '<br />';
			echo '<strong>' . esc_html__('Right field', 'mdmap_app') . '</strong>: ';
			echo esc_html__('enter the WordPress path this domain should point to. All pages beneath that path are included automatically.', 'mdmap_app');
		}
		public function field_mappings_uris_callback(){
			$options = $this->getMappings();
			if(empty($options)) $options = array();

			echo '<section class="mdmap_app_mappings">';
				$cnt = 0;
				if(isset($options['mappings']) && !empty($options['mappings'])){
					foreach($options['mappings'] as $mapping){
						$mappingClass = 'mdmap_app_mapping' . ($this->isMappingEnabled($mapping) ? '' : ' mdmap_app_mapping_disabled');
						echo '<article class="'. apply_filters( 'mdmap_appf_mapping_class', $mappingClass ) .'">';
							echo '<div class="mdmap_app_mapping_header">';
								echo '<div><div class="mdmap_app_input_wrap"><span class="mdmap_app_input_prefix">http[s]://</span><input type="text" name="mdmap_app_mappings[cnt_'.$cnt.'][domain]" value="' . esc_attr($mapping['domain']) . '" /></div></div>';
								echo '<div class="mdmap_app_mapping_arrow">&raquo;</div>';
								echo '<div><div class="mdmap_app_input_wrap"><span class="mdmap_app_input_prefix">'. esc_url(get_home_url()) .'</span><input type="text" name="mdmap_app_mappings[cnt_'.$cnt.'][path]" value="' . esc_attr($mapping['path']) . '" /></div></div>';
							echo '</div>';
							echo '<div class="mdmap_app_mapping_body">';
								echo '<span class="mdmap_app_mapping_body_icon mdmap_app_delete_mapping"><a href="#" title="' . esc_html__('Remove this mapping', 'mdmap_app') . '">' . esc_html__('Remove', 'mdmap_app') . ' <i>&cross;</i></a></span>';
								do_action('mdmap_appa_after_mapping_body', $cnt, $mapping);
							echo '</div>';
						echo '</article>';
						$cnt++;
					}
				}
			echo '</section>';

			echo '<section class="mdmap_app_new_mapping">';
				echo '<article class="'. apply_filters( 'mdmap_appf_mapping_class', 'mdmap_app_mapping mdmap_app_mapping_new' ) .'">';
					echo '<div class="mdmap_app_mapping_header">';
						echo '<div><div class="mdmap_app_input_wrap"><span class="mdmap_app_input_prefix">http[s]://</span><input type="text" name="mdmap_app_mappings[cnt_new][domain]" placeholder="[www.]newdomain.com" /></div><div class="mdmap_app_input_hint">' . esc_html__('Enter the domain you want to map.', 'mdmap_app') . '</div></div>';
						echo '<div class="mdmap_app_mapping_arrow">&raquo;</div>';
						echo '<div><div class="mdmap_app_input_wrap"><span class="mdmap_app_input_prefix">'. get_home_url() .'</span><input type="text" name="mdmap_app_mappings[cnt_new][path]" placeholder="/mappedpage" /></div><div class="mdmap_app_input_hint">' . esc_html__('Enter the path to the desired root for this mapping', 'mdmap_app') . '</div></div>';
					echo '</div>';
					echo '<div class="mdmap_app_mapping_body">';
						do_action('mdmap_appa_after_mapping_body', 'new', false);
					echo '</div>';
				echo '</article>';
			echo '</section>';

			echo '<div class="mdmap_app_io_toolbar">';
				echo '<button type="button" id="mdmap_export_btn" class="button">' . esc_html__('Export Mappings', 'mdmap_app') . '</button>';
				echo '<label for="mdmap_import_file" class="button">' . esc_html__('Import Mappings', 'mdmap_app') . '</label>';
				echo '<input type="file" id="mdmap_import_file" accept=".json" style="display:none" />';
			echo '</div>';

			//calculate and maybe show warning for higher max_input_vars needed
			$numberOfSettings = 14; //domain, path, customheadcode, redirection, enabled (+hidden companion), noindex, passthrough, sitename, sitetagline, ogimage, ga4id, robotssitemap, sortorder
			if($cnt >= (intval(ini_get('max_input_vars')) / $numberOfSettings - 100)){
				$this->saveMappingsButtonDisabled = true;
				echo '<section class="notice notice-error">';
					echo '<p>';
						echo sprintf(
							esc_html__('Heads up! Your server allows a maximum of %1$s %2$s. With %3$s mapping(s) at %4$s vars each (%5$s total), you\'re approaching the limit. Increase %6$s to save more mappings.', 'mdmap_app'),
							esc_html(ini_get('max_input_vars')),
							'<em>max_input_vars</em>',
							esc_html($cnt),
							esc_html($numberOfSettings),
							esc_html($cnt . ' x ' . $numberOfSettings . ' = ' . ($cnt*$numberOfSettings)),
							'<em>max_input_vars</em>'
						);
						echo ' <a href="https://duckduckgo.com/?q=php+increase+max_input_vars" target="_blank">' . esc_html__('How to increase max_input_vars', 'mdmap_app') . '</a>';
					echo '</p>';
					echo '<p>';
						echo esc_html__('The Save button has been hidden to prevent partial data loss. Increase max_input_vars and reload to restore it.', 'mdmap_app');
					echo '</p>';
				echo '</section>';
			}
		}

		//function to show additional input fields in mapping body
		public function render_advanced_mapping_inputs($cnt, $mapping){
			$isNew = ($cnt === 'new');
			//the new-mapping row passes false; normalise so the field reads below are warning-free
			if(!is_array($mapping)) $mapping = array();

			//enabled/disabled toggle — shown for all mappings including new
			$isEnabled = ($isNew || !isset($mapping['enabled']) || intval($mapping['enabled']) !== 0);
			echo '<div class="mdmap_app_mapping_additional_input mdmap_app_toggle_row">';
				//hidden companion so an unchecked box still submits a 0 — the checkbox value wins when checked
				echo '<input type="hidden" name="mdmap_app_mappings[cnt_'.$cnt.'][enabled]" value="0" />';
				echo '<label class="mdmap_app_toggle_label">';
					echo '<input type="checkbox" name="mdmap_app_mappings[cnt_'.$cnt.'][enabled]" value="1" ' . checked($isEnabled, true, false) . ' />';
					echo ' ' . esc_html__('Active', 'mdmap_app');
				echo '</label>';
				if(!$isNew){
					echo '<button type="button" class="button button-small mdmap_app_health_btn" data-domain="' . esc_attr($mapping['domain']) . '">' . esc_html__('Test connection', 'mdmap_app') . '</button>';
					echo '<span class="mdmap_app_health_result"></span>';
				}
				echo '</div>';

			//hidden field carrying this row's position; the admin JS renumbers these on drag-to-reorder.
			//only existing rows participate in the sortable (the new row lives outside that container).
			if(!$isNew){
				echo '<input type="hidden" class="mdmap_app_sortorder" name="mdmap_app_mappings[cnt_'.$cnt.'][sortorder]" value="' . esc_attr($cnt) . '" />';
			}

			echo '<div class="mdmap_app_mapping_additional_input">';
				echo '<p class="mdmap_app_mapping_additional_input_header">' . esc_html__('Custom <head> code (this domain only)', 'mdmap_app') . '</p>';
				echo '<textarea name="mdmap_app_mappings[cnt_'.$cnt.'][customheadcode]" placeholder="' . esc_attr__('e.g. <meta name="google-site-verification" content="…" />', 'mdmap_app') . '">' . esc_textarea(html_entity_decode($mapping['customheadcode'] ?? '')) . '</textarea>';
			echo '</div>';

			echo '<div class="mdmap_app_mapping_additional_input">';
				echo '<p class="mdmap_app_mapping_additional_input_header">' . esc_html__('301 Redirect to mapped domain', 'mdmap_app') . '</p>';
				echo '<label><input type="checkbox" name="mdmap_app_mappings[cnt_'.$cnt.'][redirection]" value="301" ' . checked( !empty($mapping['redirection']), true, false ) . ' />' . esc_html__('Redirect visitors who arrive at the original path to this domain instead.', 'mdmap_app') . '</label>';
			echo '</div>';

			echo '<div class="mdmap_app_mapping_additional_input">';
				echo '<p class="mdmap_app_mapping_additional_input_header">' . esc_html__('Noindex original URL', 'mdmap_app') . '</p>';
				echo '<label><input type="checkbox" name="mdmap_app_mappings[cnt_'.$cnt.'][noindex]" value="1" ' . checked( !empty($mapping['noindex']), true, false ) . ' />' . esc_html__('Add a noindex tag to the original path — search engines will index only the mapped domain.', 'mdmap_app') . '</label>';
			echo '</div>';

			echo '<div class="mdmap_app_mapping_additional_input">';
				echo '<p class="mdmap_app_mapping_additional_input_header">' . esc_html__('Pass through unmatched paths', 'mdmap_app') . '</p>';
				echo '<label><input type="checkbox" name="mdmap_app_mappings[cnt_'.$cnt.'][passthrough]" value="1" ' . checked( !empty($mapping['passthrough']), true, false ) . ' />' . esc_html__('When a request on this domain doesn\'t resolve under the mapped path, serve the same path from the main site instead of 404.', 'mdmap_app') . '</label>';
				echo '<p class="description">' . esc_html__('Useful when the alternate domain acts as a branded alias of the main site. Any public top-level page on the main site becomes reachable from this domain — review before enabling on a site with private pages.', 'mdmap_app') . '</p>';
			echo '</div>';

			echo '<div class="mdmap_app_mapping_additional_input">';
				echo '<p class="mdmap_app_mapping_additional_input_header">' . esc_html__('Site name (this domain only)', 'mdmap_app') . '</p>';
				echo '<input type="text" class="regular-text" name="mdmap_app_mappings[cnt_'.$cnt.'][sitename]" value="' . esc_attr($mapping['sitename'] ?? '') . '" placeholder="' . esc_attr__('Leave empty to use the main site name', 'mdmap_app') . '" />';
				echo '<p class="description">' . esc_html__('Replaces the site name in <title> tags, Open Graph site_name, RSS feeds, and SEO plugin output while visitors browse this mapped domain.', 'mdmap_app') . '</p>';
			echo '</div>';

			echo '<div class="mdmap_app_mapping_additional_input">';
				echo '<p class="mdmap_app_mapping_additional_input_header">' . esc_html__('Site tagline (this domain only)', 'mdmap_app') . '</p>';
				echo '<input type="text" class="regular-text" name="mdmap_app_mappings[cnt_'.$cnt.'][sitetagline]" value="' . esc_attr($mapping['sitetagline'] ?? '') . '" placeholder="' . esc_attr__('Leave empty to use the main site tagline', 'mdmap_app') . '" />';
				echo '<p class="description">' . esc_html__('Replaces the site tagline (blogdescription) and Yoast/RankMath %sitedesc% expansions when visitors are on this mapped domain.', 'mdmap_app') . '</p>';
			echo '</div>';

			echo '<div class="mdmap_app_mapping_additional_input">';
				echo '<p class="mdmap_app_mapping_additional_input_header">' . esc_html__('Default Open Graph image (this domain only)', 'mdmap_app') . '</p>';
				echo '<input type="text" class="regular-text" name="mdmap_app_mappings[cnt_'.$cnt.'][ogimage]" value="' . esc_attr($mapping['ogimage'] ?? '') . '" placeholder="https://example.com/share-card.jpg" />';
				echo '<p class="description">' . esc_html__('Used as a fallback og:image / twitter:image when a page on this mapped domain has no specific share image set. Per-page Yoast/RankMath images still take precedence.', 'mdmap_app') . '</p>';
			echo '</div>';

			echo '<div class="mdmap_app_mapping_additional_input">';
				echo '<p class="mdmap_app_mapping_additional_input_header">' . esc_html__('Analytics ID (GA4 or GTM, this domain only)', 'mdmap_app') . '</p>';
				echo '<input type="text" class="regular-text" name="mdmap_app_mappings[cnt_'.$cnt.'][ga4id]" value="' . esc_attr($mapping['ga4id'] ?? '') . '" placeholder="G-XXXXXXXXXX" />';
				echo '<p class="description">' . esc_html__('Injects a gtag.js snippet when visitors are browsing this mapped domain.', 'mdmap_app') . '</p>';
			echo '</div>';

			echo '<div class="mdmap_app_mapping_additional_input">';
				echo '<p class="mdmap_app_mapping_additional_input_header">' . esc_html__('robots.txt Sitemap URL (this domain only)', 'mdmap_app') . '</p>';
				echo '<input type="text" class="regular-text" name="mdmap_app_mappings[cnt_'.$cnt.'][robotssitemap]" value="' . esc_attr($mapping['robotssitemap'] ?? '') . '" placeholder="https://example.com/sitemap.xml" />';
				echo '<p class="description">' . esc_html__('Overrides the Sitemap: line in robots.txt while visitors browse this domain.', 'mdmap_app') . '</p>';
			echo '</div>';
		}

		//sanitize options fields input
		public function sanitize_settings_group($options){
			if(empty($options)){
				return $options;
			}

			//be sure that only a correct server-value will be saved
			$options['php_server'] = (isset($options['php_server']) && ( $options['php_server'] == 'SERVER_NAME' || $options['php_server'] == 'HTTP_HOST' )) ? $options['php_server'] : 'SERVER_NAME';

			//sanitize excluded domains — strip protocols and trailing slashes; one per line
			if(isset($options['excluded_domains'])){
				$lines = array_filter(array_map('trim', explode("\n", $options['excluded_domains'])));
				$clean = array();
				foreach($lines as $line){
					$line = preg_replace('#^https?://#i', '', $line);
					$line = trim($line, '/');
					if(!empty($line)) $clean[] = sanitize_text_field($line);
				}
				$options['excluded_domains'] = implode("\n", $clean);
			}

			return apply_filters( 'mdmap_appf_save_settings', $options );
		}
		public function sanitize_mappings_group($options){
			//do nothing on empty input
			if(empty($options)){
				return $options;
			}

			//prepare mappings array
			$mappings = array();

			foreach($options as $key=>$val){
				//search for mappings and prepare them for database
				if(stripos( $key, 'cnt_' ) !== false){

					//only save not empty inputs
					$domain = str_replace([']', '['], '', trim(trim($val['domain']), '/'));
					$path = trim(trim( isset($val['path']) ? $val['path'] : '' ), '/');
					if($domain != ''/* && $path != ''*/){

						//validate inputs
						$parsedDomain = parse_url($domain);
						$parsedPath = parse_url($path);
						if($parsedDomain != false && $parsedPath != false){

							//if we get only the host-representation we temporary add a protocol, so we can use the benefit from parse_url to strip the query
							//note: this will also be run for each already saved mapping, since we strip the protocol on save...
							if(!isset($parsedDomain['host'])){
								$parsedDomain = parse_url('dummyprotocol://' . $domain);
							}

							//save only host name (and path, if provided) with stripped slashes
							$trimmedDomainPath = trim(trim( (isset($parsedDomain['path']) ? $parsedDomain['path'] : '') ), '/');
							$val['domain'] = trim(trim(isset($parsedDomain['host']) ? $parsedDomain['host'] : ''), '/') . (!empty($trimmedDomainPath) ? '/' . $trimmedDomainPath : '');

							//save path with leading slash
							$val['path'] = '/' . $path;

							//reject root path - mapping "/" would intercept all site traffic
							if( $val['path'] === '/' ){
								if(function_exists('add_settings_error')) add_settings_error( 'mdmap_app_messages', 'mdmap_app_error_code', esc_html__('Mapping to "/" is not allowed — it would intercept all site traffic.', 'mdmap_app'), 'error' );
								unset($options[$key]);
								continue;
							}

							//iterate over existing mappings and check, if this path has already been used
							$saveMapping = true;
							foreach($mappings as $existingMapping){
								if($existingMapping['path'] === $val['path']){
									$saveMapping = false;
								}
								if($this->stripWww($existingMapping['domain']) === $this->stripWww($val['domain'])){
									$saveMapping = false;
								}
							}

							//sanitize html-head-code: allow only safe head elements
							if(!empty($val['customheadcode'])){
								$allowed_head_tags = array(
									'meta'     => array('name'=>true,'content'=>true,'property'=>true,'charset'=>true,'http-equiv'=>true),
									'link'     => array('rel'=>true,'href'=>true,'type'=>true,'media'=>true,'sizes'=>true,'hreflang'=>true),
									'script'   => array('type'=>true,'src'=>true,'async'=>true,'defer'=>true,'id'=>true),
									'style'    => array('type'=>true),
									'noscript' => array(),
								);
								$val['customheadcode'] = wp_kses($val['customheadcode'], $allowed_head_tags);
							}

							//only allow integers (statuscode) for redirection
							if(!empty($val['redirection'])) $val['redirection'] = intval($val['redirection']);

								//enabled flag (1 = active, 0 = disabled; absent means active — backward compat)
								$val['enabled'] = isset($val['enabled']) ? intval($val['enabled']) : 1;

								//noindex on original path
								$val['noindex'] = !empty($val['noindex']) ? 1 : 0;

								//pass-through unmatched paths to the un-rewritten path on the main site
								$val['passthrough'] = !empty($val['passthrough']) ? 1 : 0;

								//per-mapping site name override (empty = no override)
								$val['sitename'] = isset($val['sitename']) ? sanitize_text_field($val['sitename']) : '';

								//per-mapping site tagline override (empty = no override)
								$val['sitetagline'] = isset($val['sitetagline']) ? sanitize_text_field($val['sitetagline']) : '';

								//per-mapping default Open Graph image url (empty = no override)
								$val['ogimage'] = !empty($val['ogimage']) ? esc_url_raw($val['ogimage']) : '';

								//ga4 / gtm measurement id
								if(!empty($val['ga4id'])){
									$val['ga4id'] = strtoupper(sanitize_text_field($val['ga4id']));
									if(!preg_match('/^(G-[A-Z0-9]+|GTM-[A-Z0-9]+)$/', $val['ga4id'])) $val['ga4id'] = '';
								}else{
									$val['ga4id'] = '';
								}

								//per-mapping robots.txt sitemap url
								$val['robotssitemap'] = !empty($val['robotssitemap']) ? esc_url_raw($val['robotssitemap']) : '';

								//explicit sort order from drag-to-reorder
							$val['sortorder'] = isset($val['sortorder']) ? intval($val['sortorder']) : 999;

							if($saveMapping){
								//mapping should be saved and is filtered before
								//use domain as index, so we do not have any duplicates -> this index will never be used or stored, but we convert it to md5 so it can not be confusing later
								$mappings[md5($val['domain'])] = apply_filters('mdmap_appf_save_mapping', $val);
							}else{
								//check for existence, since this may be called in an upgrade process earlier, when this is not available yet
								if(function_exists('add_settings_error')) add_settings_error( 'mdmap_app_messages', 'mdmap_app_error_code', esc_html__('At least one mapping with duplicate domain or path has been dropped.', 'mdmap_app'), 'error' );
							}
						}else{
							//check for existence, since this may be called in an upgrade process earlier, when this is not available yet
							if(function_exists('add_settings_error')) add_settings_error( 'mdmap_app_messages', 'mdmap_app_error_code', esc_html__('One or more mappings had an invalid domain or path and were skipped.', 'mdmap_app'), 'error' );
						}
					//if we have only one input filled
					}else if(!($val['domain'] == '' && $val['path'] == '')){
						//check for existence, since this may be called in an upgrade process earlier, when this is not available yet
						if(function_exists('add_settings_error')) add_settings_error( 'mdmap_app_messages', 'mdmap_app_error_code', esc_html__('One or more mappings were skipped — both a domain and a path are required.', 'mdmap_app'), 'error' );
					}
					//remove original mapping (cnt_) from options array
					unset($options[$key]);
				}
			}

			//sort: use explicit drag order when present; fall back to alphabetical by domain
			$hasSortOrder = false;
			foreach($mappings as $m){
				if(isset($m['sortorder']) && $m['sortorder'] !== 999){ $hasSortOrder = true; break; }
			}
			if($hasSortOrder){
				usort($mappings, function($a, $b){ return intval($a['sortorder'] ?? 999) - intval($b['sortorder'] ?? 999); });
			}else{
				$sort_key = apply_filters('mdmap_appf_mapping_sort', 'domain');
				usort($mappings, function($a, $b) use ($sort_key) { return strcmp($a[$sort_key], $b[$sort_key]); });
			}

			//add filtered and sorted mappings to options array
			if(!empty($mappings)) $options['mappings'] = $mappings;

			return apply_filters( 'mdmap_appf_save_mappings', $options );
		}
		//change the request, check for matching mappings
		public function parse_request($do_parse, $instance, $extra_query_vars){
			//store current request uri as fallback for the originalRequestURI variable, no matter if we have a match or not
			$this->setOriginalRequestURI($_SERVER['REQUEST_URI']);

			//definitely no request-mapping in backend
			if(is_admin()) return $do_parse;

			//skip if the incoming domain is on the excluded list (e.g. WPML/Polylang language domains)
			if($this->isCurrentDomainExcluded()) return $do_parse;

			//loop mappings and compare match of mapping against each other
			$mappings = $this->getMappings();
			if(!empty($mappings) && isset($mappings['mappings']) && !empty($mappings['mappings'])){

				foreach($mappings['mappings'] as $mapping){
					//skip disabled mappings
					if(!$this->isMappingEnabled($mapping)) continue;
					$matchCompare = $this->uriMatch($this->getCurrentURI(), $mapping, true);
					//then enable custom matching by filtering
					$matchCompare = apply_filters( 'mdmap_appf_uri_match', $matchCompare, $this->getCurrentURI(), $mapping, true );

					//if the current mapping fits better, use this instead the previous one
					if($matchCompare !== false && isset($matchCompare['factor']) && $matchCompare['factor'] > $this->getCurrentMapping()['factor']){
						 $this->setCurrentMapping($matchCompare);
					}
				}

				//we have a matching mapping -> let the magic happen
				if(!empty($this->getCurrentMapping()['match'])){
					//set request uri to our original mapping path AND if we have a longer query, we need to append it
					$newRequestURI = trailingslashit($this->getCurrentMapping()['match']['path'] . substr($this->stripWww($this->getCurrentURI()), strlen($this->stripWww($this->getCurrentMapping()['match']['domain']))));
					//enable additional filtering on the request_uri
					$newRequestURI = apply_filters('mdmap_appf_request_uri', $newRequestURI, $this->getCurrentURI(), $this->getCurrentMapping());

					//robots.txt: leave the request untouched so WP serves its virtual robots.txt
					//(is_robots() stays true). currentMapping is still set, so filter_robots_txt()
					//can inject the per-mapping Sitemap line for this domain.
					$incomingPath = parse_url($this->getOriginalRequestURI(), PHP_URL_PATH);
					$isRobotsRequest = ($incomingPath === '/robots.txt');

					//pass-through: when this mapping opts in, and the rewritten path doesn't resolve
					//to any real page/post but the original (un-rewritten) path does, keep the original.
					//lets pages outside the mapping's subtree remain reachable under the mapped domain.
					$passthrough = !empty($this->getCurrentMapping()['match']['passthrough']);
					if( $isRobotsRequest ){
						//leave REQUEST_URI as /robots.txt
					}else if( $passthrough && !$this->pathHasContent($newRequestURI) && $this->pathHasContent($this->getOriginalRequestURI()) ){
						//leave REQUEST_URI as the original path — currentMapping stays set so canonical/og/admin-bar still use the mapped domain
					}else{
						$_SERVER['REQUEST_URI'] = $newRequestURI;
					}
				}
			}

			return $do_parse;
		}

		//redirect visitors from the original path to the mapped domain (when redirection is enabled for that mapping)
		public function handle_redirect(){
			//only fire when we are NOT on a mapped domain
			if( !empty($this->getCurrentMapping()['match']) ) return;

			$mappings = $this->getMappings();
			if( empty($mappings) || !isset($mappings['mappings']) ) return;

			$bestMatch = array( 'match' => false, 'factor' => PHP_INT_MIN );

			foreach( $mappings['mappings'] as $mapping ){
				//skip disabled mappings
				if(!$this->isMappingEnabled($mapping)) continue;
				//skip mappings that have no redirection configured
				if( empty($mapping['redirection']) ) continue;

				//check if the current path falls under this mapping's target path
				$matchCompare = $this->uriMatch( $this->getCurrentURI(), $mapping, false );
				if( $matchCompare !== false && $matchCompare['factor'] > $bestMatch['factor'] ){
					$bestMatch = $matchCompare;
				}
			}

			if( empty($bestMatch['match']) ) return;

			$mapping  = $bestMatch['match'];
			$protocol = is_ssl() ? 'https' : 'http';

			//confirm REQUEST_URI actually begins with the mapping path before slicing
			$requestPath = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
			if( !$this->pathUnderBase( $requestPath, $mapping['path'] ) ) return;

			//extra path beyond the mapped base (e.g. /product-a/subpage -> /subpage)
			//substr can return false in PHP 7 if offset >= string length; normalise to empty string
			$extraPath = substr( $_SERVER['REQUEST_URI'], strlen( $mapping['path'] ) );
			if( $extraPath === false ) $extraPath = '';

			$redirectUrl = $protocol . '://' . $mapping['domain'] . '/' . ltrim( $extraPath, '/' );
			wp_redirect( $redirectUrl, intval( $mapping['redirection'] ) );
			exit;
		}

		//hook into the canonical redirect to avoid infinite redirection loops
		public function check_canonical_redirect($redirect_url, $requested_url){

			//are we on a mapped page? suppress ALL canonical redirects.
			//WordPress will try to redirect the mapped domain back to the primary domain's canonical URL
			//(e.g. secondary.com/ -> mainsite.com/products-page/). Allowing this creates a loop when
			//redirection is also enabled for that mapping: mainsite.com/products-page/ -> secondary.com/ -> repeat.
			//The mapped domain IS the canonical address, so no further canonical redirects should happen.
			if($this->getCurrentMapping()['match'] != false){
				return false;
			}

			//standard return value
			return $redirect_url;
		}

		//strip leading www. subdomain from a host string
		private function stripWww($host){
			return preg_replace('/^www\./i', '', $host);
		}

		//strip port number from a host string (e.g. example.com:8080 -> example.com)
		private function stripPort($host){
			return preg_replace('/:\d+$/', '', $host);
		}

		//standard function to check an uri against a mapping
		private function uriMatch($uri, $mapping, $reverse = false){

			//strip protocol from uri
			$uri = str_ireplace('http://', '', str_ireplace('https://', '', $uri));

			//strip www-subdomain from uri for matching purpose
			$uri = $this->stripWww($uri);

			//do we check match at parsing the site or when replacing uris in the page?
			if($reverse){
				$arg2 = $this->stripWww($mapping['domain']);
				$matchingPosCompare = 0;
			}else{
				$arg2 = $mapping['path'];
				$matchingPosCompare = $this->homeURLMatchLength;
			}

			//check if arg2 is part of uri and starts where we want to
			$matchingPos = stripos(trailingslashit( $uri ), trailingslashit( $arg2 ) );
			if( $matchingPos !== false && $matchingPos === $matchingPosCompare ){
				//use length of match as factor
				return array(
					'match' => $mapping,
					'factor' => strlen(trailingslashit($arg2))
				);
			}
			return false;
		}

		//aggregation of all filters to replace the uri in the current page
		private function replace_uris(){
			//retrieve settings for compatibility mode
			$options = $this->getSettings();
			if(empty($options)) $options = array();
			$options['compatibilitymode'] = isset($options['compatibilitymode']) ? $options['compatibilitymode'] : 0;

			//single views
			if( !($options['compatibilitymode'] && is_admin()) ){
				add_filter('page_link', array($this, 'replace_uri'), 20);
				add_filter('post_link', array($this, 'replace_uri'), 20);
				add_filter('post_type_link', array($this, 'replace_uri'), 20);
				add_filter('attachment_link', array($this, 'replace_uri'), 20);
				//get_comment_author_link ... not necessary (seems to use the "author_link")
				//get_comment_author_uri_link ... this is the url the author can fill out - should not be touched
				//comment_reply_link ... leave this out until we manage to keep user logged in on addon-domains
				//remove_action('wp_head', 'wp_shortlink_wp_head', 10, 0); ... guess we should not add this...
			}

			//revoke mapping for the preview-button
			add_filter('preview_post_link', array($this, 'unreplace_uri'));

			//archive views
			add_filter('paginate_links', array($this, 'replace_uri'), 10);
			add_filter('day_link', array($this, 'replace_uri'), 20);
			add_filter('month_link', array($this, 'replace_uri'), 20);
			add_filter('year_link', array($this, 'replace_uri'), 20);
			add_filter('author_link', array($this, 'replace_uri'), 10);
			add_filter('term_link', array($this, 'replace_uri'), 10);

			//feed url (if someone matches a domain to a feed...)
			add_filter('feed_link', array($this, 'replace_uri'), 10);
			add_filter('self_link', array($this, 'replace_uri'), 10);
			add_filter('author_feed_link', array($this, 'replace_uri'), 10);

			//nav menu objects that do not use the standard link builders (like custom hrefs in the menu)
			add_filter('wp_nav_menu_objects', array($this, 'replace_menu_uri'));

			//content elements - do not map in wp-admin
			if(!is_admin()){
				add_filter( 'script_loader_src', array($this, 'replace_domain'), 10 );
				add_filter( 'style_loader_src', array($this, 'replace_domain'), 10 );
				add_filter( 'stylesheet_directory_uri', array($this, 'replace_domain'), 10 );
				add_filter( 'template_directory_uri', array($this, 'replace_domain'), 10 );
				add_filter( 'the_content', array($this, 'replace_domain'), 10 );
				add_filter( 'get_header_image_tag', array($this, 'replace_domain'), 10 );
				add_filter( 'wp_get_attachment_image_src', array($this, 'replace_src_domain'), 10 );
				add_filter( 'wp_calculate_image_srcset', array($this, 'replace_srcset_domain'), 10 );
			}

			//yoast sitemaps
			add_filter( 'wpseo_xml_sitemap_post_url', array($this, 'replace_yoast_xml_sitemap_post_url'), 0, 2 );
			add_filter( 'wpseo_sitemap_entry', array($this, 'replace_yoast_sitemap_entry'), 10, 3 );

			//core WordPress sitemaps (wp-sitemap.xml, default since WP 5.5)
			add_filter( 'wp_sitemaps_posts_entry', array($this, 'replace_sitemap_entry'), 10 );
			add_filter( 'wp_sitemaps_taxonomies_entry', array($this, 'replace_sitemap_entry'), 10 );
			add_filter( 'wp_sitemaps_users_entry', array($this, 'replace_sitemap_entry'), 10 );

			//rankmath sitemaps
			add_filter( 'rank_math/sitemap/entry', array($this, 'replace_sitemap_entry'), 10 );

			//elementor preview url
			add_filter( 'elementor/document/urls/preview', array($this, 'replace_elementor_preview_url') );
		}
		//all the helpers for the above filters
		public function replace_uri($originalURI){

			//loop mappings and compare match of mapping against each other
			$mappings = $this->getMappings();
			if(!empty($mappings) && isset($mappings['mappings']) && !empty($mappings['mappings'])){

				$bestMatch = array(
					'match' => false,
					'factor' => PHP_INT_MIN
				);

				foreach($mappings['mappings'] as $mapping){
					//skip disabled mappings
					if(!$this->isMappingEnabled($mapping)) continue;
					//first use our standard matching function
					$matchCompare = $this->uriMatch($originalURI, $mapping, false);
					//then enable custom matching by filtering
					$matchCompare = apply_filters( 'mdmap_appf_uri_match', $matchCompare, $originalURI, $mapping, false );

					//if the current mapping fits better, use this instead the previous one
					if($matchCompare !== false && isset($matchCompare['factor']) && $matchCompare['factor'] > $bestMatch['factor']){
						 $bestMatch = $matchCompare;
					}
				}

				//we have a matching mapping -> let the magic happen
				if(!empty($bestMatch['match'])){
					$uriParsed = parse_url($originalURI);
					$newURI = str_ireplace( trailingslashit( ($uriParsed['host'] ?? '') . $bestMatch['match']['path'] ), trailingslashit( $bestMatch['match']['domain'] ), $originalURI );
					return apply_filters('mdmap_appf_filtered_uri', $newURI, $originalURI, $bestMatch);
				}
			}

			return $originalURI;
		}
		//keep home/search links on the mapped domain while a visitor is browsing one.
		//tightly scoped: front-end of a mapped page only; never during admin/ajax/cron/rest/feed;
		//subtree links fall back to replace_uri, and only the bare site root is repointed at the mapped root.
		public function replace_home_url($url, $path = '', $orig_scheme = null){
			if(empty($this->getCurrentMapping()['match'])) return $url;
			if(is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST) || is_feed()) return $url;
			if($orig_scheme === 'rest') return $url; //leave the REST API base alone
			if(!apply_filters('mdmap_appf_rewrite_home_url', true, $url, $path)) return $url;

			//links that fall under a mapping's subtree are already handled by replace_uri
			$mapped = $this->replace_uri($url);
			if($mapped !== $url) return $mapped;

			//otherwise only repoint the bare site root (home link, search form action, etc.)
			if(!is_string($path) || trim($path, '/') === ''){
				$mapping  = $this->getCurrentMapping()['match'];
				$protocol = is_ssl() ? 'https' : 'http';
				$slash    = (is_string($path) && $path !== '') ? '/' : '';
				return $protocol . '://' . $mapping['domain'] . $slash;
			}

			return $url;
		}
		public function unreplace_uri( $mapped_uri ){

			//loop mappings and compare match of mapping against each other
			$mappings = $this->getMappings();
			if(!empty($mappings) && isset($mappings['mappings']) && !empty($mappings['mappings'])){

				$bestMatch = array(
					'match' => false,
					'factor' => PHP_INT_MIN
				);

				foreach($mappings['mappings'] as $mapping){
					//skip disabled mappings
					if(!$this->isMappingEnabled($mapping)) continue;
					//first use our standard matching function
					$matchCompare = $this->uriMatch($mapped_uri, $mapping, true);

					//then enable custom matching by filtering
					$matchCompare = apply_filters( 'mdmap_appf_uri_match', $matchCompare, $mapped_uri, $mapping, true );

					//if the current mapping fits better, use this instead the previous one
					if($matchCompare !== false && isset($matchCompare['factor']) && $matchCompare['factor'] > $bestMatch['factor']){
						 $bestMatch = $matchCompare;
					}
				}

				//we have a matching mapping -> let the magic happen
				if(!empty($bestMatch['match'])){
					$uriParsed = parse_url($mapped_uri);
					$newURI = str_ireplace( ($uriParsed['host'] ?? ''), parse_url(get_home_url(), PHP_URL_HOST) . $bestMatch['match']['path'], $mapped_uri );
					return apply_filters('mdmap_appf_filtered_uri', $newURI, $mapped_uri, $bestMatch);
				}
			}

			return $mapped_uri;
		}
		public function replace_menu_uri($items){
			//loop menu items and replace uri
			foreach($items as $item){
				$item->url = $this->replace_uri($item->url);
			}
		 	return $items;
		}
		public function replace_src_domain($src){
			//url is in the 0-index of the src-array
			if(!empty($src)){
				$src[0] = $this->replace_domain($src[0]);
			}
			return $src;
		}
		public function replace_srcset_domain($srcset){
			//iterate through srcset and change uri on all sources
			if(!empty($srcset)){
				foreach($srcset as $key => $val){
					$srcset[$key]['url'] = $this->replace_domain($val['url']);
				}
			}
			return $srcset;
		}
		public function replace_domain($input){
			//check if we are on a mapped page and replace original domain with mapped domain
			if(!empty($this->getCurrentMapping()['match'])){
				//we need to make sure that we only replace right at the beginning (after the protocol), so we do not destroy subdomains (like img.mydomain.com). that is why we add the :// to the strings
				//and we also need to be sure that we do not replace it in a hyperlink which leads to any page on our original domain or to the home page itelsf. so we add a pregex which needs to have any character, a dot and again any character before the next ". that should do the trick...
				$preg_host = preg_quote($this->siteHost);
				//to understand the regex, use https://regexr.com/ :)
				$input = preg_replace_callback('/:\/\/'.$preg_host.'([^\"\'\>\s]*)([\"\'>]|\s|$)/i', array($this, 'replace_domain_in_url'), $input);
			}
			return $input;
		}
		private function replace_domain_in_url($input){
			//if this is called from preg_replace_callback we will receive an array. we only need the first index, so we can generalize this to be used by other functions as well
			if(is_array($input)){
				$input = $input[0];
			}

			//check if we are on a mapped page and replace original domain with mapped domain
			if(!empty($this->getCurrentMapping()['match'])){
				//we need to make sure that we only replace right at the beginning (after the protocol), so we do not destroy subdomains (like img.mydomain.com). that is why we add the :// to the strings
				return str_ireplace( '://' . $this->siteHost, '://' . ($this->mappedHost ?? $this->getCurrentMapping()['match']['domain']), $input);
			}

			return $input;
		}
		public function replace_yoast_xml_sitemap_post_url($url, $post){
			// add home url to the posturl, so YOAST will not handle the post like an external url
			// this is stripped again in the next filter
			if(trailingslashit( get_home_url() ) != trailingslashit( $url) ){
				$url = get_home_url() .'/\\'. $this->replace_uri($url);
			}
			return $url;
		}
		public function replace_yoast_sitemap_entry($url, $type, $post){
			//true for all post types
			if($type === 'post'){
				if(false !== strpos($url['loc'],'\\')){
					$tmp = explode('\\', $url['loc']);
					$url['loc'] = $tmp[1];
				}
			}
			return $url;
		}
		//rewrite the loc of a sitemap entry to the mapped domain (core WP + RankMath sitemaps).
		//only urls under a mapping's path are changed; index/sub-sitemap urls at the site root are left alone.
		public function replace_sitemap_entry($entry){
			if(is_array($entry) && !empty($entry['loc'])){
				$entry['loc'] = $this->replace_uri($entry['loc']);
			}
			return $entry;
		}
		public function replace_elementor_preview_url($preview_url){
			//elementor saves the uri in some escaped format
			$unescaped_preview_url = str_replace( '\/', '/', $preview_url);
			return $this->unreplace_uri( $unescaped_preview_url );
		}

		//hook into some of our own defined actions
		public function hookMDMAction(){
			add_action('mdmap_appa_after_mapping_body', array( $this, 'render_advanced_mapping_inputs'), 10, 2);
		}
		//check if custom head code is defined for this mapping and output it with html entities decoded, if so...
		public function output_custom_head_code(){
			if(!empty($this->getCurrentMapping()['match'])){
				if(!empty($this->getCurrentMapping()['match']['customheadcode'])){
					// html_entity_decode handles data saved by older versions of this plugin (stored with htmlentities)
					echo html_entity_decode($this->getCurrentMapping()['match']['customheadcode']);
				}
			}
		}

		// ── Helpers ──────────────────────────────────────────────────────

		//return true when a mapping should be processed (absent enabled key = active, for backward compat)
		private function isMappingEnabled($mapping){
			return !isset($mapping['enabled']) || intval($mapping['enabled']) !== 0;
		}

		//return true when $path is the mapping's base path or a descendant of it.
		//slash-boundary aware so a mapping for /news does not match /newsletter
		private function pathUnderBase($path, $base){
			if(empty($path) || empty($base)) return false;
			return strpos(trailingslashit($path), trailingslashit($base)) === 0;
		}

		//return true when a uri resolves to a real page, post, or custom post type entry
		//used by the per-mapping pass-through option to decide whether to fall back to the un-rewritten path
		private function pathHasContent($uri){
			if(empty($uri)) return false;
			$path = parse_url($uri, PHP_URL_PATH);
			if(empty($path)) return false;
			//root always resolves to the home page / front page
			if($path === '/') return true;
			$trimmed = trim($path, '/');
			if($trimmed === '') return true;
			//hierarchical pages: the dominant content type for this plugin's typical use case
			if(function_exists('get_page_by_path') && get_page_by_path($trimmed, OBJECT, 'page')) return true;
			//posts and custom post types reachable via the rewrite rules
			if(function_exists('url_to_postid') && url_to_postid(home_url($path))) return true;
			return false;
		}

		//return true when the current incoming domain is on the admin-configured exclusion list
		private function isCurrentDomainExcluded(){
			$options = $this->getSettings();
			if(empty($options['excluded_domains'])) return false;
			$currentHost = $this->stripWww(strtok($this->getCurrentURI(), '/'));
			if(empty($currentHost)) return false;
			$lines = array_filter(array_map('trim', explode("\n", $options['excluded_domains'])));
			foreach($lines as $excDomain){
				if($this->stripWww($excDomain) === $currentHost) return true;
			}
			return false;
		}

		// ── Canonical / SEO head tags ─────────────────────────────────────

		//true when an SEO plugin that emits its own canonical is active
		private function seoPluginActive(){
			return defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || class_exists('RankMath');
		}

		//rewrite an SEO plugin's canonical url to the mapped domain (Yoast + RankMath)
		public function replace_canonical($url){
			return $this->replace_uri($url);
		}

		//output <link rel="canonical"> using the mapped domain when on a mapped page.
		//when an SEO plugin is active it owns the canonical (we filter it via replace_canonical);
		//otherwise we emit a single tag and remove core's rel_canonical so it isn't duplicated.
		public function output_canonical_tag(){
			if(empty($this->getCurrentMapping()['match'])) return;
			if($this->seoPluginActive()) return;
			//we own the canonical — stop core from printing a second one
			remove_action('wp_head', 'rel_canonical');
			$mapping    = $this->getCurrentMapping()['match'];
			$protocol   = is_ssl() ? 'https' : 'http';
			$requestUri = $this->getOriginalRequestURI();
			if(empty($requestUri)) $requestUri = $_SERVER['REQUEST_URI'];
			echo '<link rel="canonical" href="' . esc_url($protocol . '://' . $mapping['domain'] . $requestUri) . '" />' . "\n";
		}

		//output noindex on the original (un-mapped) path when the mapping has noindex enabled
		public function output_noindex_tag(){
			if(!empty($this->getCurrentMapping()['match'])) return; //on mapped domain: no noindex needed
			$mappings = $this->getMappings();
			if(empty($mappings) || !isset($mappings['mappings'])) return;
			$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
			if($requestPath === false || $requestPath === '') return;
			foreach($mappings['mappings'] as $mapping){
				if(!$this->isMappingEnabled($mapping)) continue;
				if(empty($mapping['noindex'])) continue;
				if($this->pathUnderBase($requestPath, $mapping['path'])){
					echo '<meta name="robots" content="noindex,follow" />' . "\n";
					return;
				}
			}
		}

		//output per-mapping GA4/GTM snippet only when on the mapped domain
		public function output_tracking_snippet(){
			if(empty($this->getCurrentMapping()['match'])) return;
			$ga4id = $this->getCurrentMapping()['match']['ga4id'] ?? '';
			if(empty($ga4id)) return;
			$ga4id = esc_attr(sanitize_text_field($ga4id));
			echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $ga4id . '"></script>' . "\n";
			echo '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());gtag("config","' . esc_js($ga4id) . '");</script>' . "\n";
		}

		// ── Admin bar ─────────────────────────────────────────────────────

		//show a badge in the WP admin bar when browsing a mapped domain
		public function admin_bar_badge($wp_admin_bar){
			if(is_admin()) return;
			if(empty($this->getCurrentMapping()['match'])) return;
			$domain = $this->getCurrentMapping()['match']['domain'];
			$wp_admin_bar->add_node(array(
				'id'    => 'mdmap_app_badge',
				'title' => '<span aria-hidden="true" style="color:#46b450;margin-right:4px">&#9679;</span>' . esc_html__('Mapped domain:', 'mdmap_app') . ' <strong>' . esc_html($domain) . '</strong>',
				'href'  => false,
				'meta'  => array('class' => 'mdmap_app_adminbar_badge'),
			));
		}

		// ── Open Graph ────────────────────────────────────────────────────

		//replace the OG URL with the mapped domain (Yoast + RankMath filter target)
		public function replace_og_url($url){
			return $this->replace_uri($url);
		}

		// ── Per-mapping branding (site name / tagline / og image) ─────────

		//return the override for a given key from the active mapping, or null when nothing should be overridden
		private function getMappingBrand($key){
			if(empty($this->getCurrentMapping()['match'])) return null;
			$value = $this->getCurrentMapping()['match'][$key] ?? '';
			return $value !== '' ? $value : null;
		}

		//short-circuit get_option('blogname') with the mapping's override while on the mapped domain
		public function override_blogname($value){
			$override = $this->getMappingBrand('sitename');
			return $override !== null ? $override : $value;
		}

		//short-circuit get_option('blogdescription') with the mapping's override while on the mapped domain
		public function override_blogdescription($value){
			$override = $this->getMappingBrand('sitetagline');
			return $override !== null ? $override : $value;
		}

		//swap Yoast's %%sitename%% / %%sitedesc%% replacement values when on the mapped domain
		public function override_yoast_replacements($replacements){
			if(!is_array($replacements)) return $replacements;
			$name = $this->getMappingBrand('sitename');
			$desc = $this->getMappingBrand('sitetagline');
			if($name !== null) $replacements['%%sitename%%'] = $name;
			if($desc !== null) $replacements['%%sitedesc%%'] = $desc;
			return $replacements;
		}

		//override og:site_name (Yoast + RankMath) when set on the active mapping
		public function override_og_site_name($value){
			$override = $this->getMappingBrand('sitename');
			return $override !== null ? $override : $value;
		}

		//fallback og:image / twitter:image when the page has none and the mapping has a default set
		public function override_og_image($value){
			if(!empty($value)) return $value; //page has its own — leave it alone
			$override = $this->getMappingBrand('ogimage');
			return $override !== null ? $override : $value;
		}

		// ── REST API ──────────────────────────────────────────────────────

		//replace original domain with mapped domain in REST API JSON responses
		public function rest_response_replace($result, $server, $request){
			if(empty($this->getCurrentMapping()['match'])) return $result;
			$options = $this->getSettings();
			if(!empty($options['compatibilitymode'])) return $result;
			$data = $result->get_data();
			if(empty($data)) return $result;
			$json = wp_json_encode($data);
			if($json === false) return $result;
			$replaced = $this->replace_domain($json);
			if($replaced === $json) return $result;
			$newData = json_decode($replaced, true);
			if($newData === null) return $result;
			$result->set_data($newData);
			return $result;
		}

		// ── Cache flush ───────────────────────────────────────────────────

		//flush all supported page caches after mappings or settings are updated
		public function maybe_flush_caches($option_name, $old_value, $new_value){
			if($option_name !== 'mdmap_app_mappings' && $option_name !== 'mdmap_app_settings') return;
			//WP Super Cache
			if(function_exists('wp_cache_clear_cache')) wp_cache_clear_cache();
			//W3 Total Cache
			if(function_exists('w3tc_flush_all')) w3tc_flush_all();
			//WP Rocket
			if(function_exists('rocket_clean_domain')) rocket_clean_domain();
			//LiteSpeed Cache
			if(class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'purge_all')) LiteSpeed_Cache_API::purge_all();
			//WP Engine
			if(class_exists('WpeCommon')){
				if(method_exists('WpeCommon', 'purge_memcached')) WpeCommon::purge_memcached();
				if(method_exists('WpeCommon', 'purge_varnish_cache')) WpeCommon::purge_varnish_cache();
			}
			//object cache
			wp_cache_flush();
		}

		// ── robots.txt ────────────────────────────────────────────────────

		//replace or append the Sitemap: directive in robots.txt for the active mapped domain
		public function filter_robots_txt($output, $public){
			if(empty($this->getCurrentMapping()['match'])) return $output;
			$sitemapUrl = $this->getCurrentMapping()['match']['robotssitemap'] ?? '';
			if(empty($sitemapUrl)) return $output;
			$output = preg_replace('/^Sitemap:.*\n?/im', '', $output);
			$output = rtrim($output) . "\nSitemap: " . esc_url($sitemapUrl) . "\n";
			return $output;
		}

		// ── AJAX handlers ─────────────────────────────────────────────────

		//health check: do a HEAD request to the mapped domain and return the HTTP status code
		public function ajax_health_check(){
			check_ajax_referer('mdmap_health_check', 'nonce');
			if(!current_user_can('manage_options')) wp_die(-1);
			$domain = isset($_POST['domain']) ? sanitize_text_field(wp_unslash($_POST['domain'])) : '';
			if(empty($domain)) wp_send_json_error(array('message' => esc_html__('No domain provided.', 'mdmap_app')));
			$protocol = is_ssl() ? 'https' : 'http';
			$url      = $protocol . '://' . $domain . '/';
			$response = wp_remote_head($url, array('timeout' => 10, 'redirection' => 0, 'sslverify' => false));
			if(is_wp_error($response)){
				wp_send_json_error(array('message' => $response->get_error_message()));
			}
			wp_send_json_success(array('code' => intval(wp_remote_retrieve_response_code($response)), 'url' => esc_url($url)));
		}

		//export: return current mappings as JSON
		public function ajax_export_mappings(){
			check_ajax_referer('mdmap_export', 'nonce');
			if(!current_user_can('manage_options')) wp_die(-1);
			wp_send_json_success(array('mappings' => $this->getMappings()));
		}

		//import: accept JSON, merge with existing mappings through the sanitizer, save
		public function ajax_import_mappings(){
			check_ajax_referer('mdmap_import', 'nonce');
			if(!current_user_can('manage_options')) wp_die(-1);
			$json = isset($_POST['data']) ? wp_unslash($_POST['data']) : '';
			if(empty($json)) wp_send_json_error(array('message' => esc_html__('No data provided.', 'mdmap_app')));
			$data = json_decode($json, true);
			if(json_last_error() !== JSON_ERROR_NONE){
				wp_send_json_error(array('message' => esc_html__('Invalid JSON.', 'mdmap_app')));
			}
			if(!isset($data['mappings']) || !is_array($data['mappings'])){
				wp_send_json_error(array('message' => esc_html__('The file doesn\'t look like a valid mappings export.', 'mdmap_app')));
			}

			//merge: keep existing mappings, append the imported ones, then let the sanitizer
			//dedupe (existing wins on a domain/path clash) and validate the union.
			$existing     = $this->getMappings();
			$existingList = (!empty($existing['mappings']) && is_array($existing['mappings'])) ? array_values($existing['mappings']) : array();
			$importedList = array_values($data['mappings']);

			$toSanitize = array();
			$n = 0;
			foreach(array_merge($existingList, $importedList) as $m){
				if(is_array($m)) $toSanitize['cnt_' . $n++] = $m;
			}
			$sanitized = $this->sanitize_mappings_group($toSanitize);
			update_option('mdmap_app_mappings', $sanitized);
			$this->setMappings($sanitized);

			//report how many actually landed vs. were dropped as duplicates/invalid
			$before  = count($existingList);
			$after   = (!empty($sanitized['mappings']) && is_array($sanitized['mappings'])) ? count($sanitized['mappings']) : 0;
			$added   = max(0, $after - $before);
			$skipped = max(0, count($importedList) - $added);
			wp_send_json_success(array(
				'message' => sprintf(
					/* translators: 1: number of mappings added, 2: number skipped */
					esc_html__('Import complete: %1$d added, %2$d skipped (duplicate or invalid).', 'mdmap_app'),
					$added, $skipped
				)
			));
		}
	}

	$app_plugin_instance = MultipleDomainMapper::get_instance();
}
