<?php
if ( !class_exists( 'wp_es_feeder' ) ) {
  class wp_es_feeder {
    const LOG_ALL = false;
    const SYNC_LIMIT = 25;

    protected $loader;
    protected $plugin_name;
    protected $version;
    public $plugin_dir;
    public $proxy;
    public $error;

    public function __construct() {
      $this->plugin_name = 'wp-es-feeder';
      $this->version = '2.4.0';
      $this->proxy = get_option($this->plugin_name)['es_url']; // proxy
      $this->error = '[WP_ES_FEEDER] [:LOG] ';
      $this->plugin_dir = trailingslashit(dirname(plugin_dir_path(__FILE__)));
      $this->load_api();
      $this->load_dependencies();
      $this->define_admin_hooks();
    }

    function load_api() {
      require plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-es-api-helpers.php';
      require plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-language-config.php';
      require plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-elasticsearch-wp-rest-api-controller.php';
    }

    private function load_dependencies() {
      if (!class_exists('GuzzleHttp\Client'))
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'vendor/autoload.php';
      require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wp-es-feeder-loader.php';
      require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-wp-es-feeder-admin.php';
      $this->loader = new wp_es_feeder_Loader();
    }

    private function define_admin_hooks() {
      $plugin_admin = new wp_es_feeder_Admin( $this->get_plugin_name(), $this->get_version() );
      $this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
      $this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts', 10, 1 );

      // add menu item
      $this->loader->add_action( 'admin_menu', $plugin_admin, 'add_plugin_admin_menu' );

      // add "Do not index" box to posts and pages
      $this->loader->add_action( 'add_meta_boxes', $plugin_admin, 'add_admin_meta_boxes' );
      $this->loader->add_action( 'add_meta_boxes', $plugin_admin, 'add_admin_cdp_taxonomy' );

      // add settings link to plugin
      $plugin_basename = plugin_basename( plugin_dir_path( __DIR__ ) . $this->plugin_name . '.php' );
      $this->loader->add_filter( 'plugin_action_links_' . $plugin_basename, $plugin_admin, 'add_action_links' );

      // save/update our plugin options
      $this->loader->add_action( 'admin_init', $plugin_admin, 'options_update' );

      // admin notices
      $this->loader->add_action('admin_notices', $plugin_admin, 'sync_errors_notice');

      // add sync status to list tables
      $this->loader->add_filter('manage_posts_columns', $plugin_admin, 'columns_head');
      $this->loader->add_action('manage_posts_custom_column', $plugin_admin, 'columns_content', 10, 2);
      foreach( $this->get_allowed_post_types() as $post_type ) {
        $this->loader->add_filter('manage_edit-' . $post_type . '_sortable_columns', $plugin_admin, 'sortable_columns');
      }

      // elasticsearch indexing hook actions
      add_action( 'save_post', array( $this, 'save_post' ), 101, 2 );
      add_action( 'transition_post_status', array($this, 'delete_post'), 10, 3 );
      add_action( 'wp_ajax_es_request', array( $this, 'es_request') );
      add_action( 'wp_ajax_es_initiate_sync', array($this, 'es_initiate_sync') );
      add_action( 'wp_ajax_es_process_next', array($this, 'es_process_next') );
      add_action( 'wp_ajax_es_truncate_logs', array($this, 'truncate_logs') );
      add_action( 'wp_ajax_es_validate_sync', array($this, 'validate_sync') );
      add_action( 'wp_ajax_es_reload_log', array($this, 'reload_log') );

      add_filter( 'heartbeat_received', array($this, 'heartbeat'), 10, 2 );
    }

    public function run() {
      $this->loader->run();
    }

    public function get_plugin_name() {
      return $this->plugin_name;
    }

    public function get_loader() {
      return $this->loader;
    }

    public function get_version() {
      return $this->version;
    }

    public function get_proxy_server() {
      return $this->proxy;
    }

    /**
     * Triggerd by heartbeat AJAX event, added the sync status indicator HTML
     * if the data includes es_sync_status which contains a post ID and will be
     * converted to the sync status indicator HTML.
     * If the data includes es_sync_status_counts, send back an array of counts
     * for each stuats ID.
     *
     * @param $response
     * @param $data
     * @return mixed
     */
    public function heartbeat($response, $data) {
      if ( !empty( $data['es_sync_status'] ) ) {
        $post_id = $data[ 'es_sync_status' ];
        $status = $this->get_sync_status( $post_id );
        ob_start();
        $this->sync_status_indicator( $status );
        $response[ 'es_sync_status' ] = ob_get_clean();
      }
      if ( !empty( $data['es_sync_status_counts'] ) ) {
        $response[ 'es_sync_status_counts' ] = $this->get_sync_status_counts();
      }
      return $response;
    }

    public function truncate_logs() {
      $path = $this->plugin_dir . '*.log';
      foreach (glob($path) as $log)
        file_put_contents($log, '');
      echo 1;
      exit;
    }

    public function reload_log() {
      $path = $this->plugin_dir . 'callback.log';
      $log = $this->tail($path, 100);
      echo json_encode($log);
      exit;
    }

    public function validate_sync() {
      set_time_limit(600);
      global $wpdb;
      $size = 500;
      $result = null;
      $modifieds = [];
      $stats = ['updated' => 0, 'es_missing' => 0, 'wp_missing' => 0, 'mismatched' => 0];
      $request = [
        'url' => 'search',
        'method' => 'POST',
        'body' => [
          'query' => 'site:' . $this->get_site(),
          'include' => ['post_id', 'modified'],
          'size' => $size,
          'from' => 0,
          'scroll' => '60s'
        ],
        'print' => false
      ];
      do {
        $result = $this->es_request($request);
        if ($result && $result->hits && count($result->hits->hits)) {
          foreach ($result->hits->hits as $hit) {
            $modifieds[$hit->_source->post_id] = $hit->_source->modified;
          }
        }
        $request = [
          'url' => 'search/scroll',
          'method' => 'POST',
          'body' => [
            'scrollId' => $result->_scroll_id,
            'scroll' => '60s'
          ],
          'print' => false
        ];
      } while ($result && $result->hits && count($result->hits->hits));

      if (count($modifieds)) {
        $opts = get_option( $this->plugin_name );
        $post_types = $opts[ 'es_post_types' ];
        $formats = implode(',', array_fill(0, count($post_types), '%s'));
        $query = "SELECT p.ID, p.post_modified, ms.meta_value as sync_status 
                  FROM $wpdb->posts p 
                      LEFT JOIN (SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_cdp_sync_status') ms ON p.ID = ms.post_id
                      LEFT JOIN (SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_iip_index_post_to_cdp_option') m ON p.ID = m.post_id
                  WHERE p.post_type IN ($formats) AND p.post_status = 'publish' AND (m.meta_value IS NULL OR m.meta_value != 'no') AND ms.meta_value IS NOT NULL";
        $query = $wpdb->prepare($query, array_keys($post_types));
        $rows = $wpdb->get_results($query);
        $update_errors = [];
        $update_synced = [];
        foreach ($rows as $row) {
          if (array_key_exists($row->ID, $modifieds)) {
            if ($modifieds[$row->ID] == mysql2date('c', $row->post_modified)) {
              if ( $row->sync_status != ES_FEEDER_SYNC::SYNCED ) {
                $update_synced[] = $row->ID;
                $stats['updated']++;
              }
            } else {
              $stats['mismatched']++;
              if ( $row->sync_status != ES_FEEDER_SYNC::ERROR ) {
                $update_errors[] = $row->ID;
                $stats['updated']++;
              }
            }
            unset($modifieds[$row->ID]);
          } else {
            $stats['es_missing']++;
            if ( $row->sync_status != ES_FEEDER_SYNC::ERROR ) {
              $update_errors[] = $row->ID;
              $stats['updated']++;
            }
          }
        }
        if (count($update_synced)) {
          $query = "UPDATE $wpdb->postmeta SET meta_value = '" . ES_FEEDER_SYNC::SYNCED . "' WHERE meta_key = '_cdp_sync_status' AND post_id IN (" . implode(',', $update_synced) . ")";
          $wpdb->query( $query );
        }
        if (count($update_errors)) {
          $query = "UPDATE $wpdb->postmeta SET meta_value = '" . ES_FEEDER_SYNC::ERROR . "' WHERE meta_key = '_cdp_sync_status' AND post_id IN (" . implode(',', $update_errors) . ")";
          $wpdb->query( $query );
        }
        $stats['wp_missing'] = count($modifieds);
      }

      if (defined('DOING_AJAX') && DOING_AJAX) {
        wp_send_json($stats);
        exit;
      }
      return $stats;
    }

    /**
     * Gets counts for each sync status
     */
    public function get_sync_status_counts() {
      global $wpdb;
      $opts = get_option( $this->plugin_name );
      $post_types = $opts[ 'es_post_types' ] ?: [];
      $formats = implode(',', array_fill(0, count($post_types), '%s'));

      $query = "SELECT IFNULL(ms.meta_value, 0) as status, COUNT(IFNULL(ms.meta_value, 0)) as total 
                  FROM $wpdb->posts p 
                  LEFT JOIN (SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_cdp_sync_status') ms ON p.ID = ms.post_id
                  LEFT JOIN (SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_iip_index_post_to_cdp_option') m ON p.ID = m.post_id
                  WHERE p.post_type IN ($formats) AND p.post_status = 'publish' AND (m.meta_value IS NULL OR m.meta_value != 'no') GROUP BY IFNULL(ms.meta_value, 0)";
      $query = $wpdb->prepare($query, array_keys($post_types));
      $totals = $wpdb->get_results($query);
      $ret = array();
      foreach ($totals as $total)
          $ret[$total->status] = $total->total;
      return $ret;
    }

    /**
     * Prints the appropriately colored sync status indicator dot given a status.
     * Text indicates whether or not to include indicator label text.
     *
     * @param $status
     * @param $text boolean
     * @param $merge_publishes
     */
    public function sync_status_indicator($status, $text = true, $merge_publishes = true) {
      $color = 'black';
      switch ( $status ) {
        case ES_FEEDER_SYNC::SYNCING:
        case ES_FEEDER_SYNC::SYNC_WHILE_SYNCING:
          $color = 'yellow';
          break;
        case ES_FEEDER_SYNC::SYNCED:
          $color = 'green';
          break;
        case ES_FEEDER_SYNC::RESYNC:
          $color = 'orange';
          break;
        case ES_FEEDER_SYNC::ERROR:
          $color = 'red';
          break;
      }
      ?>
      <div class="sync-status sync-status-<?=$color?>" title="<?=ES_FEEDER_SYNC::display($status, $merge_publishes)?>"></div>
      <div class="sync-status-label"><?=$text ? ES_FEEDER_SYNC::display($status, $merge_publishes) : ''?></div>
      <?php
    }

    /**
     * Check to see how long a post has been syncing and update to
     * error status if it's been longer than SYNC_TIMEOUT.
     * Post modified and sync status can be supplied to save a database query or two.
     * Then return the status.
     *
     * @param $post_id
     * @param $status - Current sync status
     * @return int
     */
    public function get_sync_status($post_id, $status = null) {
      if (!$status)
        $status = get_post_meta($post_id, '_cdp_sync_status', true);
      if (!in_array($status, [ES_FEEDER_SYNC::ERROR, ES_FEEDER_SYNC::RESYNC]) && !ES_FEEDER_SYNC::sync_allowed($status)) {
        // check to see if we should resolve to error based on time since last sync
        $last_sync = get_post_meta($post_id, '_cdp_last_sync', true);
        if ($last_sync)
            $last_sync = new DateTime($last_sync);
        else
            $last_sync = new DateTime('now');
        $interval = date_diff($last_sync, new DateTime('now'));
        $diff = $interval->format('%i');
        if ($diff >= ES_API_HELPER::SYNC_TIMEOUT) {
          $status = ES_FEEDER_SYNC::RESYNC;
          update_post_meta($post_id, '_cdp_sync_status', $status);
        }
      }
      return $status;
    }

    /**
     * Iterate over posts in a syncing or erroneous state. If syncing for longer than
     * the SYNC_TIMEOUT time, escalate to error status.
     * Return stats on total errors (if any).
     */
    public function check_sync_errors() {
      global $wpdb;
      $result = ['errors' => 0, 'ids' => []];
      $statuses = array(ES_FEEDER_SYNC::ERROR, ES_FEEDER_SYNC::SYNCING, ES_FEEDER_SYNC::SYNC_WHILE_SYNCING);
      $statuses = implode(',', $statuses);
      $query = "SELECT p.ID, p.post_type, m.meta_value as sync_status FROM $wpdb->posts p LEFT JOIN $wpdb->postmeta m ON p.ID = m.post_id
                  WHERE m.meta_key = '_cdp_sync_status' AND m.meta_value IN ($statuses)";
      $rows = $wpdb->get_results($query);
      foreach ($rows as $row) {
        $status = $this->get_sync_status($row->ID, $row->sync_status);
        if ($status == ES_FEEDER_SYNC::ERROR) {
          $result['errors']++;
          if (!array_key_exists($row->post_type, $result))
            $result[$row->post_type] = 0;
          $result[$row->post_type]++;
          $result['ids'][] = $row->ID;
        }
      }
      return $result;
    }

    /**
     * Triggered via AJAX, clears out old sync data and initiates a new sync process.
     * If sync_errors is present, we will only initiate a sync for posts with a sync error.
     */
    public function es_initiate_sync() {
      global $wpdb;
      if (isset($_POST['sync_errors']) && $_POST['sync_errors']) {
        $errors = $this->check_sync_errors();
        $post_ids = $errors['ids'];
        if (count($post_ids))
          $wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_key = '_cdp_sync_status' AND post_id IN (" . implode(',', $post_ids) . ")");
        else {
          echo json_encode(array('error' => true, 'message' => 'No posts found.'));
          exit;
        }
        $results = $this->get_resync_totals();
        wp_send_json($results);
      } else {
        $wpdb->delete($wpdb->postmeta, array('meta_key' => '_cdp_sync_status'));
        $post_ids = $this->get_syncable_posts();
        if (!count($post_ids)) {
          echo json_encode(array('error' => true, 'message' => 'No posts found.'));
          exit;
        }
        wp_send_json(array('done' => 0, 'response' => null, 'results' => null, 'total' => count($post_ids), 'complete' => 0));
      }
      exit;
    }

    /**
     * Grabs the next post in the queue and sends it to the API.
     * Updates the postmeta indicating that this post has been synced.
     * Returns a JSON object containing the API response for the current post
     * as well as stats on the sync queue.
     */
    public function es_process_next() {
      global $wpdb;
      while( get_option($this->plugin_name . '_syncable_posts') !== false );
      update_option($this->plugin_name . '_syncable_posts', 1, false);
      set_time_limit(120);
      $post_ids = $this->get_syncable_posts(self::SYNC_LIMIT);
      if (!count($post_ids)) {
        delete_option($this->plugin_name . '_syncable_posts');
        $results = $this->get_resync_totals();
        wp_send_json(array('done' => 1, 'total' => $results['total'], 'complete' => $results['complete']));
        exit;
      } else {
        $results = [];
        $vals = [];
        $query = "INSERT INTO $wpdb->postmeta (post_id, meta_key, meta_value) VALUES";
        foreach ($post_ids as $post_id) $vals[] = "($post_id, '_cdp_sync_status', '1')";
        $query .= implode(',', $vals);
        $wpdb->query($query);
        delete_option($this->plugin_name . '_syncable_posts');
        foreach ($post_ids as $post_id) {
          update_post_meta($post_id, '_cdp_last_sync', date('Y-m-d H:i:s'));
          $post = get_post($post_id);
          $resp = $this->addOrUpdate($post, false, true, false);
          $wpdb->update($wpdb->posts, ['post_status' => 'publish'], ['ID' => $post_id]);
          if (!$resp) {
            $results[] = [
              'title' => $post->post_title,
              'post_id' => $post->ID,
              'message' => 'ERROR: Connection failed.',
              'error' => true
            ];
            update_post_meta($post_id, '_cdp_sync_status', ES_FEEDER_SYNC::ERROR);
          } else if ( !is_object( $resp ) || $resp->message !== 'Sync in progress.' ) {
            $results[] = [
              'title' => $post->post_title,
              'post_id' => $post->ID,
              'response' => $resp,
              'message' => 'See error response.',
              'error' => true
            ];
            update_post_meta($post_id, '_cdp_sync_status', ES_FEEDER_SYNC::ERROR);
          }
        }
        $totals = $this->get_resync_totals();
        $totals['done'] = 0;
        $totals['results'] = $results;
        wp_send_json($totals);
      }
      exit;
    }

    private function get_syncable_posts($limit = null) {
      global $wpdb;
      $opts = get_option( $this->plugin_name );
      $post_types = $opts[ 'es_post_types' ];
      $formats = implode(',', array_fill(0, count($post_types), '%s'));
      $query = "SELECT p.ID FROM $wpdb->posts p 
                  LEFT JOIN (SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_cdp_sync_status') ms ON p.ID = ms.post_id
                  LEFT JOIN (SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_iip_index_post_to_cdp_option') m ON p.ID = m.post_id
                  WHERE p.post_type IN ($formats) AND p.post_status = 'publish' AND (m.meta_value IS NULL OR m.meta_value != 'no')
                    AND ms.meta_value IS NULL ORDER BY p.post_date DESC";
      if ($limit) $query .= " LIMIT $limit";
      $query = $wpdb->prepare($query, array_keys($post_types));
      $post_ids = $wpdb->get_col($query);
      return $post_ids ?: [];
    }

    public function get_resync_totals() {
      global $wpdb;
      $opts = get_option( $this->plugin_name );
      $post_types = $opts[ 'es_post_types' ] ?: [];
      $formats = implode(',', array_fill(0, count($post_types), '%s'));
      $query = "SELECT COUNT(*) as total, SUM(IF(ms.meta_value IS NOT NULL, 1, 0)) as complete FROM $wpdb->posts p 
                  LEFT JOIN (SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_cdp_sync_status') ms ON p.ID = ms.post_id
                  LEFT JOIN (SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_iip_index_post_to_cdp_option') m ON p.ID = m.post_id
                  WHERE p.post_type IN ($formats) AND p.post_status = 'publish' AND (m.meta_value IS NULL OR m.meta_value != 'no')";
      $query = $wpdb->prepare($query, array_keys($post_types));
      $row = $wpdb->get_row($query);
      return array('done' => $row->total == $row->complete ? 1 : 0, 'response' => null, 'results' => null, 'total' => $row->total, 'complete' => $row->complete);
    }

    public function save_post( $id, $post ) {
      $settings  = get_option( $this->plugin_name );
      $post_type = $post->post_type;

      if (array_key_exists('index_post_to_cdp_option', $_POST)) {
        update_post_meta(
          $id,
          '_iip_index_post_to_cdp_option',
          $_POST[ 'index_post_to_cdp_option' ]
        );
      }

      if (array_key_exists('cdp_language', $_POST))
        update_post_meta($id, '_iip_language', $_POST['cdp_language']);

      if (array_key_exists('cdp_terms', $_POST))
        update_post_meta($id, '_iip_taxonomy_terms', $_POST['cdp_terms']);
      else if ($_POST && is_array($_POST))
        update_post_meta($id, '_iip_taxonomy_terms', array());

      // return early if missing parameters
      if ( $post == null || !array_key_exists('es_post_types', $settings) || !array_key_exists($post_type, $settings['es_post_types']) || !$settings['es_post_types'][$post_type] ) {
        return;
      }

      if ($post->post_status !== 'publish') return;

      $this->post_sync_send($post, false);
      $this->translate_post($post);
    }

    public function post_sync_send($post, $print = true) {
      // we only care about modifying published posts
      if ( $post->post_status === 'publish' ) {
        if (array_key_exists('index_post_to_cdp_option', $_POST)) {
          // check to see if post should be indexed or removed from index
          $shouldIndex = $_POST[ 'index_post_to_cdp_option' ];
        } else
          $shouldIndex = get_post_meta($post->ID, '_iip_index_post_to_cdp_option', true) ?: 'yes';

        if (isset($shouldIndex) && $shouldIndex) {
          // default to indexing - post has to be specifically set to 'no'
          if ( $shouldIndex === 'no' ) {
            $this->delete( $post );
          } else {
            $this->addOrUpdate( $post, $print );
          }
        }
      }
    }

    /**
     * Fire PUT requests containing associated translations after save_post.
     *
     * @param $id
     */
    public function translate_post( $id ) {
      global $cdp_language_helper, $wpdb;
      if ( !function_exists( 'icl_object_id' ) ) return;
      if ( is_object( $id ) ) {
        $post = $id;
      } else {
        $post = get_post( $id );
      }

      $settings  = get_option( $this->plugin_name );
      $post_type = $post->post_type;

      if ( $post == null || !array_key_exists('es_post_types', $settings) || !array_key_exists($post_type, $settings['es_post_types']) || !$settings['es_post_types'][$post_type] ) {
        return;
      }

      // get associated post IDs
      $query = "SELECT trid, element_type FROM {$wpdb->prefix}icl_translations WHERE element_id = $post->ID";
      $vars = $wpdb->get_row($query);
      if (!$vars || !$vars->trid || !$vars->element_type) return;
      $query = "SELECT element_id FROM {$wpdb->prefix}icl_translations WHERE trid = $vars->trid AND element_type = '$vars->element_type' AND element_id != $post->ID";
      $post_ids = $wpdb->get_col($query);
      if (self::LOG_ALL) {
        $this->log("Found " . count($post_ids) . " translations for: $post->ID", 'feeder.log');
      }

      foreach ($post_ids as $post_id) {
        $post = get_post($post_id);
        if ($post->post_status !== 'publish') continue;
        $sync = get_post_meta($post_id, '_iip_index_post_to_cdp_option', true);
        if ($sync === 'no') continue;
        if (!$this->is_syncable($post_id)) continue;

        $translations = $cdp_language_helper->get_translations($post_id);
        $options = [
          'url' => $this->get_post_type_label($post->post_type) . "/" . $this->get_uuid($post_id),
          'method' => 'PUT',
          'body' => [
            'languages' => $translations
          ],
          'print' => false
        ];
        $callback = $this->create_callback($post_id);

        if (self::LOG_ALL)
            $this->log("Sending off translations for: $post_id", 'feeder.log');

        $response = $this->es_request( $options, $callback, false );
        if (self::LOG_ALL && $response)
          $this->log("IMMEDIATE RESPONSE (PUT):\r\n" . print_r($response, 1), 'callback.log');
        if ( !$response ) {
          error_log( print_r( $this->error . 'translate_post() request failed', true ) );
          update_post_meta( $post_id, '_cdp_sync_status', ES_FEEDER_SYNC::ERROR );
          delete_post_meta( $post_id, '_cdp_sync_uid' );
        } else if (isset($response->error) && $response->error) {
          update_post_meta( $post_id, '_cdp_sync_status', ES_FEEDER_SYNC::ERROR );
          delete_post_meta( $post_id, '_cdp_sync_uid' );
          if (!self::LOG_ALL && $response)
            $this->log("IMMEDIATE RESPONSE (PUT):\r\n" . print_r($response, 1), 'callback.log');
        }
      }
    }

    /**
     * Only delete posts if the old status was 'publish'.
     * Otherwise, do nothing.
     *
     * @param $new_status
     * @param $old_status
     * @param $id
     */
    public function delete_post( $new_status, $old_status, $id ) {
      if ( $old_status === $new_status || $old_status !== 'publish') return;
      if ( is_object( $id ) ) {
        $post = $id;
      } else {
        $post = get_post( $id );
      }

      $settings  = get_option( $this->plugin_name );
      $post_type = $post->post_type;

      if ( $post == null || !array_key_exists('es_post_types', $settings) || !array_key_exists($post_type, $settings['es_post_types']) || !$settings['es_post_types'][$post_type] ) {
        return;
      }

      $this->delete( $post );
      $this->translate_post( $post );
    }

    public function addOrUpdate( $post, $print = true, $callback_errors_only = false, $check_syncable = true ) {
      if ( $check_syncable && !$this->is_syncable( $post->ID ) ) {
        $response = ['error' => 1, 'message' => 'Could not publish while publish in progress.'];
        if ($print)
          wp_send_json($response);
        return $response;
      }

      // plural form of post type
      $post_type_name = ES_API_HELPER::get_post_type_label( $post->post_type, 'name' );

      // api endpoint for wp-json
      $wp_api_url = '/'.ES_API_HELPER::NAME_SPACE.'/'.rawurlencode($post_type_name).'/'.$post->ID;
      $request = new WP_REST_Request('GET', $wp_api_url);
      $api_response = rest_do_request( $request );
      $api_response = $api_response->data;

      if ( !$api_response || isset( $api_response[ 'code' ] ) ) {
        error_log( print_r( $this->error . 'addOrUpdate() calling wp rest failed', true ) );
        $api_response['error'] = true;
        $api_response['url'] = $wp_api_url;
        if ( $print ) {
          wp_send_json( $api_response );
        }
        return $api_response;
      }

      // create callback for this post
      $callback = $this->create_callback($post->ID);

      $options = array(
        'url' => $this->get_post_type_label($post->post_type),
        'method' => 'POST',
        'body' => $api_response,
        'print' => $print
      );

      $response = $this->es_request( $options, $callback, $callback_errors_only );
      if (self::LOG_ALL)
        $this->log("IMMEDIATE RESPONSE:\r\n" . print_r($response, 1), 'callback.log');
      if ( !$response ) {
        error_log( print_r( $this->error . 'addOrUpdate()[add] request failed', true ) );
        update_post_meta( $post->ID, '_cdp_sync_status', ES_FEEDER_SYNC::ERROR );
        delete_post_meta( $post->ID, '_cdp_sync_uid' );
      } else if (isset($response->error) && $response->error) {
        update_post_meta( $post->ID, '_cdp_sync_status', ES_FEEDER_SYNC::ERROR );
        delete_post_meta( $post->ID, '_cdp_sync_uid' );
        if (!self::LOG_ALL && $response)
          $this->log("IMMEDIATE RESPONSE:\r\n" . print_r($response, 1), 'callback.log');
      }

      return $response;
    }

    public function delete( $post ) {
      if ( !$this->is_syncable( $post->ID ) ) return;

      update_post_meta( $post->ID, '_cdp_sync_status', ES_FEEDER_SYNC::SYNCING );

      $uuid = $this->get_uuid($post);
      $delete_url = $this->get_post_type_label($post->post_type) . '/' . $uuid;

      $options = array(
         'url' => $delete_url,
         'method' => 'DELETE',
         'print' => false
      );

      $response = $this->es_request( $options );
      if ( !$response ) {
        error_log( print_r( $this->error . 'addOrUpdate()[add] request failed', true ) );
        update_post_meta( $post->ID, '_cdp_sync_status', ES_FEEDER_SYNC::ERROR );
      } else if (isset($response->error) && $response->error) {
        update_post_meta( $post->ID, '_cdp_sync_status', ES_FEEDER_SYNC::ERROR );
      } else {
        update_post_meta( $post->ID, '_cdp_sync_status', ES_FEEDER_SYNC::NOT_SYNCED );
      }
      delete_post_meta( $post->ID, '_cdp_sync_uid' );
    }

    public function es_request($request, $callback = null, $callback_errors_only = false) {
      $is_internal = false;
      $error = false;
      $results = null;

      $headers = [];
      if ($callback) $headers['callback'] = $callback;
      $headers['callback_errors'] = $callback_errors_only ? 1 : 0;

      $opts = ['timeout' => 30, 'http_errors' => false];

      $config = get_option( $this->plugin_name );

      $token = $config['es_token'];
      if ( !empty( $token ) ) $headers['Authorization'] = 'Bearer ' . $token;

      if (!$request) {
        $request = $_POST['data'];
      } else {
        $is_internal = true;
        $opts['base_uri'] = trim($config['es_url'], '/') . '/';
      }
      
      $client = new GuzzleHttp\Client($opts);
      try {
        // if a body is provided
        if ( isset($request['body']) ) {
          // unwrap the post data from ajax call
          if (!$is_internal) {
            $body = urldecode(base64_decode($request['body']));
          } else {
            $body = json_encode($request['body']);
            $headers['Content-Type'] = 'application/json';
          }

          $body = $this->is_domain_mapped($body);

          $response = $client->request($request['method'], $request['url'], ['body' => $body, 'headers' => $headers]);
        } else {
          $response = $client->request($request['method'], $request['url'], ['headers' => $headers]);
        }

        $body = $response->getBody();
        $results = $body->getContents();
      } catch (GuzzleHttp\Exception\ConnectException $e) {
        $error = $e->getMessage();
      } catch (GuzzleHttp\Exception\RequestException $e) {
        $error = $e->getMessage();
      } catch (Exception $e) {
        $error = $e->getMessage();
      }

      if (self::LOG_ALL && !in_array($request['url'], ['owner','language','taxonomy'])) {
        $this->log("Sending " . $request['method'] . " request to: " . $request['url'] . (array_key_exists('body', $request) && array_key_exists('post_id', $request['body']) ? ', post_id : ' . $request['body']['post_id'] : ''), 'feeder.log');
        $this->log( "\n\nREQUEST: " . print_r( $request, 1 ), 'es_request.log' );
        $this->log( "RESULTS: " . print_r( $results, 1 ), 'es_request.log' );
        $this->log( "ERROR: " . print_r( $error, 1 ), 'es_request.log' );
      }

      if ($error) {
        if ($is_internal || (isset($request['print']) && !$request['print'])) {
          return (object) array(
            'error' => 1,
            'message' => $error
          );
        } else {
          wp_send_json(array(
            'error' => 1,
            'message' => $error
          ));
          return null;
        }
      } else if ($is_internal || (isset($request['print']) && !$request['print'])) {
        return json_decode($results);
      } else {
        wp_send_json(json_decode($results));
        return null;
      }
    }

    /**
     * Determines if a post can be synced or not. Syncable means that it is not in the process
     * of being synced. If it is not syncable, update the sync status to inform the user that
     * they needs to wait until the sync is complete and then resync.
     *
     * @param $post_id
     * @return bool
     */
    public function is_syncable( $post_id ) {
      global $wpdb;
      // check sync status by attempting to update and if rows updated then sync is in progress
      $query = "UPDATE $wpdb->postmeta 
                SET meta_value = '" . ES_FEEDER_SYNC::SYNC_WHILE_SYNCING . "' 
                WHERE post_id = $post_id AND meta_key = '_cdp_sync_status' 
                    AND meta_value IN (" . ES_FEEDER_SYNC::SYNCING . "," . ES_FEEDER_SYNC::SYNC_WHILE_SYNCING . ")";
      $rows = $wpdb->query($query);
      if ($rows) {
        if (self::LOG_ALL)
            $this->log("Post not syncable so status updated to SYNC_WHILE_SYNCING: $post_id, sync_uid:" . get_post_meta($post_id, '_cdp_sync_uid', true) ?: 'none', 'feeder.log');
        return false;
      }
      return true;
    }

    private function is_domain_mapped( $body ) {
      // check if domain is mapped
      $opt = get_option( $this->plugin_name );
      $protocol = is_ssl() ? 'https://' : 'http://';
      $opt_url = $opt['es_wpdomain'];
      $opt_url = str_replace($protocol, '', $opt_url);
      $site_url = site_url();
      $site_url = str_replace($protocol, '', $site_url);

      if ($opt_url !== $site_url) {
        $body = str_replace($site_url, $opt_url, $body);
      }

      return $body;
    }

    public function get_taxonomy() {
      $args = [
        'method' => 'GET',
        'url' => 'taxonomy?tree'
      ];
      $data = $this->es_request($args);
      if ($data) {
          if (is_object($data) && $data->error)
              return [];
          if (is_array($data) && array_key_exists('error', $data) && $data['error'])
              return [];
          else if (is_array($data))
              return $data;
      }
      return [];
    }

    public function get_allowed_post_types() {
      $settings = get_option( $this->plugin_name );
      $types = [];
      if ($settings && $settings['es_post_types'])
        foreach ($settings['es_post_types'] as $post_type => $val)
          if ($val) $types[] = $post_type;
      return $types;
    }

    private function create_callback($post_id = null) {
      $options = get_option($this->plugin_name);
      $es_wpdomain = $options[ 'es_wpdomain' ] ? $options[ 'es_wpdomain' ] : null;
      if ( !$es_wpdomain ) $es_wpdomain = site_url();
      if (!$post_id) return $es_wpdomain . '/wp-json/' . ES_API_HELPER::NAME_SPACE . '/callback/noop';

      // create callback for this post
      global $wpdb;
      do {
        $uid = uniqid();
        $query = "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_cdp_sync_uid' AND meta_value = '$uid'";
      } while ($wpdb->get_var($query));
      $options = get_option($this->plugin_name);
      $es_wpdomain = $options[ 'es_wpdomain' ] ? $options[ 'es_wpdomain' ] : null;
      if ( !$es_wpdomain ) $es_wpdomain = site_url();
      $callback = $es_wpdomain . '/wp-json/' . ES_API_HELPER::NAME_SPACE . '/callback/' . $uid;
      if (self::LOG_ALL)
        $this->log("Created callback for: $post_id with UID: $uid", 'feeder.log');
      update_post_meta($post_id, '_cdp_sync_uid', $uid);
      update_post_meta($post_id, '_cdp_sync_status', ES_FEEDER_SYNC::SYNCING);
      update_post_meta($post_id, '_cdp_last_sync', date('Y-m-d H:i:s'));
      return $callback;
    }

    /**
     * Construct UUID which is site domain delimited by dashes and not periods, underscore, and post ID.
     *
     * @param $post
     * @return string
     */
    public function get_uuid($post) {
      $post_id = $post;
      if (!is_numeric($post_id))
        $post_id = $post->ID;
      $opt = get_option( $this->plugin_name );
      $url = $opt['es_wpdomain'];
      $args = parse_url($url);
      $host = $url;
      if (array_key_exists('host', $args))
        $host = $args['host'];
      else
        $host = str_ireplace('https://', '', str_ireplace('http://', '', $host));
      
      return "{$host}_{$post_id}";
    }

    public function get_site() {
      $opt = get_option( ES_API_HELPER::PLUGIN_NAME );
      $url = $opt[ 'es_wpdomain' ];
      $args = parse_url( $url );
      $host = $url;
      if ( array_key_exists( 'host', $args ) )
        $host = $args[ 'host' ];
      else
        $host = str_ireplace( 'https://', '', str_ireplace( 'http://', '', $host ) );
      return $host;
    }

    /**
     * Retrieves the singular post type label for use in API end points.
     * Some post types are registered as plural but we want to use singular end point URLs.
     *
     * @param $post_type
     * @return string
     */
    public function get_post_type_label($post_type) {
      $obj = get_post_type_object($post_type);
      if (!$obj) return $post_type;
      return $obj->labels->singular_name;
    }

    public function log($str, $file = 'feeder.log') {
      $path = $this->plugin_dir . $file;
      file_put_contents($path, date('[m/d/y H:i:s] ') . trim(print_r($str,1)) . "\r\n\r\n", FILE_APPEND);
    }

    /**
     * Slightly modified version of http://www.geekality.net/2011/05/28/php-tail-tackling-large-files/
     * @author Torleif Berger, Lorenzo Stanco
     * @link http://stackoverflow.com/a/15025877/995958
     * @license http://creativecommons.org/licenses/by/3.0/
     *
     * @param $filepath
     * @param $lines
     * @param $adaptive
     * @return string
     */
    public function tail($filepath, $lines = 1, $adaptive = true) {
      // Open file
      $f = @fopen($filepath, "rb");
      if ($f === false) return '';
      // Sets buffer size, according to the number of lines to retrieve.
      // This gives a performance boost when reading a few lines from the file.
      if (!$adaptive) $buffer = 4096;
      else $buffer = ($lines < 2 ? 64 : ($lines < 10 ? 512 : 4096));
      // Jump to last character
      fseek($f, -1, SEEK_END);
      // Read it and adjust line number if necessary
      // (Otherwise the result would be wrong if file doesn't end with a blank line)
      if (fread($f, 1) != "\n") $lines -= 1;

      // Start reading
      $output = '';
      $chunk = '';
      // While we would like more
      while (ftell($f) > 0 && $lines >= 0) {
        // Figure out how far back we should jump
        $seek = min(ftell($f), $buffer);
        // Do the jump (backwards, relative to where we are)
        fseek($f, -$seek, SEEK_CUR);
        // Read a chunk and prepend it to our output
        $output = ($chunk = fread($f, $seek)) . $output;
        // Jump back to where we started reading
        fseek($f, -mb_strlen($chunk, '8bit'), SEEK_CUR);
        // Decrease our line counter
        $lines -= substr_count($chunk, "\n");
      }
      // While we have too many lines
      // (Because of buffer size we might have read too many)
      while ($lines++ < 0) {
        // Find first newline and remove all text before that
        $output = substr($output, strpos($output, "\n") + 1);
      }
      // Close file and return
      fclose($f);
      return trim($output);
    }
  }
}
