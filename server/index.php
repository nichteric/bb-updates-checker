<?php

/*
NOTES:
  Place this file somewhere on your FTP server.
  Create two sub-directories: 'themes' and 'plugins'.
  Set the correct value of 'BB_UPDATE_CHECKER_URL' in 'bb-updates-checker.php'.
*/

define('DEFAULT_MIN_PHP_VERSION', '7.0');
define('DEFAULT_MIN_WP_VERSION', '6.0');
define('DEFAULT_TESTED_WP_VERSION', DEFAULT_MIN_WP_VERSION);


function get_plugin_data($slug)
{
  if (!file_exists("{$slug}.zip")) {
    die();
  }

  $zip = new ZipArchive();
  $res = $zip->open("{$slug}.zip");
  $file_data = $zip->getFromName("{$slug}/{$slug}.php", 2048);

  if (false === $file_data) {
    $file_data = '';
  }
  $file_data = str_replace("\r", "\n", $file_data);
  $all_headers = [
    'name' => 'Plugin Name',
    'version' => 'Version',
    'description' => 'Description',
    'author' => 'Author',
    'requires' => 'Requires at least',
    'requires_php' => 'Requires PHP',
    'author_profile' => 'Author URI',
  ];

  $data = [];
  foreach ($all_headers as $field => $regex) {
    if (preg_match('/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote($regex, '/') . ':(.*)$/mi', $file_data, $match) && $match[1]) {
      $data[$field] = trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $match[1]));
    } else {
      $data[$field] = '';
    }
  }

  $data['slug'] = $slug;
  $update_url = dirname('http' . (!empty($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI']);
  $data['download_url'] = "{$update_url}/plugins/{$slug}.zip";
  $data['sections']['description'] = $data['description'];
  $data['sections']['installation'] = '';
  $data['sections']['changelog'] = '';
  unset($data['description']);
  $data['last_updated'] = @date('Y-m-d H:i:s', filemtime("{$slug}.zip"));

  if ($data['requires'] == '') {
    $data['requires'] = DEFAULT_MIN_WP_VERSION;
  }
  if ($data['requires_php'] == '') {
    $data['requires_php'] = DEFAULT_MIN_PHP_VERSION;
  }
  if (($data['tested'] ?? '') == '') {
    $data['tested'] = DEFAULT_TESTED_WP_VERSION;
  }
  return $data;
}

function do_plugin($slug)
{
  chdir('plugins');
  if (!$slug) {
    $data = [];
    foreach (glob("*.zip") as $file) {
      $slug = basename($file, '.zip');
      $d = get_plugin_data($slug);
      unset($d['sections']);
      unset($d['author']);
      unset($d['download_url']);
      unset($d['author_profile']);
      unset($d['tested']);
      unset($d['requires_php']);
      unset($d['requires']);
      $data[] = $d;
    }
    header('Content-Type: text/html');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo '<html><body>';
    echo '<pre>';
    print_r($data);
    echo '</pre>';
    echo '</body></html>';
  } else {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $slug = $_GET['slug'];
    $data = get_plugin_data($slug);
    print json_encode($data);
  }
}

function get_theme_data($slug)
{
  if (!file_exists("{$slug}.zip")) {
    die();
  }

  $zip = new ZipArchive();
  $res = $zip->open("{$slug}.zip");
  $file_data = $zip->getFromName("{$slug}/style.css", 2048);

  if (false === $file_data) {
    $file_data = '';
  }
  $file_data = str_replace("\r", "\n", $file_data);
  $all_headers = [
    'name' => 'Theme Name',
    'version' => 'Version',
    'description' => 'Description',
    'author' => 'Author',
    'requires' => 'Requires at least',
    'requires_php' => 'Requires PHP',
    'author_profile' => 'Author URI',
  ];

  $data = [];
  foreach ($all_headers as $field => $regex) {
    if (preg_match('/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote($regex, '/') . ':(.*)$/mi', $file_data, $match) && $match[1]) {
      $data[$field] = trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $match[1]));
    } else {
      $data[$field] = '';
    }
  }

  $data['slug'] = $slug;
  $update_url = dirname('http' . (!empty($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI']);
  $data['download_url'] = "{$update_url}/themes/{$slug}.zip";
  $data['sections']['description'] = $data['description'];
  $data['sections']['installation'] = '';
  $data['sections']['changelog'] = '';
  unset($data['description']);
  $data['last_updated'] = @date('Y-m-d H:i:s', filemtime("{$slug}.zip"));

  if ($data['requires'] == '') {
    $data['requires'] = DEFAULT_MIN_WP_VERSION;
  }
  if ($data['requires_php'] == '') {
    $data['requires_php'] = DEFAULT_MIN_PHP_VERSION;
  }
  if (($data['tested'] ?? '') == '') {
    $data['tested'] = DEFAULT_TESTED_WP_VERSION;
  }
  return $data;
}

function do_theme($slug)
{
  chdir('themes');
  if (!$slug) {
    $data = [];
    foreach (glob("*.zip") as $file) {
      $slug = basename($file, '.zip');
      $d = get_theme_data($slug);
      unset($d['sections']);
      unset($d['author']);
      unset($d['download_url']);
      unset($d['author_profile']);
      unset($d['tested']);
      unset($d['requires_php']);
      unset($d['requires']);
      $data[] = $d;
    }
    header('Content-Type: text/html');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo '<html><body>';
    echo '<pre>';
    print_r($data);
    echo '</pre>';
    echo '</body></html>';
  } else {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $slug = $_GET['slug'];
    $data = get_theme_data($slug);
    print json_encode($data);
  }
}


$type = $_GET['type'] ?? null;
$slug = $_GET['slug'] ?? null;

// Create missing directories
foreach (['themes', 'plugins'] as $dir) {
  if (!file_exists($dir) || !is_dir($dir)) {
    mkdir($dir);
  }
}

if ($type === 'theme') {
  do_theme($slug);
} elseif ($type === 'plugin') {
  do_plugin($slug);
} else {
  echo 'nope';
}

die();
