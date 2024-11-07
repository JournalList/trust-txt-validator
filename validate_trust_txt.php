<?php
/*
Plugin Name: Trust.txt REST API Validator with Social Verification
Description: A REST API endpoint to validate trust.txt and verify social entries contain a Trust URI in the format trust://<domain>!
Version: 1.4

MIT License

Copyright (c) 2024 Ralph W Brown

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

Author: Ralph W. Brown with assist from ChatGPT
*/

// Register REST API route
add_action('rest_api_init', function () {
    register_rest_route('trust-txt/v1', '/validate', array(
        'methods' => 'POST',
        'callback' => 'validate_trust_txt',
        'args' => array(
            'url' => array(
                'required' => true,
                'validate_callback' => function($param, $request, $key) {
                    return filter_var($param, FILTER_VALIDATE_URL);
                }
            )
        ),
    ));
});

// Callback function to validate trust.txt file and referenced entries
function validate_trust_txt($data) {
    
    // Make sure to use HTTPS and extract the domain from the URL
    $url = str_ireplace('http:', 'https:', sanitize_text_field($data['url']));
    $domain = str_ireplace('www.', '', parse_url($url, PHP_URL_HOST));

    // Fetch trust.txt file for the specified domain
    $response = fetch_trust_txt_url($domain);

    $wp_error = is_wp_error($response);
    if ($wp_error == true) {
        $wp_error_message = $response->get_error_message();
    } else {
        $wp_error_message = '';
    }
    $status_code = wp_remote_retrieve_response_code($response);

    if ($wp_error || $status_code != 200) {
        return new WP_REST_Response(array(
            'is_wp_error' => $wp_error,
            'wp_error_message' => $wp_error_message,
            'status_code' => $status_code,
            'url' => $url,
            'domain' => $domain,
            'message' => 'A trust.txt file was not found at ' . $url
        ), $status_code);
    } else {
        // Retrieve trust.txt content
        $trust_txt_content = wp_remote_retrieve_body($response);
        $lines = explode("\n", $trust_txt_content);

        // Initialize validation variables
        $belongto = [];
        $control = [];
        $controlledby = [];
        $customer = [];
        $member = [];
        $vendor = [];
        $social = [];
        $self_reference = [];

        // Loop through each line and check for the keywords
        foreach ($lines as $line) {
            if (strpos($line, 'belongto=') === 0) {
                $belongto_ref = trim(str_replace('belongto=', '', $line));
                if ($domain == str_ireplace('www.', '', parse_url($belongto_ref, PHP_URL_HOST))) {
                    $self_reference[] = $belongto_ref;
                }
                $belongto[] = $belongto_ref;
            }
            if (strpos($line, 'control=') === 0) {
                $control_ref = trim(str_replace('control=', '', $line));
                if ($domain == str_ireplace('www.', '', parse_url($control_ref, PHP_URL_HOST))) {
                    $self_reference[] = $control_ref;
                }
                $control[] = $control_ref;
            }
            if (strpos($line, 'controlledby=') === 0) {
                $controlledby_ref = trim(str_replace('controlledby=', '', $line));
                if ($domain == str_ireplace('www.', '', parse_url($controlledby_ref, PHP_URL_HOST))) {
                    $self_reference[] = $controlledby_ref;
                }
                $controlledby[] = $controlledby_ref;
            }
            if (strpos($line, 'customer=') === 0) {
                $customer_ref = trim(str_replace('customer=', '', $line));
                if ($domain == str_ireplace('www.', '', parse_url($customer_ref, PHP_URL_HOST))) {
                    $self_reference[] = $customer_ref;
                }
                $customer[] = $customer_ref;
            }
            if (strpos($line, 'member=') === 0) {
                $member_ref = trim(str_replace('member=', '', $line));
                if ($domain == str_ireplace('www.', '', parse_url($member_ref, PHP_URL_HOST))) {
                    $self_reference[] = $member_ref;
                }
                $member[] = $member_ref;
            }
            if (strpos($line, 'vendor=') === 0) {
                $vendor_ref = trim(str_replace('vendor=', '', $line));
                if ($domain == str_ireplace('www.', '', parse_url($vendor_ref, PHP_URL_HOST))) {
                    $self_reference[] = $vendor_ref;
                }
                $vendor[] = $vendor_ref;
            }
            if (strpos($line, 'social=') === 0) {
                $social[] = trim(str_replace('social=', '', $line));
            }
        }

        // Validate referenced trust.txt files
        $referenced_validations = validate_referenced_trust_txts($belongto, $controlledby, $vendor, $member, $control, $customer, $domain);

        // Validate social entries for the Trust URI
        $social_validations = validate_social_trust_uri($social, $domain);

        // Prepare the result array
        $result = array(
            'is_wp_error' => $wp_error,
            'wp_error_message' => $wp_error_message,
            'status_code' => $status_code,
            'url' => $url,
            'domain' => $domain,
            'belongto' => $belongto,
            'control' => $control,
            'controlledby' => $controlledby,
            'customer' => $customer,
            'member' => $member,
            'vendor' => $vendor,
            'social' => $social,
            'self_reference' => $self_reference,
            'referenced_validations' => $referenced_validations,
            'social_validations' => $social_validations
        );
        // Remove empty entries
        if (count($belongto) == 0) {
            unset($result["belongto"]);
        }
        if (count($control) == 0) {
            unset($result["control"]);
        }
        if (count($controlledby) == 0) {
            unset($result["controlledby"]);
        }
        if (count($customer) == 0) {
            unset($result["customer"]);
        }
        if (count($member) == 0) {
            unset($result["member"]);
        }
        if (count($vendor) == 0) {
            unset($result["vendor"]);
        }
        if (count($self_reference) == 0) {
            unset($result["self_reference"]);
        }
        return new WP_REST_Response($result, 200);
    }
}
// Helper function to check self referential entries
function check_self_ref($domain, $refernce_url) {
    $url = str_ireplace('http:', 'https:', sanitize_text_field($reference_url));
    $reference_domain = str_ireplace('www.', '', parse_url($url, PHP_URL_HOST));
    if ($domain == $reference_domain) {
        return true;
    } else {
        return false;
    }
}
// Helper function to fetch the trust.txt file from .well-known or root directory
function fetch_trust_txt_url($domain) {
    // Try fetching the trust.txt file from the root "/" directory first
    $trust_txt_url = 'https://www.' . rtrim($domain, '/') . '/trust.txt';
    $response = wp_remote_get($trust_txt_url);

    // If trust.txt is not found at root "/" directory, fallback to .well-known/trust.txt
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) != 200) {
        $trust_txt_url = 'https://www.' . rtrim($domain, '/') . '/.well-known/trust.txt';
        $response = wp_remote_get($trust_txt_url);
    }

    // Return response
    return $response;
}

// Helper function to validate referenced trust.txt files
function validate_referenced_trust_txts($belongto, $controlledby, $vendor, $member, $control, $customer, $original_domain) {
    $all_references = array_merge($belongto, $controlledby, $vendor, $member, $control, $customer);
    $results = [];
    $count = 0;

    // For each forward reference (belongto <=> member, or control <=> controlledby, or vendor <=> customer) check to see if a reverse reference exists
    foreach ($all_references as $reference_url) {
        // Determine forward and reverse attributes for this reference
        $fwd_attr = "undefined";
        $rev_attr = "undefined";
        $rev_attr_found = false;
        if (array_search($reference_url,$belongto,true) !== false) {
            $fwd_attr = "belongto";
            $rev_attr = "member";
        }
        if (array_search($reference_url,$controlledby,true) !== false) {
            $fwd_attr = "controlledby";
            $rev_attr = "control";
        }
        if (array_search($reference_url,$vendor,true) !== false) {
            $fwd_attr = "vendor";
            $rev_attr = "customer";
        }
        if (array_search($reference_url,$member,true) !== false) {
            $fwd_attr = "member";
            $rev_attr = "belongto";
        }
        if (array_search($reference_url,$control,true) !== false) {
            $fwd_attr = "control";
            $rev_attr = "controlledby";
        }
        if (array_search($reference_url,$customer,true) !== false) {
            $fwd_attr = "customer";
            $rev_attr = "vendor";
        }

        // Make sure to use HTTPS and extract the domain from the URL
        $url = str_ireplace('http:', 'https:', sanitize_text_field($reference_url));
        $reference_domain = str_ireplace('www.', '', parse_url($url, PHP_URL_HOST));

        // Fetch referenced trust.txt file
        $response = fetch_trust_txt_url($reference_domain);
        $wp_error = is_wp_error($response);
        if ($wp_error == true) {
            $wp_error_message = $response->get_error_message();
        } else {
            $wp_error_message = '';
        }
        $status_code = wp_remote_retrieve_response_code($response);

        // If fetch successful check the referenced trust.txt file for a reverse reference with the appropriate reverse attribute
        if (!$wp_error && $status_code == 200) {
            $trust_txt_content = wp_remote_retrieve_body($response);
            $lines = explode("\n", $trust_txt_content);

            // Check each line for a reverse attribute match containing the original domain of the referencing site
            foreach ($lines as $line) {
                if (strpos($line, $rev_attr . '=') === 0 && strpos($line, $original_domain) !== false) {
                    $rev_attr_found = true;
                }
            }
            if ($rev_attr_found) {
                $message = 'Corresponding "' . $rev_attr . '=https://www.' . $original_domain . '/" found at ' . $reference_url;
            } else {
                $message = 'Corresponding "' . $rev_attr . '=https://www.' . $original_domain . '/" not found at ' . $reference_url;
            }
            // Add the results for this referenced trust.txt to the restults array
            $results[] = array(
                'is_wp_error' => $wp_error,
                'wp_error_message' => $wp_error_message,
                'status_code' => $status_code,
                'reference_url' => $url,
                'reference_domain' => $reference_domain,
                'fwd_attr' => $fwd_attr,
                'rev_attr' => $rev_attr,
                'rev_attr_found' => $rev_attr_found,
                'message' => $message
            );
        } else {
            // If the referenced trust.txt file is not found or cannot be retrieved
            $results[] = array(
                'is_wp_error' => $wp_error,
                'wp_error_message' => $wp_error_message,
                'status_code' => $status_code,
                'reference_url' => $url,
                'fwd_attr' => $fwd_attr,
                'rev_attr' => $rev_attr,
                'rev_attr_found' => $rev_attr_found,
                'message' => 'A trust.txt file was not found at ' . $url
            );
        }
        $count = $count +1;
    }
    return $results;
}

// Helper function to validate social entries for the Trust URI
function validate_social_trust_uri($social_urls, $original_domain) {
    $results = [];
    $trust_uri = "trust://$original_domain!";

    foreach ($social_urls as $social_url) {
        $response = wp_remote_get($social_url);
        $wp_error = is_wp_error($response);
        if ($wp_error == true) {
            $wp_error_message = $response->get_error_message();
        } else {
            $wp_error_message = '';
        }
        $status_code = wp_remote_retrieve_response_code($response);

        if (!$wp_error && $status_code == 200) {
            $page_content = wp_remote_retrieve_body($response);

            // Check if the Trust URI is present in the page content
            if (strpos($page_content, $trust_uri) !== false) {
                $results[] = array(
                    'is_wp_error' => $wp_error,
                    'wp_error_message' => $wp_error_message,
                    'status_code' => $status_code,
                    'trust_uri_found' => true,
                    'trust_uri' => $trust_uri,
                    'social_url' => $social_url,
                    'message' => 'Trust URI ' . $trust_uri . ' found on social network account page ' . $social_url
                );
            // Check if login is required
            } else {
                if ((strpos($page_content, 'Sign ') !== false)) {
                    $results[] = array(
                        'status' => 'not found',
                        'is_wp_error' => $wp_error,
                        'wp_error_message' => $wp_error_message,
                        'status_code' => $status_code,
                        'trust_uri_found' => false,
                        'trust_uri' => $trust_uri,
                        'social_url' => $social_url,
                        'message' => 'Sign in required for social network account page ' . $social_url
                    );
                } else {
                    $results[] = array(
                        'status' => 'not found',
                        'is_wp_error' => $wp_error,
                        'wp_error_message' => $wp_error_message,
                        'status_code' => $status_code,
                        'trust_uri_found' => false,
                        'trust_uri' => $trust_uri,
                        'social_url' => $social_url,
                        'message' => 'Trust URI ' . $trust_uri . ' not found on social network account page ' . $social_url
                    );
                }
            }
        } else {
            // If the social page cannot be retrieved
            $results[] = array(
                'status' => 'not found',
                'is_wp_error' => $wp_error,
                'wp_error_message' => $wp_error_message,
                'trust_uri_found' => false,
                'status_code' => $status_code,
                'social_url' => $social_url,
                'message' => 'Could not retrieve the social network account page ' . $social_url
            );
        }
    }
    return $results;
}