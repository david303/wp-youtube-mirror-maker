<?php
/*
Plugin Name: Mirror Maker
Plugin URI:  http://www.d23.pl/
Description: Plugin for downloading hosted videos and mirroring them as media library content in Wordpress
Version:     0.1.0
Author:      David Kolodziej
Author URI:  http://www.d23.pl/
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

class MirrorMaker
{
  public function __construct()
  {
    add_action( 'admin_menu', array($this, 'mirrorMakerMenu') );

    if (isset($_GET['error'])) {
      add_action( 'admin_notices', array($this, 'mirrorMakerPrintError') );
    }

    // attach form handler method
    add_action('admin_post_youtube_mirror', array($this, 'mirrorMakerHandleFormAction') ); // If the user is logged in
    add_action('admin_post_nopriv_youtube_mirror', array($this, 'mirrorMakerHandleFormAction') ); // If the user in not logged in

  }

  /**
   * Attach to Wordpress admin media sub-menu
   */
  public function mirrorMakerMenu()
  {
    add_media_page( 'Mirror Maker Options', 'Mirror Maker', 'manage_options', 'mirror-maker', array($this, 'mirrorMakerOptions') );
  }

  /**
   * Generate html view code for plugin page
   */
  function mirrorMakerOptions() {
    if ( !current_user_can( 'manage_options' ) )  {
      wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }
    echo '
      <div class="wrap">
      <h2>Mirror Maker Plugin</h2>

      <p>Mirror will be uploaded to the media library using best available quality.</p>
      <p style="font-weight: bolder">Current version only supports Youtube.</p>

        <form action="' . esc_url( admin_url("admin-post.php") ) . ' " method="post">
          <input type="hidden" name="action" value="youtube_mirror" />
          <label>Youtube video URL: <input type="text" name="youtube_url"/></label>
          <br/>
          <br/>
          <input type="submit" value="Add to media library"/>
        </form>
        <br/>
      </div>
    ';
  }

  /**
   * Selects and prints error message based on $_GET['error'] parameter
   */
  public function mirrorMakerPrintError() {
    $class = 'notice notice-error';

    switch($_GET['error']) {
      case 'upload':
        $errorMessage = 'Error: Upload error, check Wordpress upload directory';
        break;
      case 'download':
        $errorMessage = 'Error: Download error, video is copy protected';
        break;
      case 'url':
        $errorMessage = 'Error: Url is not correct Youtube format';
        break;
      case 'attachment':
        $errorMessage = 'Error: Unable to insert video file into Wordpress media library';
        break;
      default:
        $errorMessage = 'Error';
    }
    $message = __( $errorMessage, 'youtube-mirror-maker' );

    printf( '<div class="%1$s is-dismissible"><p>%2$s</p></div>', $class, $message );
  }

  /**
   * Form handler action
   *
   */
  public function mirrorMakerHandleFormAction(){

    $url = $_POST['youtube_url'];
    $queryString = explode("?", $url);
    parse_str($queryString[1], $queryArray);

    if( !isset($queryArray["v"]) ) {
      wp_redirect( admin_url("upload.php?page=mirror-maker&error=url") ); exit;
    }

    // get video info
    $response = file_get_contents("https://www.youtube.com/get_video_info?video_id=" . $queryArray["v"]);
    $videoInfoArray = array();
    parse_str($response, $videoInfoArray);

    // get stream map
    $fmtStreamMapUrls = explode(",", $videoInfoArray['url_encoded_fmt_stream_map']);
    $streamUrlResults = array();
    foreach($fmtStreamMapUrls as $urlEncoded) {
      parse_str($urlEncoded, $streamUrlResults[]);
    }

    $selectedStream = $streamUrlResults[0];

    // set filePath and fileName
    $uploadDir = wp_upload_dir();
    $filePath = "{$uploadDir['basedir']}/{$videoInfoArray['title']}.mp4";

    // download first stream in array (highest quality) into filePath
    $handle = fopen($selectedStream['url']."&signature=".$selectedStream[0]['s'], 'r');
    if ($handle) {
      file_put_contents($filePath, $handle);
    } else {
      wp_redirect( admin_url("upload.php?page=mirror-maker&error=download") ); exit;
    }

    $this->addFileToMediaLibrary($filePath);
  }

  /**
   * Add file as Wordpress media content
   *
   * @param $filePath
   */
  protected function addFileToMediaLibrary($filePath)
  {
    $fileName = basename($filePath);

    // 0 = no parent
    $parentPostId = 0;

    // insert file into Wordpress library
    $upload_file = wp_upload_bits($fileName, null, file_get_contents($filePath));
    if (!$upload_file['error']) {
      $wp_filetype = wp_check_filetype($fileName, null );
      $attachment = array(
        'post_mime_type' => $wp_filetype['type'],
        'post_parent' => $parentPostId,
        'post_title' => preg_replace('/\.[^.]+$/', '', $fileName),
        'post_content' => '',
        'post_status' => 'inherit'
      );
      $attachment_id = wp_insert_attachment( $attachment, $upload_file['file'], $parentPostId );
      if (!is_wp_error($attachment_id)) {
        require_once(ABSPATH . "wp-admin" . '/includes/image.php');
        $attachment_data = wp_generate_attachment_metadata( $attachment_id, $upload_file['file'] );
        wp_update_attachment_metadata( $attachment_id,  $attachment_data );

        //redirect to media library list
        wp_redirect( admin_url("upload.php") ); exit;
      } else {
        wp_redirect( admin_url("upload.php?page=mirror-maker&error=attachment") ); exit;
      }
    } else {
      wp_redirect( admin_url("upload.php?page=mirror-maker&error=upload") ); exit;
    }
  }
}

$plugin = new MirrorMaker();