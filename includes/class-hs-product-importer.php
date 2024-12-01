<?php

class HS_Product_Importer {

    private static $api_url = 'https://rezasrugs.com/ws-api/products';
    private static $items_per_page = 50;
	private static $username;
	private static $password;
	private static $base_api_url = 'https://rezasrugs.com/ws-api/';

	// Function to generate the authorization header
	private static function get_auth_header() {
		// Fetch the API username and password from the options
		self::$username = get_option('pis_' . get_option('pis_environment') . '_username');
		self::$password = get_option('pis_' . get_option('pis_environment') . '_password');

		// Create a logger instance
		$logger = new HS_API_Logger();

		// Check if username and password are set
		if (empty(self::$username) || empty(self::$password)) {
			// Log a warning if username or password is missing
			$logger->log("Missing API credentials: " .
			             (empty(self::$username) ? "Username is missing." : "") .
			             (empty(self::$password) ? " Password is missing." : ""));
			return null; // Return null or handle error accordingly
		}

		return 'Basic ' . base64_encode(self::$username . ':' . self::$password);
	}

	// Function to generate the full API URL for a specific endpoint
	private static function get_api_url($endpoint, $params = []) {
		$url = self::$base_api_url . $endpoint;
		if (!empty($params)) {
			$url .= '?' . http_build_query($params);
		}
		return $url;
	}

    public static function import_products() {
	    $logger = new HS_API_Logger();
        $page_number = get_option('hs_current_page', 1);
        //$page_number = 1;
        $products    = [];
	    $logger->log("Starting Import " . self::$items_per_page . " products from page " . ($page_number - 1));
        $response = self::fetch_products($page_number);

        if( $response ){
            $products = $response['data'];
        }

        if (!empty($products)) {
			// Import each product
            foreach ($products as $product) {
                self::import_single_product($product);
            }

			// Import each product images
	        foreach ($products as $product) {
		        self::import_single_product_images($product);
	        }

            update_option('hs_current_page', ++$page_number);
        } else {
            // Reset page number to 1 when no more products
            update_option('hs_current_page', 1);
        }

        // Logging the import
        $logger->log("Imported " . self::$items_per_page . " products from page " . ($page_number - 1));
    }

    private static function fetch_products($page_number) {
	    $api_url = self::get_api_url('products', [
		    'lang' => 'en',
		    'currencyISOCode' => 'EUR',
		    'itemsPerPage' => self::$items_per_page,
		    'pageNumber' => $page_number
	    ]);

		// Perform the API request
	    $response = wp_remote_get($api_url, array(
		    'headers' => array(
			    'Authorization' => self::get_auth_header(),  // Reuse the auth header function
		    )
	    ));

        if (is_wp_error($response)) {
            $logger = new HS_API_Logger();
            $logger->log("API error: " . $response->get_error_message());
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }

    private static function fetch_category($category_uid) {
	    $logger = new HS_API_Logger();

	    // API endpoint for fetching the category
	    $api_url = self::get_api_url('category', ['lang' => 'en', 'categoryUid' => $category_uid]);

	    // Log category fetch start
	    $logger->log("Fetching category with UID: {$category_uid}");

	    // Perform the API call with basic authentication
	    $response = wp_remote_get($api_url, array(
		    'headers' => array(
			    'Authorization' => self::get_auth_header(),
		    ),
	    ));

        // Check for API errors
        if (is_wp_error($response)) {
            $logger->log("Category API error: " . $response->get_error_message());
            return [];
        }

        // Retrieve and decode the response body
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Log response data
        //$logger->log("Category response: " . print_r($data, true));

        return $data;
    }

    private static function fetch_assets($product_uid) {
	    // Construct the API URL using get_api_url()
	    $api_url = self::get_api_url('product-asset/' . $product_uid, [
		    'hideHtml' => 'true',
	    ]);

		// Log asset fetch start
	    $logger = new HS_API_Logger();
	    $logger->log("Fetching assets for product UID: {$product_uid}");

		// Perform the API call with basic authentication
	    $response = wp_remote_get($api_url, array(
		    'headers' => array(
			    'Authorization' => self::get_auth_header(), // Use the reusable get_auth_header() method
		    ),
	    ));

        // Check for API errors
        if (is_wp_error($response)) {
            $logger->log("Assets API error: " . $response->get_error_message());
            return [];
        }

        // Retrieve and decode the response body
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Log response data
        //$logger->log("Assets response: " . print_r($data, true));

        return $data;
    }

	// Import a single product
    private static function import_single_product($product) {
        $logger = new HS_API_Logger();

        // Log product import start
        $logger->log("Starting product import for SKU: {$product['productUId']}");

        // Import product into WooCommerce
        $product_id = '';
        $args = array(
            'post_type'   => 'product',
            'meta_query'  => array(
                array(
                    'key'   => '_sku',
                    'value' => $product['productUId'],
                ),
            ),
        );

        $query = new WP_Query($args);
        if ( $query->have_posts() ) {
            $product_id = $query->posts[0]->ID;

	        // Fetch and update the price for the product
	        self::fetch_and_update_price( $product['productUId'], $product_id );

			// Fetch and update the inventory for the product
	        self::fetch_and_update_inventory( $product['productUId'], $product_id );

	        // Retrieve the last updated timestamp stored in post meta
	        $stored_last_updated = get_post_meta($product_id, 'last_updated', true);
	        // Check if the new lastUpdated is greater than the stored one
	        if ($stored_last_updated && $product['lastUpdated'] <= $stored_last_updated) {
		        //$logger->log("Product not updated for SKU: {$product['productUId']}. Last updated is not greater.");
		        //return; // Exit if the product does not need to be updated
	        }
        }
	    $category_data = array();

        // Check if the product exists, else create a new one
        if (!$product_id) {
	        // Generate the slug using the product name and product UID
	        $slug = sanitize_title($product['name'] . '-' . $product['productUId']);

            $product_id = wp_insert_post([
                'post_title'   => $product['name'],
                'post_content' => $product['longdesctext'],
                'post_status'  => 'publish',
                'post_type'    => 'product',
                'post_name'    => $slug, // Set the slug
            ]);

            // Log product creation
            $logger->log("Product created with ID: {$product_id} for SKU: {$product['productUId']}");

	        // Update the product if it does not exist
	        // Fetch and update the price for the product
	        self::fetch_and_update_price( $product['productUId'], $product_id );

	        // Fetch and update the inventory for the product
	        self::fetch_and_update_inventory( $product['productUId'], $product_id );
	        // Fetch and store category
	        if ( $product['categories'] ) {
		        $category_data = self::fetch_category( $product['categories'] );
		        if ( $category_data ) {
			        self::set_product_category( $product_id, $category_data );
		        }
	        }
        } else {
            // Update the product if it exists
            wp_update_post([
                'ID'          => $product_id,
                'post_title'   => $product['name'],
                'post_content' => $product['longdesctext'],
            ]);

            // Log product update
            $logger->log("Product updated with ID: {$product_id} for SKU: {$product['productUId']}");

	        // Fetch and store category
	        if ( $product['categories'] ) {
		        $category_data = self::fetch_category( $product['categories'] );
	        }
        }

	    // Example usage in your product creation function
	    self::add_product_size_attribute( $product_id, $product['length'], $product['width'] );
	    self::add_product_attribute( $product_id, 'color', $product['color'] );
	    self::add_product_attribute( $product_id, 'length', $product['length'] );
	    self::add_product_attribute( $product_id, 'width', $product['width'] );

        // Update custom fields //
	    update_post_meta($product_id, '_manage_stock', 'yes' );
        update_post_meta($product_id, '_stock', $product['inventory']);
        update_post_meta($product_id, '_sku', $product['productUId']);
        update_post_meta($product_id, 'product_origin', $product['origin']);
        update_post_meta($product_id, 'product_manufacturing', $product['manufacturing']);
        update_post_meta($product_id, 'product_pile', $product['pile']);
        update_post_meta($product_id, 'product_warp', $product['warp']);
        update_post_meta($product_id, 'product_condition', $product['condition']);
        update_post_meta($product_id, 'product_age', $product['age']);
        update_post_meta($product_id, 'product_shape', $product['shape']);
        update_post_meta($product_id, 'product_sqm', $product['sqm']);
        update_post_meta($product_id, 'product_length', $product['length']);
        update_post_meta($product_id, 'product_width', $product['width']);
        update_post_meta($product_id, 'product_design', $product['design']);
        update_post_meta($product_id, 'product_color', $product['color']);
        update_post_meta($product_id, 'product_colorsString', $product['colorsString']);
        update_post_meta($product_id, 'product_knotDensity', $product['knotDensity']);
        update_post_meta($product_id, 'product_description', $product['description']);
        update_post_meta($product_id, 'product_points', $product['points']);
        update_post_meta($product_id, 'product_backing', $product['backing']);
        update_post_meta($product_id, 'product_kg', $product['kg']);
        update_post_meta($product_id, 'product_knotDensityCM', $product['knotDensityCM']);
	    update_post_meta($product_id, 'product_colorCode', $product['colorCode']);

	    // Generate the meta data table and append it to post content
	    $table_html = self::generate_product_meta_table($product, $category_data);

		// Append the table to the existing post content
	    $post_content = get_post_field('post_content', $product_id);
	    $post_content .= $table_html;

		// Update the post content with the new table
	    wp_update_post([
		    'ID' => $product_id,
		    'post_content' => $post_content,
	    ]);

        // Log product meta update
       // $logger->log("Product meta updated for product ID: {$product_id}");
    }

	// Import product images
	private static function import_single_product_images($product) {
        $logger = new HS_API_Logger();

        // Log product import start
        //$logger->log("Starting product images import for SKU: {$product['productUId']}");

        $product_id = '';
        $args = array(
            'post_type'   => 'product',
            'meta_query'  => array(
                array(
                    'key'   => '_sku',
                    'value' => $product['productUId'],
                ),
            ),
        );

        $query = new WP_Query($args);
        if ( $query->have_posts() ) {
            $product_id = $query->posts[0]->ID;

	        // Retrieve the last updated timestamp stored in post meta
	        $stored_last_updated = get_post_meta($product_id, 'last_updated', true);
	        // Check if the new lastUpdated is greater than the stored one
	        if ($stored_last_updated && $product['lastUpdated'] <= $stored_last_updated) {
		        $logger->log("Product not updated for SKU: {$product['productUId']}. Last updated is not greater.");
		        return; // Exit if the product does not need to be updated
	        }

	        // Check if the product exists, else create a new one
	        if ( $product_id ) {
		        // Update the product if it exists
		        $assets_data = self::fetch_assets($product['productUId']);
		        if ($assets_data) {
			        self::set_product_images($product_id, $assets_data);
		        }

		        // Store the last updated timestamp
		        update_post_meta($product_id, 'last_updated', $product['lastUpdated']);
	        }
        }
    }

    private static function set_product_category($product_id, $category_data) {
        // Check if the category exists in WooCommerce
        $category_name = $category_data['name'];
        $category_id = get_term_by('name', $category_name, 'product_cat');

        if (!$category_id) {
            // Category doesn't exist, create a new one
            $category_id = wp_insert_term($category_name, 'product_cat', array(
                'description' => $category_data['displayName']
            ));
            if (is_wp_error($category_id)) {
                $logger = new HS_API_Logger();
                $logger->log("Error creating category: " . $category_id->get_error_message());
                return;
            }
            $category_id = $category_id['term_id'];
        } else {
            $category_id = $category_id->term_id;
        }

        // Attach category to product
        wp_set_object_terms($product_id, $category_id, 'product_cat');
        $logger = new HS_API_Logger();
        //$logger->log("Category '{$category_name}' set for product ID: {$product_id}");
    }

	private static function add_product_size_attribute($product_id, $length, $width) {
		// Check if both 'length' and 'width' fields are not empty
		if (!empty($length) && !empty($width)) {
			// Combine 'length' and 'width' into the 'size' attribute
			$size = $length . ' x ' . $width;

			// Define the attribute name
			$attribute_name = 'size';

			// Check if the attribute already exists in WooCommerce
			if (!taxonomy_exists('pa_' . $attribute_name)) {
				// If the attribute does not exist, create it
				wc_create_attribute([
					'name'         => ucfirst($attribute_name), // Capitalize the attribute name
					'slug'         => 'pa_' . $attribute_name,
					'type'         => 'select',
					'order_by'     => 'menu_order',
					'has_archives' => false,
				]);
			}

			// Register the attribute if not registered
			register_taxonomy('pa_' . $attribute_name, 'product', [
				'hierarchical' => true,
				'labels'       => [
					'name' => ucfirst($attribute_name),
				],
				'show_ui'      => true,
				'query_var'    => true,
			]);

			// Assign the attribute value to the product
			$product_attributes = get_post_meta($product_id, '_product_attributes', true);

			if (empty($product_attributes)) {
				$product_attributes = [];
			}

			$product_attributes['pa_' . $attribute_name] = [
				'name'         => 'pa_' . $attribute_name,
				'value'        => $size,
				'is_visible'   => 1,
				'is_variation' => 0,
				'is_taxonomy'  => 1,
			];

			// Update the product attribute
			update_post_meta($product_id, '_product_attributes', $product_attributes);

			// Assign the term value to the product
			wp_set_object_terms($product_id, $size, 'pa_' . $attribute_name, true);
		}
	}

	private static function add_product_attribute($product_id, $attr_name, $attr_value) {
		$logger = new HS_API_Logger();
		try {
			if (!empty($attr_value)) {
				// Sanitize the attribute name and slug
				$attribute_name = ucfirst($attr_name);
				$attribute_slug = sanitize_title($attr_name);

				// Check if the attribute already exists in WooCommerce
				if (!taxonomy_exists('pa_' . $attribute_slug)) {
					// Create the attribute
					wc_create_attribute([
						'name'         => $attribute_name,
						'slug'         => 'pa_' . $attribute_slug,
						'type'         => 'select',
						'order_by'     => 'menu_order',
						'has_archives' => false,
					]);
				}

				// Ensure the term exists
				$term = term_exists($attr_value, 'pa_' . $attribute_slug);
				if (!$term) {
					wp_insert_term($attr_value, 'pa_' . $attribute_slug);
				}

				// Assign the term value to the product
				wp_set_object_terms($product_id, $attr_value, 'pa_' . $attribute_slug, true);

				// Assign the attribute to the product
				$product_attributes = get_post_meta($product_id, '_product_attributes', true) ?: [];
				$product_attributes['pa_' . $attribute_slug] = [
					'name'         => 'pa_' . $attribute_slug,
					'value'        => $attr_value,
					'is_visible'   => 1,
					'is_variation' => 0,
					'is_taxonomy'  => 1,
				];

				update_post_meta($product_id, '_product_attributes', $product_attributes);

				//$logger->log("Attribute added: {$attribute_name} - {$attr_value} for Product ID: {$product_id}");
				return true;
			}
		} catch (Exception $e) {
			$logger->log("Error adding attribute for Product ID: {$product_id} - " . $e->getMessage());
			return false;
		}
	}

	private static function set_product_images($product_id, $assets_data) {
        $image_base_url = 'https://rezasrugs.com';

        // Set featured image
        $featured_image_url = $image_base_url . $assets_data['image'];
        $featured_image_id = self::import_image($featured_image_url, $product_id, true);

        // Set gallery images
        $gallery_image_urls = array_map(function($image) use ($image_base_url) {
            return $image_base_url . $image;
        }, $assets_data['imageGallery']);

        $gallery_image_ids = [];
        foreach ($gallery_image_urls as $gallery_image_url) {
            $gallery_image_id = self::import_image($gallery_image_url, $product_id, false);
            if ($gallery_image_id) {
                $gallery_image_ids[] = $gallery_image_id;
            }
        }

        // Attach gallery images
        if (!empty($gallery_image_ids)) {
            update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_image_ids));
        }

        $logger = new HS_API_Logger();
        $logger->log("Images set for product ID: {$product_id}");
    }

	private static function import_image($image_url, $product_id, $is_featured = false) {
		$logger = new HS_API_Logger();

		if ( empty( $image_url ) || $image_url === 'https://rezasrugs.com' || $image_url == '' ) {
			$logger->log( "Image URL is empty for product ID: {$product_id}" );
			return false;
		}

		// Concatenate the base URL if the image URL is incomplete
		if (strpos($image_url, 'https://rezasrugs.com') === false) {
			$image_url = 'https://rezasrugs.com' . $image_url;
		}

		// Step 1: Extract the file name from the URL
		$image_name = basename(parse_url($image_url, PHP_URL_PATH));

		// Step 2: Sanitize the file name to avoid any issues
		$image_name = sanitize_file_name($image_name);

		// Step 3: Ensure it has a proper extension
		if (!preg_match('/\.(jpg|jpeg|png|gif)$/i', $image_name)) {
			$image_name .= '.jpg'; // Default to .jpg if no extension is present
		}

		// Step 4: Check if the image already exists in the media library
		$attachment_id = self::get_image_by_name($image_name);
		if ($attachment_id) {
			$logger->log("Image already exists in media library: $image_url");
			return $attachment_id;
		}

		// Import image and attach to product
		$upload_dir = wp_upload_dir();
		$image_data = @file_get_contents($image_url);

		if ($image_data === false) {
			$logger->log("Failed to download image: $image_url");
			return false;
		}

		$file = $upload_dir['path'] . '/' . $image_name;

		// Save the image file
		if (file_put_contents($file, $image_data)) {
			$wp_filetype = wp_check_filetype($image_name, null);
			$attachment = array(
				'post_mime_type' => $wp_filetype['type'],
				'post_title' => sanitize_file_name($image_name),
				'post_content' => '',
				'post_status' => 'inherit'
			);

			$attachment_id = wp_insert_attachment($attachment, $file, $product_id);

			// Handle image processing and attachment metadata
			require_once(ABSPATH . 'wp-admin/includes/image.php');
			$attach_data = wp_generate_attachment_metadata($attachment_id, $file);
			wp_update_attachment_metadata($attachment_id, $attach_data);

			// Set featured image if specified
			if ($is_featured) {
				set_post_thumbnail($product_id, $attachment_id);
			}

			$logger->log("Image successfully imported: $image_url");

			return $attachment_id;
		} else {
			$logger->log("Failed to save image to $file");
			return false;
		}
	}

	// Function to check if image already exists by file name
	private static function get_image_by_name($image_name) {
		global $wpdb;

		// Prepare the scaled image name
		$scaled_name = pathinfo($image_name, PATHINFO_FILENAME) . '-scaled.' . pathinfo($image_name, PATHINFO_EXTENSION);

		// Check for existing attachment with the same file name or any scaled version
		$attachment_id = $wpdb->get_var($wpdb->prepare(
			"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND (meta_value LIKE %s OR meta_value LIKE %s)",
			'%' . $image_name, '%' . $scaled_name
		));

		return $attachment_id ? $attachment_id : false;
	}

	// Fetch and update price
	public static function fetch_and_update_price($product_uid, $product_id) {
		$logger = new HS_API_Logger();

		// API endpoint for fetching the product price
		$api_url = self::get_api_url('product-price/' . $product_uid, ['currencyISOCode' => 'EUR']);

		// Fetch the price data from the API
		$response = wp_remote_get($api_url, array(
			'headers' => array(
				'Authorization' => self::get_auth_header(),
			),
		));

		// Check for a valid response
		if (is_wp_error($response)) {
			$logger->log("Failed to fetch price for product UID: {$product_uid}");
			return false;
		}

		$body = wp_remote_retrieve_body($response);
		$price_data = json_decode($body, true);


		if (isset($price_data['recommendedRetailPrice']) && isset($price_data['wholesalePrice'])) {
			// Log successful price retrieval
			$logger->log("Successfully fetched price data for product UID: {$product_uid}");

			// Update product price meta
			update_post_meta($product_id, '_regular_price', $price_data['recommendedRetailPrice']);
			update_post_meta($product_id, '_price', $price_data['recommendedRetailPrice']); // Set as current price
			update_post_meta($product_id, '_wholesale_price', $price_data['wholesalePrice']);

			// Log price update
			$logger->log("Price updated for product UID: {$product_uid} (ID: {$product_id})");
		} else {
			$logger->log("Invalid price data for product UID: {$product_uid}");
		}
	}

	// Helper function to get product ID by SKU (product UID)
	private static function get_product_by_sku($product_uid) {
		$args = array(
			'post_type'   => 'product',
			'meta_query'  => array(
				array(
					'key'   => '_sku',
					'value' => $product_uid,
				),
			),
		);

		$query = new WP_Query($args);
		if ($query->have_posts()) {
			return $query->posts[0]->ID;
		}

		return false;
	}


	// Fetch and update inventory
	public static function fetch_and_update_inventory($product_uid, $product_id) {
		$logger = new HS_API_Logger();

		// API endpoint for fetching the product inventory
		$api_url = self::get_api_url('inventory/' . $product_uid);

		// Fetch the inventory data from the API
		$response = wp_remote_get($api_url, array(
			'headers' => array(
				'Authorization' => self::get_auth_header(),
			),
		));

		// Check for a valid response
		if (is_wp_error($response)) {
			$logger->log("Failed to fetch inventory for product UID: {$product_uid}");
			return false;
		}

		$body = wp_remote_retrieve_body($response);
		$inventory_data = json_decode($body, true);

		if (isset($inventory_data['inventory']) && isset($inventory_data['inventoryLastUpdated'])) {
			// Log successful inventory retrieval
			$logger->log("Successfully fetched inventory data for product UID: {$product_uid}");

			// Update product inventory meta
			update_post_meta($product_id, '_manage_stock', 'yes' );
			update_post_meta($product_id, '_stock', $inventory_data['inventory']);
			update_post_meta($product_id, 'inventory_last_updated', $inventory_data['inventoryLastUpdatedTimestamp']);

			// Log inventory update
			$logger->log("Inventory updated for product UID: {$product_uid} (ID: {$product_id})");
		} else {
			$logger->log("Invalid inventory data for product UID: {$product_uid}, \nresponse: " . json_encode( $inventory_data ) );
		}
	}
	// Function to generate the HTML table for product meta information
	private static function generate_product_meta_table($product, $category_data) {
		$table_html = '<br /><table style="width: 550px; margin-top: 20px;">';
		//$table_html .= '<thead><tr><th>Attribute</th><th>Value</th></tr></thead>';
		$table_html .= '<tbody>';

		// Define the meta fields and their corresponding product data
		$meta_fields = [
			'Origin' => $product['origin'],
			'Manufacturing' => $product['manufacturing'],
			'Pile' => $product['pile'],
			'Warp' => $product['warp'],
			'Condition' => $product['condition'],
			'Age' => $product['age'],
			'Shape' => $product['shape'],
			'SQM' => $product['sqm'],
			'Length' => $product['length'],
			'Width' => $product['width'],
			'Design' => $product['design'],
			'Color' => $product['color'],
			'Colors String' => $product['colorsString'],
			'Knot Density' => $product['knotDensity'],
			'Points' => $product['points'],
			'Backing' => $product['backing'],
			'KG' => $product['kg'],
			'Knot Density (CM)' => $product['knotDensityCM'],
			'Color Code' => $product['colorCode'],
		];
		if( !empty($category_data) && is_array( $category_data ) ) {
			$meta_fields['Category'] = $category_data['name'];
		}

		// Loop through the meta fields and add rows only if the value is present
		foreach ($meta_fields as $attribute => $value) {
			if (!empty($value)) {
				$table_html .= '<tr>';
				$table_html .= '<td>' . esc_html($attribute) . '</td>';
				$table_html .= '<td>' . esc_html($value) . '</td>';
				$table_html .= '</tr>';
			}
		}

		$table_html .= '</tbody></table>';

		return $table_html;
	}
}
