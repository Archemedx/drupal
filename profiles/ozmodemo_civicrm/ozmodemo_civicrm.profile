<?php

define('CIVI_DEMO_PROFILE_NAME', 'ozmodemo_civicrm');
define('CIVI_DEMO_PROFILE_DESCRIPTION', 'Custom ozmosis profile with demo data and CiviCRM support');
define('CIVI_DEMO_PROFILE_DUMP', '/ozmo.sql');
define('CIVI_DEMO_DUMP', '/demo.sql');
define('CIVI_DEMO_HOME', '/tt1\.oz01\.ozmobiz\.com/');

function ozmodemo_civicrm_profile_modules() {
    return array();
}

function ozmodemo_civicrm_profile_details() {
    return array(
        'name' => CIVI_DEMO_PROFILE_NAME,
        'description' => CIVI_DEMO_PROFILE_DESCRIPTION
    );
}

function ozmodemo_civicrm_profile_task_list() {
}

function ozmodemo_civicrm_profile_tasks(&$task, $url) {
    $this_dir = dirname(__FILE__);

// Import a dump file
    $dump_file = $this_dir . CIVI_DEMO_PROFILE_DUMP;
    $success = import_civi_demo_dump($dump_file, FALSE);
    if (!$success) {
        return;
    }

    $dump_file = $this_dir . CIVI_DEMO_DUMP;
    $success = import_civi_demo_dump($dump_file, TRUE);
    if (!$success) {
        return;
    }

// Get solr path from drush and set it
    $solr = drush_get_option('my_solr_path', 'site');
    if (strlen($solr)){
      variable_set('apachesolr_path', $solr);
    }
    else {
      variable_set('apachesolr_path', '/solr/dummy');
    }

// Reset a couple of variables based on uri also retrieved from drush
    $my_uri = drush_get_option('my_uri', 'site');
    if (strlen($my_uri)){
      variable_set('og_domain_default_value', $my_uri);
      variable_set('og_domain_disabled_page', ('http://' . $my_uri));
      variable_set('trace_file', ('sites/' . $my_uri . '/files/logs/trace.log'));
      variable_set('file_directory_path', ('sites/' . $my_uri . '/files'));
      $extlink_exclude = preg_replace('/\./', '\.', $my_uri);
      variable_set('extlink_exclude', '\/' . $extlink_exclude);
    }
    else {
      variable_set('og_domain_default_value', '');
      variable_set('og_domain_disabled_page', '');
      variable_set('trace_file', 'sites/default/files/logs/trace.log');
      variable_set('file_directory_path', 'sites/default/files');
    }

// This is a really ugly way to get civi up and running
// We'll set the variables for civicrm, but installing the module breaks
// So, we'll do that in the post_provision process
/*
    $civi_modules = array('civicrm', 'civicrmtheme');
    module_enable($civi_modules);
*/
    variable_set('civicrmtheme', 'pushbutton');
    variable_set('civicrm_admin_theme', 'pushbutton');

// Update the menu router information.
   menu_rebuild();
}

function ozmodemo_civicrm_form_alter(&$form, $form_state, $form_id) {
    if ($form_id == 'install_configure') {
        $form['#submit'][] = 'ozmodemo_civicrm_form_submit';
    }
}

function ozmodemo_civicrm_form_submit($form, &$form_state) {
    $dump_file = dirname(__FILE__) . CIVI_DEMO_PROFILE_DUMP;
    $success = import_civi_demo_dump($dump_file, FALSE);
    if (!$success) {
        return;
    }

    variable_set('site_name', $form_state['values']['site_name']);
    variable_set('site_mail', $form_state['values']['site_mail']);
    variable_set('date_default_timezone', $form_state['values']['date_default_timezone']);
    variable_set('clean_url', $form_state['values']['clean_url']);
    variable_set('update_status_module', $form_state['values']['update_status_module']);
    variable_del('file_directory_temp'); 
    $name = $form_state['values']['account']['name'];
    $pass = $form_state['values']['account']['pass'];
    $mail = $form_state['values']['account']['mail'];
    db_query("UPDATE {users} SET name = '%s', pass = MD5('%s'), mail = '%s' WHERE uid = 1", $name, $pass, $mail);
    user_authenticate(array('name' => $name, 'pass' => $pass));
    drupal_goto('<front>');
}

function import_civi_demo_dump($filename, $demo=FALSE) {
    if (!file_exists($filename) || !($fp = fopen($filename, 'r'))) {
        drupal_set_message(t('Unable to open dump %filename.', array('%filename' => $filename)), 'error');
        return FALSE;
    }

    if (!$demo){
        foreach (civi_list_all_tables() as $table) {
            db_query("DROP TABLE %s", $table);
        }
    }

    $success = TRUE;
    $query = '';
    $new_line = TRUE;
    $my_uri = drush_get_option('my_uri', 'site');

    while (!feof($fp)) {
        $data = fgets($fp);
        if ($data === FALSE) {
            break;
        }
        if ($new_line && ($data == "\n" || !strncmp($data, '--', 2) || !strncmp($data, '#', 1))) {
            continue;
        }

        $data = preg_replace(CIVI_DEMO_HOME, $my_uri, $data);

        $query .= $data;
        $len = strlen($data);
        if ($data[$len - 1] == "\n") {
            if ($data[$len - 2] == ';') {
                if (!_db_query($query, FALSE)) {
                    $success = FALSE;
                }
                $query = '';
            }
            $new_line = TRUE;
        }
        else {
            $new_line = FALSE;
        }
    }
    fclose($fp);

    if (!$success) {
        drupal_set_message(t('An error occured when importing the file %filename.', array('%filename' => $filename)), 'error');
    }
    return $success;
}

function civi_list_all_tables() {
    global $db_prefix;
    $tables = array();
    if (is_array($db_prefix)) {
        $rx = '/^' . implode('|', array_filter($db_prefix)) . '/';
    }
    else if ($db_prefix != '') {
        $rx = '/^' . $db_prefix . '/';
    }

    switch ($GLOBALS['db_type']) {
        case 'mysql':
        case 'mysqli':
            $result = db_query("SHOW TABLES");
            break;
        case 'pgsql':
            $result = db_query("SELECT table_name FROM information_schema.tables WHERE table_schema = '%s'", 'public');
            break;
    }

    while ($table = db_fetch_array($result)) {
        $table = reset($table);
        if (is_array($db_prefix)) {
            if (preg_match($rx, $table, $matches)) {
                $table_prefix = $matches[0];
                $plain_table = substr($table, strlen($table_prefix));
                if ($db_prefix[$plain_table] == $table_prefix || $db_prefix['default'] == $table_prefix) {
                    $tables[] = $table;
                }
            }
        }
        else if ($db_prefix != '') {
            if (preg_match($rx, $table)) {
                $tables[] = $table;
            }
        }
        else {
            $tables[] = $table;
        }
    }
    return $tables;
}
