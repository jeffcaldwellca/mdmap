<?php
/*
Plugin Name: Muldoon
Plugin URI:  https://www.jeffcaldwell.ca/muldoon/
Description: Point any extra domain or subdomain at a WordPress page, post, or archive without redirects. The mapped domain always stays in the visitor's address bar.
Version:     2.0
Requires at least: 4.5
Requires PHP: 7.4
Author:      Jeff Caldwell
License:     GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: muldoon
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
			add_filter( 'admin_footer_text', array( $this, 'footer_easter_egg' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );

			//set current uri
			$phpServerVar = self::resolvePhpServerVar($this->getSettings(), $_SERVER);
			$this->setCurrentURI(($_SERVER[$phpServerVar] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''));

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

		//pick which $_SERVER var identifies the requested host
		private static function resolvePhpServerVar($settings, $server){
			//an explicitly saved, valid choice is honored as-is - the fallback below must not override it
			$saved = (!empty($settings) && isset($settings['php_server'])) ? $settings['php_server'] : '';
			if( $saved === 'SERVER_NAME' || $saved === 'HTTP_HOST' ){
				return $saved;
			}
			//no saved choice: default to SERVER_NAME, but fall back to HTTP_HOST when SERVER_NAME doesn't reflect the actual requested host (e.g. behind a proxy or with domain aliases)
			if( !empty($server['HTTP_HOST']) && (!isset($server['SERVER_NAME']) || $server['HTTP_HOST'] !== $server['SERVER_NAME']) ){
				return 'HTTP_HOST';
			}
			return 'SERVER_NAME';
		}

		//footer easter egg decision - pure + testable. Returns the "Clever girl." markup
		//only when we're on our own admin screen; otherwise the footer text is unchanged.
		private static function footerEasterEggText($currentText, $screenId, $hookSuffix){
			if( !empty($hookSuffix) && $screenId === $hookSuffix ){
				return '<span class="mdmap_app_easter_egg">Clever girl.</span>';
			}
			return $currentText;
		}

		//admin_footer_text filter - scopes the easter egg to the Muldoon screen only
		public function footer_easter_egg($text){
			$screen   = function_exists('get_current_screen') ? get_current_screen() : null;
			$screenId = ($screen && isset($screen->id)) ? $screen->id : '';
			return self::footerEasterEggText($text, $screenId, $this->menuHookSuffix);
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
		//set textdomain
	  public function set_textdomain(){
			load_plugin_textdomain( 'muldoon', false, dirname( $this->pluginBasename ) . '/languages/' );
	  }

		//enqueue scripts and styles in admin
		public function admin_scripts($hook){
			if($hook !== $this->menuHookSuffix) return;
			//custom assets
			wp_enqueue_style( 'mdmap_app_adminstyle', plugin_dir_url( __FILE__ ) . 'assets/css/admin.css', array(), $this->pluginVersion );
			wp_register_script( 'mdmap_app_adminscript', plugin_dir_url( __FILE__ ) . 'assets/js/admin.js', array('jquery', 'jquery-ui-accordion', 'jquery-ui-sortable'), $this->pluginVersion, true );
			wp_localize_script( 'mdmap_app_adminscript', 'localizedObj', array(
				'removedMessage'  => esc_html__('This mapping will be deleted when you save. Click Undo to keep it.', 'muldoon'),
				'undoMessage'     => esc_html__('Undo', 'muldoon'),
				'dismissMessage'  => __( 'Dismiss this notice.', 'muldoon' ),
				'unsavedMessage'  => esc_html__('You have unsaved changes. Click Save Mappings to apply them.', 'muldoon'),
				'unsavedStatus'   => esc_html__('Unsaved changes', 'muldoon'),
				'healthOk'        => esc_html__('Reachable', 'muldoon'),
				'healthFail'      => esc_html__('Unreachable', 'muldoon'),
				'healthError'     => esc_html__('Check failed', 'muldoon'),
				'exportLabel'     => esc_html__('Export JSON', 'muldoon'),
				'importSuccess'   => esc_html__('Mappings imported! Reloading…', 'muldoon'),
				'importError'     => esc_html__('Import failed. Please check the file and try again.', 'muldoon'),
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
			$this->menuHookSuffix = add_submenu_page( 'tools.php', esc_html__('Muldoon', 'muldoon'), esc_html__('Muldoon', 'muldoon'), 'manage_options', $this->pluginBasename, array( $this, 'output_menu_page') );
			$this->register_settings();
		}

		//add a "Settings" link to the plugin's row on the Plugins screen
		public function add_settings_link($links){
			$url = admin_url('tools.php?page=' . $this->pluginBasename);
			array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'muldoon') . '</a>');
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
			$raw_tab = isset($_GET['tab']) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
			$active_tab = in_array($raw_tab, $valid_tabs, true) ? $raw_tab : 'mappings';
			//translated, human label per tab - keeps the "Save %s" button and "%s saved" notice fully localized
			$tab_labels = array(
				'mappings' => esc_html__('Mappings', 'muldoon'),
				'settings' => esc_html__('Settings', 'muldoon'),
				'advanced' => esc_html__('Developers', 'muldoon'),
				'help'     => esc_html__('Help', 'muldoon'),
			);
			$active_tab_name = isset($tab_labels[$active_tab]) ? $tab_labels[$active_tab] : ucfirst($active_tab);

			echo '<div class="wrap mdmap_app_wrap">';

				//branded page header - raptor/claw mark + stacked wordmark (no separator)
				echo '<div class="mdmap_app_brandhead">';
					echo '<svg class="mdmap_app_logo" viewBox="0 0 120 120" aria-hidden="true" focusable="false">'
					   . '<g fill="none" stroke="#2271b1" stroke-width="3.5"><circle cx="60" cy="60" r="33"/><ellipse cx="60" cy="60" rx="14" ry="33"/><line x1="27" y1="60" x2="93" y2="60"/></g>'
					   . '<g fill="#1d2327"><path d="M30,102 Q52,74 60,40 Q40,70 30,102 Z"/><path d="M46,108 Q70,72 92,22 Q62,68 46,108 Z"/><path d="M64,104 Q84,74 102,38 Q80,72 64,104 Z"/></g>'
					   . '</svg>';
					echo '<div class="mdmap_app_brandtext">';
						echo '<h1>' . esc_html__('Muldoon', 'muldoon') . '</h1>';
						echo '<p class="mdmap_app_tagline">' . esc_html__('The Multi Domain Name Mapper', 'muldoon') . '</p>';
					echo '</div>';
				echo '</div>';

				//updated notices
				if ( isset( $_GET['settings-updated'] ) ) {
					/* translators: %s: name of the active tab (Mappings, Settings, etc.). */
					add_settings_error( 'mdmap_app_messages', 'mdmap_app_message', sprintf(esc_html__( '%s saved successfully', 'muldoon' ), esc_html($active_tab_name)), 'updated' );
				}
				settings_errors( 'mdmap_app_messages' );

				//page intro
				echo '<p>' . esc_html__('Point any extra domain or subdomain at a page, post, or archive on this site, with no redirects. Visitors always see the mapped domain in their address bar. New here? Check the Help tab for setup instructions.', 'muldoon') . '</p>';

				//tabs
				echo '<h2 class="nav-tab-wrapper">';
					echo '<a href="?page='. $this->pluginBasename .'&amp;tab=mappings" class="nav-tab ' . ($active_tab == 'mappings' ? 'nav-tab-active ' : '') . '">' . $tab_labels['mappings'] . '</a>';
					echo '<a href="?page='. $this->pluginBasename .'&amp;tab=settings" class="nav-tab ' . ($active_tab == 'settings' ? 'nav-tab-active ' : '') . '">' . $tab_labels['settings'] . '</a>';
					echo '<a href="?page='. $this->pluginBasename .'&amp;tab=advanced" class="nav-tab ' . ($active_tab == 'advanced' ? 'nav-tab-active ' : '') . '">' . $tab_labels['advanced'] . '</a>';
					echo '<a href="?page='. $this->pluginBasename .'&amp;tab=help" class="nav-tab ' . ($active_tab == 'help' ? 'nav-tab-active ' : '') . '">' . $tab_labels['help'] . '</a>';
				echo '</h2>';

				//main form
				echo '<form action="options.php" method="post">';

					//inputs based on current tab
					switch($active_tab){
						case 'settings':{
							add_settings_section(
								'mdmap_app_section_settings',
								esc_html__('Domain mapping settings', 'muldoon'),
								array($this, 'section_settings_callback'),
								$this->pluginBasename
							);

							add_settings_field(
								'mdmap_app_field_settings_phpserver',
								esc_html__('PHP Server Variable:', 'muldoon'),
								array($this, 'field_settings_phpserver_callback'),
								$this->pluginBasename,
								'mdmap_app_section_settings'
							);

							add_settings_field(
								'mdmap_app_field_settings_compatibilitymode',
								esc_html__('Compatibility mode:', 'muldoon'),
								array($this, 'field_settings_compatibilitymode_callback'),
								$this->pluginBasename,
								'mdmap_app_section_settings'
							);

					add_settings_field(
						'mdmap_app_field_settings_excluded_domains',
						esc_html__('Excluded domains:', 'muldoon'),
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
						echo '<h2>' . esc_html__('Developer Hooks', 'muldoon') . '</h2>';
						echo '<p>' . esc_html__('Muldoon provides action and filter hooks for developers to extend its behaviour.', 'muldoon') . '</p>';
						echo '<ul>';
							echo '<li>' . esc_html__('Actions prefix:', 'muldoon') . ' <code>mdmap_appa_</code></li>';
							echo '<li>' . esc_html__('Filters prefix:', 'muldoon') . ' <code>mdmap_appf_</code></li>';
						echo '</ul>';
						echo '<p>' . esc_html__('Search for these prefixes in the plugin source to see all available hooks.', 'muldoon') . '</p>';
						break 1;
					}
					case 'help':{
						echo '<h2>' . esc_html__('Setup', 'muldoon') . '</h2>';
						echo '<p>' . esc_html__('Before adding any mappings, each extra domain needs to point to the same web root as your main WordPress site. Two things to set up:', 'muldoon') . '</p>';
						echo '<ol>';
							echo '<li>' . esc_html__('Set the A-record of each extra domain to your main site\'s IP address (done through your domain registrar\'s DNS settings).', 'muldoon') . '</li>';
							echo '<li>' . esc_html__('Configure your hosting to route all domains to the same WordPress directory (virtual host, domain alias, or parked domain).', 'muldoon') . '</li>';
						echo '</ol>';
						echo '<p>' . esc_html__('Quick test: drop a file in your WordPress root and confirm it\'s accessible from both domains before adding any mappings.', 'muldoon') . '</p>';
						echo '<p>' . esc_html__('Using nginx? Switch the PHP Server Variable to HTTP_HOST in the Settings tab.', 'muldoon') . '</p>';
						break 1;
					}
					default:{ //default is our mappings tab

							add_settings_section(
								'mdmap_app_section_mappings',
								esc_html__('Domain mappings', 'muldoon'),
								array($this, 'section_mappings_callback'),
								$this->pluginBasename
							);

							add_settings_field(
								'mdmap_app_field_mappings_uris',
								esc_html__('Your domain mappings:', 'muldoon'),
								array($this, 'field_mappings_uris_callback'),
								$this->pluginBasename,
								'mdmap_app_section_mappings'
							);
							settings_fields('mdmap_app_mappings_group');
							do_settings_sections( $this->pluginBasename );

							break 1;
						}
					}

					//dynamic submit button, wrapped in a sticky action bar (status left, button right)
					if($active_tab != 'help' && $active_tab != 'advanced'){
						if($active_tab != 'mappings' || $this->saveMappingsButtonDisabled == false){
							echo '<div class="mdmap_app_actionbar">';
								echo '<span class="mdmap_app_save_status" aria-live="polite"></span>';
								/* translators: %s: name of the active tab (Mappings, Settings, etc.). */
								submit_button(sprintf(esc_html__('Save %s', 'muldoon'), $active_tab_name), 'primary', 'submit', false);
							echo '</div>';
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
			echo esc_html__('Advanced server settings. Most sites can leave these at their defaults.', 'muldoon');
		}
		public function field_settings_phpserver_callback(){
			$options = $this->getSettings();
			if(empty($options)) $options = array();

			$options['php_server'] = isset($options['php_server']) ? $options['php_server'] : 'SERVER_NAME';

			echo '<p>' . esc_html__('Most sites work fine with the default. If your mappings aren\'t resolving correctly, switch to HTTP_HOST below.', 'muldoon') . '</p>';
			echo '<p><label><input type="radio" name="mdmap_app_settings[php_server]" value="SERVER_NAME" '. checked('SERVER_NAME', $options['php_server'], false ) . ' />$_SERVER["SERVER_NAME"] ('. esc_html__('Default', 'muldoon') .')</label></p>';
			echo '<p><label><input type="radio" name="mdmap_app_settings[php_server]" value="HTTP_HOST" '. checked('HTTP_HOST', $options['php_server'], false ) .' />$_SERVER["HTTP_HOST"] ('. esc_html__('recommended for nginx', 'muldoon') .')</label></p>';
		}
		public function field_settings_compatibilitymode_callback(){
			$options = $this->getSettings();
			if(empty($options)) $options = array();

			$options['compatibilitymode'] = isset($options['compatibilitymode']) ? $options['compatibilitymode'] : 0;

			echo sprintf('<p>%s</p>',
				esc_html__('Disables domain replacement inside wp-admin. Useful if a page builder or visual editor has trouble loading mapped pages.', 'muldoon')
			);
			echo '<p><label><input type="radio" name="mdmap_app_settings[compatibilitymode]" value="0" '. checked('0', $options['compatibilitymode'], false ) . ' />Off ('. esc_html__('Default', 'muldoon') .')</label></p>';
			echo '<p><label><input type="radio" name="mdmap_app_settings[compatibilitymode]" value="1" '. checked('1', $options['compatibilitymode'], false ) .' />On</label></p>';
		}
		public function field_settings_excluded_domains_callback(){
			$options  = $this->getSettings();
			$excluded = !empty($options['excluded_domains']) ? $options['excluded_domains'] : '';
			if( defined('ICL_SITEPRESS_VERSION') || defined('POLYLANG_VERSION') ){
				echo '<p class="description">' . esc_html__('A multilingual plugin (WPML or Polylang) is active. Add the language-specific domains it manages here to prevent the mapper from processing them.', 'muldoon') . '</p>';
			}
			echo '<textarea name="mdmap_app_settings[excluded_domains]" rows="4" class="large-text code">' . esc_textarea($excluded) . '</textarea>';
			echo '<p class="description">' . esc_html__('One domain per line. The mapper ignores requests arriving on these domains.', 'muldoon') . '</p>';
		}

		//generate options fields output for the mappings tab
		public function section_mappings_callback(){
			echo '<strong>' . esc_html__('Left field', 'muldoon') . '</strong>: ';
			echo esc_html__('enter the domain you want to use. http/https and www/non-www are handled automatically, so one entry per domain is all you need.', 'muldoon');
			echo '<br />';
			echo '<strong>' . esc_html__('Right field', 'muldoon') . '</strong>: ';
			echo esc_html__('enter the WordPress path this domain should point to. All pages beneath that path are included automatically.', 'muldoon');
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
								echo '<span class="mdmap_app_mapping_body_icon mdmap_app_delete_mapping"><a href="#" title="' . esc_html__('Remove this mapping', 'muldoon') . '">' . esc_html__('Remove', 'muldoon') . ' <i>&cross;</i></a></span>';
								do_action('mdmap_appa_after_mapping_body', $cnt, $mapping);
							echo '</div>';
						echo '</article>';
						$cnt++;
					}
				}
			echo '</section>';

			//work out available headroom up front so the "Add mapping" affordance and the bottom Save button stay in agreement
			$numberOfSettings = 14; //domain, path, customheadcode, redirection, enabled (+hidden companion), noindex, passthrough, sitename, sitetagline, ogimage, ga4id, robotssitemap, sortorder
			$atLimit = ($cnt >= ((intval(ini_get('max_input_vars')) - 100) / $numberOfSettings));

			echo '<section class="mdmap_app_new_mapping">';
				echo '<h3 class="mdmap_app_new_mapping_title">' . esc_html__('Add a new mapping', 'muldoon') . '</h3>';
				echo '<article class="'. apply_filters( 'mdmap_appf_mapping_class', 'mdmap_app_mapping mdmap_app_mapping_new' ) .'">';
					echo '<div class="mdmap_app_mapping_header">';
						echo '<div><div class="mdmap_app_input_wrap"><span class="mdmap_app_input_prefix">http[s]://</span><input type="text" name="mdmap_app_mappings[cnt_new][domain]" placeholder="[www.]newdomain.com" /></div><div class="mdmap_app_input_hint">' . esc_html__('Enter the domain you want to map.', 'muldoon') . '</div></div>';
						echo '<div class="mdmap_app_mapping_arrow">&raquo;</div>';
						echo '<div><div class="mdmap_app_input_wrap"><span class="mdmap_app_input_prefix">'. esc_url(get_home_url()) .'</span><input type="text" name="mdmap_app_mappings[cnt_new][path]" placeholder="/mappedpage" /></div><div class="mdmap_app_input_hint">' . esc_html__('Enter the path to the desired root for this mapping', 'muldoon') . '</div></div>';
					echo '</div>';
					echo '<div class="mdmap_app_mapping_body">';
						do_action('mdmap_appa_after_mapping_body', 'new', false);
					echo '</div>';
				echo '</article>';
				//explicit add action - gives the blank row a clear call-to-action; it submits the form so the new row (and any pending edits) save together
				if(!$atLimit){
					echo '<p class="mdmap_app_add_mapping_row">';
						submit_button(esc_html__('Add mapping', 'muldoon'), 'primary', 'mdmap_add_mapping', false);
						echo '<span class="mdmap_app_add_mapping_hint">' . esc_html__('Fill in the domain and path above, then click Add mapping. Pending edits to other rows are saved at the same time.', 'muldoon') . '</span>';
					echo '</p>';
				}
			echo '</section>';

			echo '<div class="mdmap_app_io_toolbar">';
				echo '<button type="button" id="mdmap_export_btn" class="button">' . esc_html__('Export Mappings', 'muldoon') . '</button>';
				echo '<label for="mdmap_import_file" class="button">' . esc_html__('Import Mappings', 'muldoon') . '</label>';
				echo '<input type="file" id="mdmap_import_file" accept=".json" style="display:none" />';
			echo '</div>';

			//warn (and hide the Save button) only when genuinely near the server's max_input_vars ceiling
			if($atLimit){
				$this->saveMappingsButtonDisabled = true;
				echo '<section class="notice notice-error">';
					echo '<p>';
						echo sprintf(
							/* translators: 1: configured max_input_vars value, 2: the literal "max_input_vars", 3: number of mappings, 4: input vars per mapping, 5: total input vars used, 6: the literal "max_input_vars". */
							esc_html__('Heads up! Your server allows a maximum of %1$s %2$s. With %3$s mapping(s) at %4$s vars each (%5$s total), you\'re approaching the limit. Increase %6$s to save more mappings.', 'muldoon'),
							esc_html(ini_get('max_input_vars')),
							'<em>max_input_vars</em>',
							esc_html($cnt),
							esc_html($numberOfSettings),
							esc_html($cnt . ' x ' . $numberOfSettings . ' = ' . ($cnt*$numberOfSettings)),
							'<em>max_input_vars</em>'
						);
						echo ' ' . esc_html__('Ask your host how to raise the max_input_vars limit.', 'muldoon');
					echo '</p>';
					echo '<p>';
						echo esc_html__('The Save button has been hidden to prevent partial data loss. Increase max_input_vars and reload to restore it.', 'muldoon');
					echo '</p>';
				echo '</section>';
			}
		}

		//function to show additional input fields in mapping body
		public function render_advanced_mapping_inputs($cnt, $mapping){
			$isNew = ($cnt === 'new');
			//the new-mapping row passes false; normalise so the field reads below are warning-free
			if(!is_array($mapping)) $mapping = array();

			//enabled/disabled toggle - shown for all mappings including new
			$isEnabled = ($isNew || !isset($mapping['enabled']) || intval($mapping['enabled']) !== 0);
			echo '<div class="mdmap_app_mapping_additional_input mdmap_app_toggle_row">';
				//hidden companion so an unchecked box still submits a 0 - the checkbox value wins when checked
				echo '<input type="hidden" name="mdmap_app_mappings[cnt_'.$cnt.'][enabled]" value="0" />';
				echo '<label class="mdmap_app_toggle_label">';
					echo '<input type="checkbox" name="mdmap_app_mappings[cnt_'.$cnt.'][enabled]" value="1" ' . checked($isEnabled, true, false) . ' />';
					echo ' ' . esc_html__('Active', 'muldoon');
				echo '</label>';
				if(!$isNew){
					echo '<button type="button" class="button button-small mdmap_app_health_btn" data-domain="' . esc_attr($mapping['domain']) . '">' . esc_html__('Test connection', 'muldoon') . '</button>';
					echo '<span class="mdmap_app_health_result"></span>';
				}
				echo '</div>';

			//hidden field carrying this row's position; the admin JS renumbers these on drag-to-reorder.
			//only existing rows participate in the sortable (the new row lives outside that container).
			if(!$isNew){
				echo '<input type="hidden" class="mdmap_app_sortorder" name="mdmap_app_mappings[cnt_'.$cnt.'][sortorder]" value="' . esc_attr($cnt) . '" />';
			}

			echo '<div class="mdmap_app_mapping_additional_input">';
				echo '<p class="mdmap_app_mapping_additional_input_header">' . esc_html__('Custom <head> code (this domain only)', 'muldoon') . '</p>';
				echo '<textarea name="mdmap_app_mappings[cnt_'.$cnt.'][customheadcode]" placeholder="' . esc_attr__('e.g. <meta name="google-site-verification" content="…" />', 'muldoon') . '">' . esc_textarea(html_entity_decode($mapping['customheadcode'] ?? '')) . '</textarea>';
			echo '</div>';

			echo '<div class="mdmap_app_mapping_additional_input">';
				echo '<p class="mdmap_app_mapping_additional_input_header">' . esc_html__('301 Redirect to mapped domain', 'muldoon') . '</p>';
				echo '<label><input type="checkbox" name="mdmap_app_mappings[cnt_'.$cnt.'][redirection]" value="301" ' . checked( !empty($mapping['redirection']), true, false ) . ' />' . esc_html__('Redirect visitors who arrive at the original path to this domain instead.', 'muldoon') . '</label>';
			echo '</div>';

			echo '<div class="mdmap_app_mapping_additional_input">';
				echo '<p class="mdmap_app_mapping_additional_input_header">' . esc_html__('Noindex original URL', 'muldoon') . '</p>';
				echo '<label><input type="checkbox" name="mdmap_app_mappings[cnt_'.$cnt.'][noindex]" value="1" ' . checked( !empty($mapping['noindex']), true, false ) . ' />' . esc_html__('Add a noindex tag to the original path so search engines index only the mapped domain.', 'muldoon') . '</label>';
			echo '</div>';

			echo '<div class="mdmap_app_mapping_additional_input">';
				echo '<p class="mdmap_app_mapping_additional_input_header">' . esc_html__('Pass through unmatched paths', 'muldoon') . '</p>';
				echo '<label><input type="checkbox" name="mdmap_app_mappings[cnt_'.$cnt.'][passthrough]" value="1" ' . checked( !empty($mapping['passthrough']), true, false ) . ' />' . esc_html__('When a request on this domain doesn\'t resolve under the mapped path, serve the same path from the main site instead of 404.', 'muldoon') . '</label>';
				echo '<p class="description">' . esc_html__('Useful when the alternate domain acts as a branded alias of the main site. Any public top-level page on the main site becomes reachable from this domain, so review before enabling on a site with private pages.', 'muldoon') . '</p>';
			echo '</div>';

			echo '<div class="mdmap_app_mapping_additional_input">';
				echo '<p class="mdmap_app_mapping_additional_input_header">' . esc_html__('Site name (this domain only)', 'muldoon') . '</p>';
				echo '<input type="text" class="regular-text" name="mdmap_app_mappings[cnt_'.$cnt.'][sitename]" value="' . esc_attr($mapping['sitename'] ?? '') . '" placeholder="' . esc_attr__('Leave empty to use the main site name', 'muldoon') . '" />';
				echo '<p class="description">' . esc_html__('Replaces the site name in <title> tags, Open Graph site_name, RSS feeds, and SEO plugin output while visitors browse this mapped domain.', 'muldoon') . '</p>';
			echo '</div>';

			echo '<div class="mdmap_app_mapping_additional_input">';
				echo '<p class="mdmap_app_mapping_additional_input_header">' . esc_html__('Site tagline (this domain only)', 'muldoon') . '</p>';
				echo '<input type="text" class="regular-text" name="mdmap_app_mappings[cnt_'.$cnt.'][sitetagline]" value="' . esc_attr($mapping['sitetagline'] ?? '') . '" placeholder="' . esc_attr__('Leave empty to use the main site tagline', 'muldoon') . '" />';
				/* translators: %sitedesc% is a Yoast/RankMath template variable shown to the user verbatim. */
				echo '<p class="description">' . esc_html__('Replaces the site tagline (blogdescription) and Yoast/RankMath %sitedesc% expansions when visitors are on this mapped domain.', 'muldoon') . '</p>';
			echo '</div>';

			echo '<div class="mdmap_app_mapping_additional_input">';
				echo '<p class="mdmap_app_mapping_additional_input_header">' . esc_html__('Default Open Graph image (this domain only)', 'muldoon') . '</p>';
				echo '<input type="text" class="regular-text" name="mdmap_app_mappings[cnt_'.$cnt.'][ogimage]" value="' . esc_attr($mapping['ogimage'] ?? '') . '" placeholder="https://example.com/share-card.jpg" />';
				echo '<p class="description">' . esc_html__('Used as a fallback og:image / twitter:image when a page on this mapped domain has no specific share image set. Per-page Yoast/RankMath images still take precedence.', 'muldoon') . '</p>';
			echo '</div>';

			echo '<div class="mdmap_app_mapping_additional_input">';
				echo '<p class="mdmap_app_mapping_additional_input_header">' . esc_html__('Analytics ID (GA4 or GTM, this domain only)', 'muldoon') . '</p>';
				echo '<input type="text" class="regular-text" name="mdmap_app_mappings[cnt_'.$cnt.'][ga4id]" value="' . esc_attr($mapping['ga4id'] ?? '') . '" placeholder="G-XXXXXXXXXX" />';
				echo '<p class="description">' . esc_html__('Injects a gtag.js snippet when visitors are browsing this mapped domain.', 'muldoon') . '</p>';
			echo '</div>';

			echo '<div class="mdmap_app_mapping_additional_input">';
				echo '<p class="mdmap_app_mapping_additional_input_header">' . esc_html__('robots.txt Sitemap URL (this domain only)', 'muldoon') . '</p>';
				echo '<input type="text" class="regular-text" name="mdmap_app_mappings[cnt_'.$cnt.'][robotssitemap]" value="' . esc_attr($mapping['robotssitemap'] ?? '') . '" placeholder="https://example.com/sitemap.xml" />';
				echo '<p class="description">' . esc_html__('Overrides the Sitemap: line in robots.txt while visitors browse this domain.', 'muldoon') . '</p>';
			echo '</div>';
		}

		//sanitize options fields input
		public function sanitize_settings_group($options){
			if(empty($options)){
				return $options;
			}

			//be sure that only a correct server-value will be saved
			$options['php_server'] = (isset($options['php_server']) && ( $options['php_server'] == 'SERVER_NAME' || $options['php_server'] == 'HTTP_HOST' )) ? $options['php_server'] : 'SERVER_NAME';

			//sanitize excluded domains - strip protocols and trailing slashes; one per line
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
					$domain = str_replace([']', '['], '', trim(trim($val['domain'] ?? ''), '/'));
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
								if(function_exists('add_settings_error')) add_settings_error( 'mdmap_app_messages', 'mdmap_app_error_code', esc_html__('Mapping to "/" is not allowed because it would intercept all site traffic.', 'muldoon'), 'error' );
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
								$val['customheadcode'] = wp_kses($val['customheadcode'], $this->allowedHeadCodeTags());
							}

							//only allow integers (statuscode) for redirection
							if(!empty($val['redirection'])) $val['redirection'] = intval($val['redirection']);

								//enabled flag (1 = active, 0 = disabled; absent means active - backward compat)
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
								if(function_exists('add_settings_error')) add_settings_error( 'mdmap_app_messages', 'mdmap_app_error_code', esc_html__('At least one mapping with duplicate domain or path has been dropped.', 'muldoon'), 'error' );
							}
						}else{
							//check for existence, since this may be called in an upgrade process earlier, when this is not available yet
							if(function_exists('add_settings_error')) add_settings_error( 'mdmap_app_messages', 'mdmap_app_error_code', esc_html__('One or more mappings had an invalid domain or path and were skipped.', 'muldoon'), 'error' );
						}
					//if we have only one input filled
					}else if(!(($val['domain'] ?? '') == '' && ($val['path'] ?? '') == '')){
						//check for existence, since this may be called in an upgrade process earlier, when this is not available yet
						if(function_exists('add_settings_error')) add_settings_error( 'mdmap_app_messages', 'mdmap_app_error_code', esc_html__('One or more mappings were skipped because both a domain and a path are required.', 'muldoon'), 'error' );
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
			$this->setOriginalRequestURI($_SERVER['REQUEST_URI'] ?? '');

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
						//leave REQUEST_URI as the original path - currentMapping stays set so canonical/og/admin-bar still use the mapped domain
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
			$requestPath = parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
			if( !$this->pathUnderBase( $requestPath, $mapping['path'] ) ) return;

			//extra path beyond the mapped base (e.g. /product-a/subpage -> /subpage)
			//substr can return false in PHP 7 if offset >= string length; normalise to empty string
			$extraPath = substr( $_SERVER['REQUEST_URI'] ?? '', strlen( $mapping['path'] ) );
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
				//anchor on ://host so only the exact site host is swapped; subdomains (img.mydomain.com) are left intact because :// is immediately followed by the subdomain, not the bare host.
				//note: every absolute link to the main host in this string IS repointed at the mapped host (assets, same-site links, etc.). on a branded-alias domain that deliberately keeps the visitor on the mapped domain - it is not path-scoped.
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
				//swap only ://host so subdomains stay intact. mappedHost is host-only by design: assets live at the mapped domain's web root (mappeddomain.com/wp-content/...), never under a mapping's sub-path.
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
					// html_entity_decode handles data saved by older versions of this plugin (stored with htmlentities);
					// re-run wp_kses afterwards so decoding can't reintroduce markup the sanitizer would have stripped
					echo wp_kses(html_entity_decode($this->getCurrentMapping()['match']['customheadcode']), $this->allowedHeadCodeTags());
				}
			}
		}

		// ── Helpers ──────────────────────────────────────────────────────

		//return true when a mapping should be processed (absent enabled key = active, for backward compat)
		private function isMappingEnabled($mapping){
			return !isset($mapping['enabled']) || intval($mapping['enabled']) !== 0;
		}

		//allowed tags/attributes for the per-mapping custom <head> code - used on save and re-applied on output
		private function allowedHeadCodeTags(){
			return array(
				'meta'     => array('name'=>true,'content'=>true,'property'=>true,'charset'=>true,'http-equiv'=>true),
				'link'     => array('rel'=>true,'href'=>true,'type'=>true,'media'=>true,'sizes'=>true,'hreflang'=>true),
				'script'   => array('type'=>true,'src'=>true,'async'=>true,'defer'=>true,'id'=>true),
				'style'    => array('type'=>true),
				'noscript' => array(),
			);
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
			//posts and custom post types reachable via the rewrite rules.
			//suspend our own home_url rewrite first: url_to_postid() builds its lookup url via home_url(),
			//which we otherwise repoint at the mapped domain mid-request - that would break the match.
			if(function_exists('url_to_postid')){
				remove_filter('home_url', array($this, 'replace_home_url'), 10);
				$postId = url_to_postid(home_url($path));
				add_filter('home_url', array($this, 'replace_home_url'), 10, 3);
				if($postId) return true;
			}
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
			//we own the canonical - stop core from printing a second one
			remove_action('wp_head', 'rel_canonical');
			$mapping    = $this->getCurrentMapping()['match'];
			$protocol   = is_ssl() ? 'https' : 'http';
			$requestUri = $this->getOriginalRequestURI();
			if(empty($requestUri)) $requestUri = $_SERVER['REQUEST_URI'] ?? '';
			//canonical uses the path only - query strings (utm_*, etc.) would create non-canonical variants
			$canonicalPath = parse_url($requestUri, PHP_URL_PATH);
			if(!is_string($canonicalPath) || $canonicalPath === '') $canonicalPath = '/';
			echo '<link rel="canonical" href="' . esc_url($protocol . '://' . $mapping['domain'] . $canonicalPath) . '" />' . "\n";
		}

		//output noindex on the original (un-mapped) path when the mapping has noindex enabled
		public function output_noindex_tag(){
			if(!empty($this->getCurrentMapping()['match'])) return; //on mapped domain: no noindex needed
			$mappings = $this->getMappings();
			if(empty($mappings) || !isset($mappings['mappings'])) return;
			$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
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
				'title' => '<span aria-hidden="true" style="color:#46b450;margin-right:4px">&#9679;</span>' . esc_html__('Mapped domain:', 'muldoon') . ' <strong>' . esc_html($domain) . '</strong>',
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
			if(!empty($value)) return $value; //page has its own - leave it alone
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
			if(empty($domain)) wp_send_json_error(array('message' => esc_html__('No domain provided.', 'muldoon')));
			//only test domains that are actually configured - keeps this endpoint from probing arbitrary hosts
			$known    = false;
			$mappings = $this->getMappings();
			if(!empty($mappings['mappings']) && is_array($mappings['mappings'])){
				foreach($mappings['mappings'] as $m){
					if(isset($m['domain']) && $m['domain'] === $domain){ $known = true; break; }
				}
			}
			if(!$known) wp_send_json_error(array('message' => esc_html__('Unknown domain. Only configured mappings can be tested.', 'muldoon')));
			$protocol = is_ssl() ? 'https' : 'http';
			$url      = $protocol . '://' . $domain . '/';
			//verify TLS: a mapped domain with a broken or mismatched certificate should report as a failure, since this plugin requires valid shared SSL
			$response = wp_remote_head($url, array('timeout' => 10, 'redirection' => 0, 'sslverify' => true));
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
			if(empty($json)) wp_send_json_error(array('message' => esc_html__('No data provided.', 'muldoon')));
			$data = json_decode($json, true);
			if(json_last_error() !== JSON_ERROR_NONE){
				wp_send_json_error(array('message' => esc_html__('Invalid JSON.', 'muldoon')));
			}
			if(!isset($data['mappings']) || !is_array($data['mappings'])){
				wp_send_json_error(array('message' => esc_html__('The file doesn\'t look like a valid mappings export.', 'muldoon')));
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
					esc_html__('Import complete: %1$d added, %2$d skipped (duplicate or invalid).', 'muldoon'),
					$added, $skipped
				)
			));
		}
	}

	$app_plugin_instance = MultipleDomainMapper::get_instance();
}
