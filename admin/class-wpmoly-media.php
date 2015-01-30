<?php
/**
 * WPMovieLibrary Media Class extension.
 * 
 * Add and manage Movie Images and Posters
 *
 * @package   WPMovieLibrary
 * @author    Charlie MERLAND <charlie@caercam.org>
 * @license   GPL-3.0
 * @link      http://www.caercam.org/
 * @copyright 2014 CaerCam.org
 */

if ( ! class_exists( 'WPMOLY_Media' ) ) :

	class WPMOLY_Media extends WPMOLY_Module {

		/**
		 * Constructor
		 *
		 * @since    1.0
		 */
		public function __construct() {

			if ( ! is_admin() )
				return false;

			$this->register_hook_callbacks();
		}

		/**
		 * Register callbacks for actions and filters
		 * 
		 * @since    1.0
		 */
		public function register_hook_callbacks() {

			add_action( 'the_posts', array( $this, 'images_media_modal_query' ), 10, 2 );

			add_action( 'before_delete_post', __CLASS__ . '::delete_movies_attachments', 10, 1 );

			add_filter( 'wpmoly_check_for_existing_images', array( $this, 'check_for_existing_images' ), 10, 3 );
			add_filter( 'wpmoly_jsonify_movie_images', array( $this, 'jsonify_movie_images' ), 10, 3 );

			// Callbacks
			add_action( 'wp_ajax_wpmoly_load_images', __CLASS__ . '::load_images_callback' );
			add_action( 'wp_ajax_wpmoly_upload_image', __CLASS__ . '::upload_image_callback' );
			add_action( 'wp_ajax_wpmoly_set_featured', __CLASS__ . '::set_featured_image_callback' );
		}

		/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
		 *
		 *                             Callbacks
		 * 
		 * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

		/**
		 * Load a movie images.
		 *
		 * @since    2.2
		 */
		public static function load_images_callback() {

			wpmoly_check_ajax_referer( 'load-movie-images' );

			// Make sure data is sent...
			$data = ( isset( $_POST['data'] ) && '' != $_POST['data'] ? $_POST['data'] : null );
			if ( is_null( $data ) )
				wp_send_json_error( new WP_Error( -1, __( 'Data could not be processed correctly.', 'wpmovielibrary' ) ) );

			// ... and we have the requires IDs and type
			$post_id = ( isset( $data['post_id'] ) && '' != $data['post_id'] ? intval( $data['post_id'] ) : null );
			$type    = ( isset( $data['type'] )    && '' != $data['type']    ? esc_attr( $data['type'] )  : null );
			if ( is_null( $post_id ) || is_null( $type ) )
				wp_send_json_error( new WP_Error( -2, __( 'Invalid Post ID or Image type.', 'wpmovielibrary' ) ) );

			$images = self::get_movie_imported_attachments( $type, $post_id, $format = 'raw' );
			$images = array_map( 'wp_prepare_attachment_for_js', $images );
			$images = array_filter( $images );

			wp_send_json_success( $images );
		}

		/**
		 * Upload a movie image.
		 * 
		 * Extract params from $_POST values. Image URL and post ID are
		 * required, title is optional. If no title is submitted file's
		 * basename will be used as image name.
		 *
		 * @since    1.0
		 */
		public static function upload_image_callback() {

			wpmoly_check_ajax_referer( 'upload-movie-image' );

			// Make sure data is sent...
			$data = ( isset( $_POST['data'] ) && '' != $_POST['data'] ? $_POST['data'] : null );
			if ( is_null( $data ) )
				wp_send_json_error( new WP_Error( -1, __( 'Image data could not be processed correctly.', 'wpmovielibrary' ) ) );

			// ... and we have the requires IDs
			$post_id = ( isset( $data['post_id'] ) && '' != $data['post_id'] ? intval( $data['post_id'] ) : null );
			$tmdb_id = ( isset( $data['tmdb_id'] ) && '' != $data['tmdb_id'] ? intval( $data['tmdb_id'] ) : null );
			if ( is_null( $post_id ) || is_null( $tmdb_id ) )
				wp_send_json_error( new WP_Error( -2, __( 'Invalid Post ID or TMDb ID.', 'wpmovielibrary' ) ) );

			// ... and we have a file_path to use.
			if ( ! isset( $data['metadata']['file_path'] ) || empty( $data['metadata']['file_path'] ) )
				wp_send_json_error( new WP_Error( -3, __( 'Empty filename.', 'wpmovielibrary' ) ) );

			$file_path = esc_attr( $data['metadata']['file_path'] );

			$response = self::image_upload( $file_path, $post_id, $tmdb_id, $data['type'], $data );

			wp_send_json_success();
		}

		/**
		 * Upload an image and set it as featured image of the submitted
		 * post.
		 * 
		 * Extract params from $_POST values. Image URL and post ID are
		 * required, title is optional. If no title is submitted file's
		 * basename will be used as image name.
		 * 
		 * Return the uploaded image ID to updated featured image preview
		 * in editor.
		 *
		 * @since    1.0
		 */
		public static function set_featured_image_callback() {

			wpmoly_check_ajax_referer( 'set-movie-poster' );

			$image   = ( isset( $_POST['image'] )   && '' != $_POST['image']   ? $_POST['image']   : null );
			$post_id = ( isset( $_POST['post_id'] ) && '' != $_POST['post_id'] ? $_POST['post_id'] : null );
			$title   = ( isset( $_POST['title'] )   && '' != $_POST['title']   ? $_POST['title']   : null );
			$tmdb_id = ( isset( $_POST['tmdb_id'] ) && '' != $_POST['tmdb_id'] ? $_POST['tmdb_id'] : null );

			if ( 1 != wpmoly_o( 'poster-featured' ) )
				return new WP_Error( 'no_featured', __( 'Movie Posters as featured images option is deactivated. Update your settings to activate this.', 'wpmovielibrary' ) );

			if ( is_null( $image ) || is_null( $post_id ) )
				return new WP_Error( 'invalid', __( 'An error occured when trying to import image: invalid data or Post ID.', 'wpmovielibrary' ) );

			$response = self::set_image_as_featured( $image, $post_id, $tmdb_id, $title );
			wpmoly_ajax_response( $response );
		}


		/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
		 *
		 *                      Movie images Media Modal
		 * 
		 * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

		/**
		 * This is the hijack trick. Use the 'the_posts' filter hook to
		 * determine whether we're looking for movie images or regular
		 * posts. If the query has a 'tmdb_id' field, images wanted, we
		 * load them. If not, it's just a regular Post query, return the
		 * Posts.
		 * 
		 * @since    1.0
		 * 
		 * @param    array    $posts Posts concerned by the hijack, should be only one
		 * @param    array    $wp_query concerned WP_Query instance
		 * 
		 * @return   array    Posts return by the query if we're not looking for movie images
		 */
		public function images_media_modal_query( $posts, $wp_query ) {

			if ( ! isset( $wp_query->query['post_mime_type'] ) || ! in_array( $wp_query->query['post_mime_type'], array( 'backdrops', 'posters' ) ) )
				return $posts;

			$image_type = $wp_query->query['post_mime_type'];

			if ( ! isset( $wp_query->query['s'] ) || '' == $wp_query->query['s'] )
				return $posts;

			if ( empty( $posts ) && ! empty( $wp_query->query_vars['post__in'] ) ) {
				$post_id = intval( array_shift( $wp_query->query_vars['post__in'] ) );
				$posts = array( get_post( $post_id ) );
			}

			$tmdb_id  = intval( $wp_query->query['s'] );
			$paged    = intval( $wp_query->query['paged'] );
			$per_page = intval( $wp_query->query['posts_per_page'] );

			if ( 'backdrops' == $image_type )
				$images = $this->load_movie_images( $tmdb_id, $posts[0] );
			else if ( 'posters' == $image_type )
				$images = $this->load_movie_posters( $tmdb_id, $posts[0] );

			$images = array_slice( $images, ( ( $paged - 1 ) * $per_page ), $per_page );

			wp_send_json_success( $images );
		}

		/**
		 * Load the Movie Images and display a jsonified result.s
		 * 
		 * @since    1.0
		 * 
		 * @param    int      $tmdb_id Movie TMDb ID to fetch images
		 * @param    array    $post Related Movie Post
		 * 
		 * @return   array    Movie images
		 */
		public function load_movie_images( $tmdb_id, $post ) {

			$images = WPMOLY_TMDb::get_movie_images( $tmdb_id );
			$images = apply_filters( 'wpmoly_jsonify_movie_images', $images, $post, 'image' );

			return $images;
		}

		/**
		 * Load the Movie Images and display a jsonified result.s
		 * 
		 * @since    1.0
		 * 
		 * @param    int      $tmdb_id Movie TMDb ID to fetch images
		 * @param    array    $post Related Movie Post
		 * 
		 * @return   array    Movie posters
		 */
		public function load_movie_posters( $tmdb_id, $post ) {

			$posters = WPMOLY_TMDb::get_movie_posters( $tmdb_id );
			$posters = apply_filters( 'wpmoly_jsonify_movie_images', $posters, $post, 'poster' );

			return $posters;
		}


		/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
		 *
		 *                       Images & Posters
		 * 
		 * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

		/**
		 * Perform delete actions on movies' images and posters.
		 * 
		 * User can set through the Settings whether the imported images,
		 * posters or both should be deleted along with the movie's Post.
		 * 
		 * @since    1.0
		 * 
		 * @param    int    Post ID
		 * 
		 * @return   mixed    Attachment deletion status
		 */
		public static function delete_movies_attachments( $post_id ) {

			$post = get_post( $post_id );
			if ( ! $post || 'movie' != get_post_type( $post ) )
				return false;

			// Do nothing
			if ( ! wpmoly_o( 'images-delete' ) && ! wpmoly_o( 'posters-delete' ) )
				return false;

			// Delete posters only
			if ( ! wpmoly_o( 'images-delete' ) && wpmoly_o( 'posters-delete' ) )
				if ( has_post_thumbnail( $post_id ) )
					return wp_delete_attachment( get_post_thumbnail_id( $post_id ), $force_delete = true );

			// Delete images only
			$args = array(
				'post_parent' => $post_id,
				'post_type' => 'attachment'
			);

			if ( ! wpmoly_o( 'posters-delete' ) )
				if ( has_post_thumbnail( $post_id ) )
					$args['exclude'] = get_post_thumbnail_id( $post_id );

			$attached = get_children( $args );

			if ( empty( $attached ) )
				return false;

			foreach ( $attached as $a )
				if ( '' != get_post_meta( $a->ID, '_wpmoly_backdrop_related_tmdb_id', true ) ||
				     '' != get_post_meta( $a->ID, '_wpmoly_poster_related_tmdb_id', true ) )
					wp_delete_attachment( $a->ID, $force_delete = true );

			return $post_id;
		}

		/**
		 * Get related images for the current movie. Shortcut for the
		 * get_movie_imported_attachments() method.
		 * 
		 * @since    2.0
		 * 
		 * @param    int       $post_id Post ID to find related attachments
		 * @param    string    $format Output format, raw or filtered
		 * 
		 * @return   array    Images list
		 */
		public static function get_movie_imported_images( $post_id = null, $format = 'filtered' ) {

			return self::get_movie_imported_attachments( $type = 'image', $post_id, $format );
		}

		/**
		 * Get related posters for the current movie. Shortcut for the
		 * get_movie_imported_attachments() method.
		 * 
		 * @since    2.0
		 * 
		 * @param    int       $post_id Post ID to find related attachments
		 * @param    string    $format Output format, raw or filtered
		 * 
		 * @return   array    Posters list
		 */
		public static function get_movie_imported_posters( $post_id = null, $format = 'filtered' ) {

			return self::get_movie_imported_attachments( $type = 'poster', $post_id, $format );
		}

		/**
		 * Get all the imported images/posters related to current movie
		 * and format them to be showed in the Movie Edit page.
		 * 
		 * @since    1.0
		 * 
		 * @param    string    $type Attachment type, 'image' or 'poster'
		 * @param    int       $post_id Post ID to find related attachments
		 * @param    string    $format Output format, raw or filtered
		 * 
		 * @return   array    Attachment list
		 */
		public static function get_movie_imported_attachments( $type = 'image', $post_id = null, $format = 'filtered' ) {

			if ( is_null( $post_id ) )
				$post_id = get_the_ID();

			if ( 'movie' != get_post_type( $post_id ) )
				return false;

			if ( 'raw' != $format )
				$format = 'filtered';

			if ( 'poster' != $type )
				$type = 'image';

			$args = array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'meta_key'       => "_wpmoly_{$type}_related_tmdb_id",
				'posts_per_page' => -1,
				'post_parent'    => $post_id
			);

			$attachments = new WP_Query( $args );
			$images = array();

			if ( empty( $attachments->posts ) )
				return array();
			
			foreach ( $attachments->posts as $attachment ) {

				if ( 'raw' == $format ) {
					$images[] = $attachment;
				} else {
					$images[] = array(
						'id'     => $attachment->ID,
						/*'meta'   => wp_get_attachment_metadata( $attachment->ID ),
						'type'   => ( isset( $meta['sizes']['medium']['mime-type'] ) ? str_replace( 'image/', ' subtype-', $meta['sizes']['medium']['mime-type'] ) : '' ),
						'height' => ( isset( $meta['sizes']['medium']['height'] ) ? $meta['sizes']['medium']['height'] : 0 ),
						'width'  => ( isset( $meta['sizes']['medium']['width'] ) ? $meta['sizes']['medium']['width'] : 0 ),
						'format' => ( $width && $height ? ( $height > $width ? ' portrait' : ' landscape' ) : '' ),*/
						'image'  => wp_get_attachment_image_src( $attachment->ID, 'medium' ),
						'link'   => get_edit_post_link( $attachment->ID )
					);
				}
			}

			return $images;
		}

		/**
		 * Set the image as featured image.
		 * 
		 * @since    1.0
		 * 
		 * @param    string    $file The image file name to set as featured
		 * @param    int       $post_id The post ID the image is to be associated with
		 * @param    int       $tmdb_id The TMDb Movie ID the image is associated with
		 * @param    string    $title The related Movie title
		 * 
		 * @return   int|WP_Error Uploaded image ID if successfull, WP_Error if an error occured.
		 */
		public static function set_image_as_featured( $file, $post_id, $tmdb_id ) {

			$image = self::image_upload( $file, $post_id, $tmdb_id, 'poster' );
			return $image;
		}

		/**
		 * Media Sideload Image revisited
		 * This is basically an override function for WP media_sideload_image
		 * modified to return the uploaded attachment ID instead of HTML img
		 * tag.
		 * 
		 * @see http://codex.wordpress.org/Function_Reference/media_sideload_image
		 * 
		 * @since    1.0
		 * 
		 * @param    string    $file The filename of the image to download
		 * @param    int       $post_id The post ID the media is to be associated with
		 * @param    int       $tmdb_id The TMDb Movie ID the image is associated with
		 * @param    string    $image_type Optional. Image type, 'backdrop' or 'poster'
		 * @param    array     $data Optional. Image metadata
		 * 
		 * @return   string|WP_Error Populated HTML img tag on success
		 */
		private static function image_upload( $file, $post_id, $tmdb_id, $image_type = 'backdrop', $data = null ) {

			if ( empty( $file ) )
				return new WP_Error( 'invalid', __( 'The image you\'re trying to upload is empty.', 'wpmovielibrary' ) );

			$image_type = ( 'poster' == $image_type ? 'poster' : 'backdrop' );

			if ( 'poster' == $image_type ) {
				$size = wpmoly_o( 'poster-size' );
			} else {
				$size = wpmoly_o( 'images-size' );
			}

			if ( is_array( $file ) ) {
				$data = $file;
				$file = WPMOLY_TMDb::get_image_url( $file['file_path'], $image_type, $size );
				$image = $file;
			}
			else {
				$image = $file;
				$file = WPMOLY_TMDb::get_image_url( $file, $image_type, $size );
			}

			$image = substr( $image, 1 );

			$existing = self::check_for_existing_images( $tmdb_id, $image_type, $image );
			if ( false !== $existing )
				return new WP_Error( 'invalid', __( 'The image you\'re trying to upload already exists.', 'wpmovielibrary' ) );

			$tmp = download_url( $file );

			preg_match( '/[^\?]+\.(jpe?g|jpe|gif|png)\b/i', $file, $matches );
			$file_array['name'] = basename( $matches[0] );
			$file_array['tmp_name'] = $tmp;

			if ( is_wp_error( $tmp ) ) {
				@unlink( $file_array['tmp_name'] );
				$file_array['tmp_name'] = '';
			}

			$id = media_handle_sideload( $file_array, $post_id );
			if ( is_wp_error( $id ) ) {
				@unlink( $file_array['tmp_name'] );
				return new WP_Error( $id->get_error_code(), $id->get_error_message() );
			}

			self::update_attachment_meta( $id, $post_id, $image_type );

			return $id;
		}

		/**
		 * Update movie images/posters title, description, caption...
		 * 
		 * @since    2.0
		 * 
		 * @param    int       $attachment_id Current image Post ID
		 * @param    int       $post_id Related Post ID
		 * @param    string    $image_type Image type, 'backdrop' or 'poster'
		 * @param    array     $data Image data. Deprecated since 2.2
		 */
		private static function update_attachment_meta( $attachment_id, $post_id, $image_type, $data = null ) {

			if ( ! get_post( $attachment_id ) || ! get_post( $post_id ) )
				return false;

			if ( 'poster' != $image_type )
				$image_type = 'image';

			$filtered = self::filter_attachment_meta( $post_id, $image_type );
			extract( $filtered );

			$attachment = array(
				'ID'           => $attachment_id,
				'post_title'   => $_title,
				'post_content' => $_description,
				'post_excerpt' => $_description
			);

			update_post_meta( $attachment_id, '_wpmoly_' . $image_type . '_related_tmdb_id', $tmdb_id );
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $_title );

			$update = wp_update_post( $attachment );
		}

		/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
		 *
		 *                            Utils
		 * 
		 * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

		public static function filter_attachment_meta( $post_id, $image_type ) {

			if ( $image_type != 'poster' )
				$image_type = 'image';

			$meta = wpmoly_get_movie_meta( $post_id );
			$meta = array(
				'tmdb_id'        => $meta['tmdb_id'],
				'title'          => $meta['title'],
				'production'     => $meta['production_companies'],
				'director'       => $meta['director'],
				'originaltitle'  => $meta['original_title'],
				'year'           => $meta['release_date']
			);

			$meta['production'] = explode( ',', $meta['production'] );
			$meta['production'] = trim( array_shift( $meta['production'] ) );
			$meta['year']       = apply_filters( 'wpmoly_format_movie_date',  $meta['year'], 'Y' );

			$_description = wpmoly_o( "{$image_type}-description", '' );
			$_title       = wpmoly_o( "{$image_type}-title", '' );

			foreach ( $meta as $find => $replace ) {
				if ( ! empty( $replace ) ) {
					$_description = str_replace( '{' . $find . '}', $replace, $_description );
					$_title       = str_replace( '{' . $find . '}', $replace, $_title );
				}
			}

			$meta = compact( '_description', '_title' );

			return $meta;
		}

		/**
		 * Check for previously imported images to avoid duplicates.
		 * 
		 * If any attachment has one or more postmeta matching the current
		 * Movie's TMDb ID, we don't want to import the image again. If
		 * we're testing a poster, make sure it isn't there already, in
		 * which case it should have a metafield storing its original
		 * TMDb file name. If we're testing an image we make sure its
		 * file name doesn't match a previously imported image.
		 * 
		 * @since    1.0
		 * 
		 * @param    string    $tmdb_id    The Movie's TMDb ID.
		 * @param    string    $image_type Optional. Which type of image we're dealing with, simple image or poster.
		 * @param    string    $image Image name to compare
		 * 
		 * @return   mixed     Return the last found image's ID if any, false if no matching image was found.
		 */
		public function check_for_existing_images( $tmdb_id, $image_type = 'image', $image = null ) {

			if ( ! isset( $tmdb_id ) || '' == $tmdb_id )
				return false;

			if ( 'poster' != $image_type )
				$image_type = 'image';

			$check = get_posts(
				array(
					'post_type' => 'attachment',
					'meta_query' => array(
						array(
							'key'     => '_wpmoly_' . $image_type . '_related_tmdb_id',
							'value'   => $tmdb_id,
						)
					)
				)
			);

			if ( empty( $check ) )
				return false;

			foreach ( $check as $c ) {
				$post_name = strtolower( $c->post_name );
				$image     = strtolower( str_replace( '.jpg', '', $image ) );
				if ( $post_name == $image )
					return true;
			}

			return false;
		}

		/**
		 * Prepare movie images to Media Modal query creating an array
		 * matching wp_prepare_attachment_for_js() filtered attachments.
		 * 
		 * This is used by WPMOLY_Edit_Movies::load_images_callback() to
		 * show movie images in Media Modal instead of regular images,
		 * which needs to fed JSONified Attachments to the AJAX callback
		 * to append to the modal.
		 * 
		 * @since    1.0
		 * 
		 * @param    array     $images The images to prepare
		 * @param    object    $post Related Movie Posts
		 * @param    string    $image_type Which type of image we're dealing with, simple image or poster.
		 * 
		 * @return   array    The prepared images
		 */
		public function jsonify_movie_images( $images, $post, $image_type ) {

			$image_type = ( 'poster' == $image_type ? 'poster' : 'backdrop' );

			$base_url = WPMOLY_TMDb::get_image_url( null, $image_type );
			$json_images = array();
			$i = 0;

			foreach ( $images as $image ) {

				$i++;
				$_date = time();
				$_title = $post->post_title;
				$_orientation = $image['aspect_ratio'] > 1 ? 'landscape' : 'portrait';

				$delete_nonce = current_user_can( 'delete_post', $post->ID ) ? wp_create_nonce( 'delete-post_' . $post->ID ) : false;
				$edit_nonce = current_user_can( 'edit_post', $post->ID ) ? wp_create_nonce( 'update-post_' . $post->ID ) : false;
				$image_editor_none = current_user_can( 'edit_post', $post->ID ) ? wp_create_nonce( 'image_editor-' . $post->ID ) : false;

				$filtered_meta = self::filter_attachment_meta( $post->ID, $image_type );
				extract( $filtered_meta );

				$json_images[] = array(
					'id' 		=> $post->ID . '_' . $i,
					'title' 	=> $_title,
					'filename' 	=> substr( $image['file_path'], 1 ),
					'url' 		=> $base_url['original'] . $image['file_path'],
					'link' 		=> get_permalink( $post->ID ),
					'alt'		=> $_description,
					'author' 	=> "" . get_current_user_id(),
					'description' 	=> $_description,
					'caption' 	=> $_description,
					'name' 		=> substr( $image['file_path'], 1, -4 ),
					'status' 	=> "inherit",
					'uploadedTo' 	=> $post->ID,
					'date' 		=> $_date * 1000,
					'modified' 	=> $_date * 1000,
					'menuOrder' 	=> 0,
					'mime' 		=> "image/jpeg",
					'type' 		=> "image",
					'subtype' 	=> "jpeg",
					'icon' 		=> includes_url( 'images/crystal/default.png' ),
					'dateFormatted' => date( get_option( 'date_format' ), $_date ),
					'nonces' 	=> array(
						'delete' 	=> $delete_nonce,
						'update' 	=> $edit_nonce,
						'edit' 		=> $image_editor_none
					),
					'editLink' 	=> "#",
					'sizes' => array(
						'thumbnail' => array(
							'height' => 154,
							'orientation' => $_orientation,
							'url' => $base_url['small'] . $image['file_path'],
							'width' => 154,
						),
						'medium' => array(
							'height' => floor( 300 / $image['aspect_ratio'] ),
							'orientation' => $_orientation,
							// Modal thumbs are actually Medium size, so we set a small one
							// for posters, a really small one
							'url' => ( 'poster' == $image_type ? $base_url['x-small'] : $base_url['small'] ) . $image['file_path'],
							'width' => 300,
						),
						'large' => array(
							'height' => floor( 500 / $image['aspect_ratio'] ),
							'orientation' => $_orientation,
							'url' => $base_url['full'] . $image['file_path'],
							'width' => 500,
						),
						'full' => array(
							'height' => $image['height'],
							'orientation' => $_orientation,
							'url' => $base_url['original'] . $image['file_path'],
							'width' => $image['width'],
						),
					),
					'height' 	=> $image['height'],
					'width' 	=> $image['width'],
					'orientation' 	=> $_orientation,
					'compat' 	=> array( 'item' => '', 'meta' => '' ),
					'metadata' 	=> $image
				);
			}

			return $json_images;
		}

		/**
		 * Prepares sites to use the plugin during single or network-wide activation
		 *
		 * @since    1.0
		 *
		 * @param    bool    $network_wide
		 */
		public function activate( $network_wide ) {}

		/**
		 * Rolls back activation procedures when de-activating the plugin
		 *
		 * @since    1.0
		 */
		public function deactivate() {}

		/**
		 * Initializes variables
		 *
		 * @since    1.0
		 */
		public function init() {}

	}

endif;