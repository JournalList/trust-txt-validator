<?php
/*
Plugin Name: Validator for trust.txt file referenced by Trust URI
Description: A REST API endpoint to validate trust.txt and verify belongto=, control=, controlledby=, and vendor= entries in the trust.txt file
    for the specified domain by fetching referenced trust.txt files and parsing for matching member=, controlledby=, control=, and vendor= entries.
Input "url": "https://<domain>" [, "full": "true"]
Returns: array of {"domain", "status": "found" | "not found" | "error", "message" : string}
    linenum - line number of entry [if "full" is "true"]
    attribute - attribute of entry [if "full" is "true"]
    domain - domain of trust.txt file evaluated
    status - "found" = a matching entry (e.g., "member=" for "belongto=" entry) was found for the original input domain, 
            "not found" = a matching entry was not found for the original input domain,
            "error" = a trust.txt file was not found or not accessible for this domain,
            "unknown" = a login is required to access a social media account page,
            "warning" = a warning error is found (e.g., multiple controlledby= entries)
    "message" - a text message describing the result

Version: 1.0

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

// Allow CORS for REST API
add_action('rest_api_init', function () {
    // Log API call
    // error_log('REST API request received: ' . json_encode($_SERVER));
    
    // Set the Access-Control-Allow-Origin header for all REST API responses
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");

    // Respond to preflight requests
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        status_header(200);
        exit();
    }
    // Make sure site allows REST API access
    add_filter('rest_authentication_errors', function ($access) {
        return is_wp_error($access) ? $access : true;
    });    
});

// Register REST API route
add_action('rest_api_init', function () {
    register_rest_route('trust-txt/v1', '/validate', array(
        'methods' => array('GET', 'POST', 'OPTIONS'),
        'callback' => 'validate_trust_txt',
        'args' => array(
            'url' => array(
                'required' => true,
                'validate_callback' => function($param, $request, $key) {
                    return filter_var($param, FILTER_VALIDATE_URL);
                }
            ),
            'full' => array(
                'required' => false, // This parameter is optional
                'default' => 'false', // Default is not to do a full check of trust.txt file
                'validate_callback' => function($param, $request, $key) {
                    return in_array($param, array('true', 'false'), true); // Only allow 'true' or 'false'
                }
            )
        ),
    ));
});

// Callback function to validate trust.txt file and referenced entries
function validate_trust_txt($data) {
    $attributes = array(
        'belongto=',
        'control=',
        'controlledby=',
        'vendor=',
        'customer=',
        'member=',
        'social=',
        'contact=',
        'disclosure=',
        'datatrainingallowed='
    );
    // Make sure to use HTTPS and extract the domain from the URL
    $url = str_ireplace('http:', 'https:', sanitize_text_field($data['url']));
    $domain = str_ireplace('www.', '', parse_url($url, PHP_URL_HOST));
    $full = $data->get_param('full') ?: 'false'; // Optional parameter with default value

    // Fetch trust.txt file for the specified domain
    $response = fetch_trust_txt_url($domain);
    $wp_error = is_wp_error($response);
    $status_code = wp_remote_retrieve_response_code($response);
    $status_message = wp_remote_retrieve_response_message($response);
    if ($wp_error == true) {
        $wp_error_message = $response->get_error_message();
        $error_message = " (" . $status_message . ", " . $wp_error_message . ")";
    } else {
        $wp_error_message = '';
        $error_message = " (" . $status_code . ": " . $status_message . ")";
    }
    if ($wp_error || $status_code != 200) {
        return new WP_REST_Response(array([
            'status' => 'error',
            'domain' => $domain,
            'message' => 'A trust.txt file was not found at ' . $url . $error_message
        ]
        ), $status_code);
    } else {
        // Retrieve trust.txt content
        $trust_txt_content = wp_remote_retrieve_body($response);
        $lines = explode("\n", $trust_txt_content);

        // Parse the trust.xt file
        $linenums = [];
        $attrs = [];
        $values = [];
        $linenum = 0;
        $num_entries = 0;
        foreach ($lines as $line) {
            $linenum = $linenum + 1;
            // Loop through each attribute type
            foreach ($attributes as $attribute) {
                if (strpos($line, $attribute) === 0) {
                    $linenums[] = $linenum;
                    $attrs[] = $attribute;
                    $values[] = trim(str_replace($attribute, '', $line));
                    $num_entries += 1;
                }
            }
        }
        //* Validate references
        $referenced_validations = validate_referenced_trust_txts($num_entries, $linenums, $attrs, $values, $domain, $full);

        // Check for self referential entries
        $self_reference = [];
        for ($i = 0; $i < $num_entries; $i++) {
            $linenum = $linenums[$i];
            $attribute = $attrs[$i];
            $reference_url = str_ireplace('http:', 'https:', sanitize_text_field($values[$i]));
            $reference_domain = str_ireplace('www.', '', parse_url($reference_url, PHP_URL_HOST));
            if ($attribute !== 'social=' && $attribute !== 'contact=' && $attribute !== 'disclosure=' && $attribute !== 'datatrainingallowed='  && $reference_url === $url) {
                if ($full === 'false') {
                    $self_reference[] = array(
                        'status' => 'warning',
                        'domain' => $reference_domain,
                        'message' => 'Self referential entry found in trust.txt file at ' . $reference_url
                    );
                } else {
                    $self_reference[] = array(
                        'linenum' => $linenum,
                        'attribute' => $attribute,
                        'status' => 'warning',
                        'domain' => $reference_domain,
                        'message' => 'Self referential entry found in trust.txt file at ' . $reference_url
                    );
                }
            }
        }
        // Check for multiple controlledby= entries
        $control_err = [];
        $control_cnt = 0;
        for ($i = 0; $i < $num_entries; $i++) {
            $linenum = $linenums[$i];
            $attribute = $attrs[$i];
            $reference_url = str_ireplace('http:', 'https:', sanitize_text_field($values[$i]));
            $reference_domain = str_ireplace('www.', '', parse_url($reference_url, PHP_URL_HOST));
            if ($attribute === 'controlledby=') {
                $control_cnt += 1;
                if ($control_cnt > 1) {
                    if ($full === 'false') {
                        $control_err[] = array(
                            'status' => 'error',
                            'domain' => $reference_domain,
                            'message' => 'Multiple controlledby= entry found in trust.txt file at ' . $url
                        );
                    } else {
                        $control_err[] = array(
                            'linenum' => $linenum,
                            'attribute' => 'controlledby=',
                            'status' => 'error',
                            'domain' => $reference_domain,
                            'message' => 'Multiple controlledby= found in trust.txt file at ' . $url
                        );
                    }
                }
            }
        }
        // If we are only validating a Trust URI reference just return the belongto=, control=, controlledby=, and vendor= references
        if ($full === 'false') {
            $result = array_merge($referenced_validations, $self_reference, $control_err);
            return new WP_REST_Response($result, 200);
        } else {
            // Otherwise, validate social=, contact=, and disclosure= references

            // Validate social entries
            $social_validations = validate_social_trust_uri($num_entries, $linenums, $attrs, $values, $domain);

            // Validate contact entries
            $contact_validations = validate_contacts($num_entries, $linenums, $attrs, $values);

            // Validate disclosure entries
            $disclosure_validations = validate_disclosures($num_entries, $linenums, $attrs, $values);

            // Validate datatrainingallowed entries
            $datatrainingallowed_validations = validate_datatrainingallowed($num_entries, $linenums, $attrs, $values, $domain);
            /*
            for ($i = 0; $i < $num_entries; $i++) {
                $result[] = array(
                    'linenum' => $linenums[$i],
                    'attribute' => $attrs[$i],
                    'value' => $values[$i]
                );
            }
            */
            // Prepare the result array
            
            $result = array_merge($referenced_validations, $social_validations, $contact_validations, $disclosure_validations, 
                $self_reference, $control_err, $datatrainingallowed_validations);
            
            return new WP_REST_Response($result, 200);
        }
    }
}
// Helper function to fetch the trust.txt file from .well-known or root directory
function fetch_trust_txt_url($domain) {
    // Try fetching the trust.txt file from the root "/" directory first
    $trust_txt_url = 'https://www.' . rtrim($domain, '/') . '/trust.txt';
    $response_root = wp_remote_get($trust_txt_url);

    // If trust.txt is not found at root "/" directory, fallback to /.well-known/trust.txt
    if (is_wp_error($response_root) || wp_remote_retrieve_response_code($response_root) != 200) {
        $trust_txt_url = 'https://www.' . rtrim($domain, '/') . '/.well-known/trust.txt';
        $response = wp_remote_get($trust_txt_url);
        // Return the most informative response (the one with a wp_error if there was one)
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) == 200) {
            return $response;
        }
    }
    return $response_root;
}
// Helper function to validate referenced trust.txt files
function validate_referenced_trust_txts($num_entries, $linenums, $attrs, $values, $domain, $full) {
    // Map forward attributes to reverse attributes, 'social=', 'contact=', 'disclosure=', and 'datatrainingallowed=' do not have reverse attributes
    $fwd2rev = array(
        'belongto=' => 'member=',
        'member=' => 'belongto=',
        'control=' => 'controlledby=',
        'controlledby=' => 'control=',
        'vendor=' => 'customer=',
        'customer=' => 'vendor=',
        'social=' => '',
        'contact=' => '',
        'disclosure=' => '',
        'datatrainingallowed=' => ''
    );
    $results = [];
    
    // Loop through each trust.txt file entry
    for ($i = 0; $i < $num_entries; $i++) {
        $linenum = $linenums[$i];
        $attribute = $attrs[$i];
        $value = $values[$i];

        // If we are only validating a Trust URI reference just return the belongto=, control=, controlledby=, and vendor= references, and
        // skip the member= and customer= references
        if ($full === 'false' && ($attribute === 'member=' || $attribute === 'customer=')) {
            continue;
        }
        // Get the reverse attribute for this reference
        $rev_attr = $fwd2rev[$attribute];
        $rev_attr_found = false;

        // If the reverse attribute is not null then check for a corresponding reverse attribute in the referenced trust.txt file
        if ($rev_attr != '') {
            // Make sure to use HTTPS and extract the domain from the URL
            $reference_url = str_ireplace('http:', 'https:', sanitize_text_field($value));
            $reference_domain = str_ireplace('www.', '', parse_url($reference_url, PHP_URL_HOST));
            // Fetch referenced trust.txt file
            $response = fetch_trust_txt_url($reference_domain);
            $wp_error = is_wp_error($response);
            $status_code = wp_remote_retrieve_response_code($response);
            $status_message = wp_remote_retrieve_response_message($response);
            if ($wp_error == true) {
                $wp_error_message = $response->get_error_message();
                $error_message = " (" . $status_message . ", " . $wp_error_message . ")";
            } else {
                $wp_error_message = '';
                $error_message = " (" . $status_code . ": " . $status_message . ")";
            }
            // If fetch successful check the referenced trust.txt file for a reverse reference with the appropriate reverse attribute
            if (!$wp_error && $status_code == 200) {
                $trust_txt_content = wp_remote_retrieve_body($response);
                $lines = explode("\n", $trust_txt_content);
                // Check each line for a reverse attribute match containing the original domain of the referencing site
                foreach ($lines as $line) {
                    if (strpos($line, $rev_attr) === 0 && strpos($line, $domain) !== false) {
                        $rev_attr_found = true;
                    }
                }
                if ($rev_attr_found) {
                    $message = 'Corresponding "' . $rev_attr . 'https://www.' . $domain . '/" found at ' . $reference_url;
                    $status = 'found';
                } else {
                    $message = 'Corresponding "' . $rev_attr . 'https://www.' . $domain . '/" not found at ' . $reference_url;
                    $status = 'not found';
                }
                // Add the results for this referenced trust.txt to the restults array
                if ($full === 'false') {
                    $results[] = array(
                        'status' => $status,
                        'domain' => str_ireplace('www.', '', parse_url($reference_url, PHP_URL_HOST)),
                        'message' => $message
                    );
                } else {
                    $results[] = array(
                        'linenum' => $linenum,
                        'attribute' => $attribute,
                        'status' => $status,
                        'domain' => str_ireplace('www.', '', parse_url($reference_url, PHP_URL_HOST)),
                        'message' => $message
                    );
                }
            } else {
                // If the referenced trust.txt file is not found or cannot be retrieved
                if ($full === 'false') {
                    $results[] = array(
                        'status' => 'error',
                        'domain' => str_ireplace('www.', '', parse_url($reference_url, PHP_URL_HOST)),
                        'message' => 'A trust.txt file was not found at ' . $reference_url . $error_message
                    );
                } else {
                    $results[] = array(
                        'linenum' => $linenum,
                        'attribute' => $attribute,
                        'status' => 'error',
                        'domain' => str_ireplace('www.', '', parse_url($reference_url, PHP_URL_HOST)),
                        'message' => 'A trust.txt file was not found at ' . $reference_url . $error_message
                    );
                }
            }
        }
    }
    return $results;
}
// Helper function to validate social entries for the Trust URI
function validate_social_trust_uri($num_entries, $linenums, $attrs, $values, $original_domain) {
    $results = [];
    $trust_uri = "trust://$original_domain!";
    // Loop through each trust.txt file entry
    for ($i = 0; $i < $num_entries; $i++) {
        // Skip entry if not a social= entry
        if ($attribute !== 'social=') {
            continue;
        }
        $linenum = $linenums[$i];
        $attribute = $attrs[$i];
        $social_url = $values[$i];
        // Get social URL
        $response = wp_remote_get($social_url);
        $wp_error = is_wp_error($response);
        $status_code = wp_remote_retrieve_response_code($response);
        $status_message = wp_remote_retrieve_response_message($response);
        if ($wp_error == true) {
            $wp_error_message = $response->get_error_message();
            $error_message = " (" . $status_message . ", " . $wp_error_message . ")";
        } else {
            $wp_error_message = '';
            $error_message = " (" . $status_message . ")";
        }
        if (!$wp_error && $status_code == 200) {
            $page_content = wp_remote_retrieve_body($response);
            // Check if the Trust URI is present in the page content
            if (strpos($page_content, $trust_uri) !== false) {
                $results[] = array(
                    'linenum' => $linenum,
                    'attribute' => $attribute,
                    'status' => 'found',
                    'domain' => str_ireplace('www.', '', parse_url($social_url, PHP_URL_HOST)),
                    'message' => 'Trust URI ' . $trust_uri . ' found on social network account page ' . $social_url
                );
            // Check if login is required
            } else {
                $pattern = array(
                    "/sign[u|i| ]/mi",
                    "/log[u|i| ]/mi",
                    "/x\.com/",
                    "/bsky\.social/"
                );
                if ((preg_match($pattern[0],$page_content) == 1) || (preg_match($pattern[1],$page_content) == 1) || 
                    (preg_match($pattern[2],$page_content) == 1) || (preg_match($pattern[3],$page_content) == 1)) {
                    $results[] = array(
                        'linenum' => $linenum,
                        'attribute' => $attribute,
                        'status' => 'unknown',
                        'domain' => str_ireplace('www.', '', parse_url($social_url, PHP_URL_HOST)),
                        'message' => 'Login required for social network account page ' . $social_url
                    );
                } else {
                    $results[] = array(
                        'linenum' => $linenum,
                        'attribute' => $attribute,
                        'status' => 'not found',
                        'domain' => str_ireplace('www.', '', parse_url($social_url, PHP_URL_HOST)),
                        'message' => 'Trust URI ' . $trust_uri . ' not found on social network account page ' . $social_url
                    );
                }
            }
        } else {
            // If the social page cannot be retrieved
            $results[] = array(
                'linenum' => $linenum,
                'attribute' => $attribute,
                'status' => 'error',
                'domain' => str_ireplace('www.', '', parse_url($social_url, PHP_URL_HOST)),
                'message' => 'Could not retrieve the social network account page ' . $social_url
            );
        }
    }
    return $results;
}

// Helper function to validate contact entries
function validate_contacts($num_entries, $linenums, $attrs, $values) {
    $results = [];
    // Loop through each trust.txt file entry
    for ($i = 0; $i < $num_entries; $i++) {
        $linenum = $linenums[$i];
        $attribute = $attrs[$i];
        $contact = $values[$i];
        // Skip if entry not a contact= entry
        if ($attribute !== 'contact=') {
            continue;
        }
        // Verify contact scheme is valid
        $scheme = parse_url($contact, PHP_URL_SCHEME);
        if ($scheme == 'mailto')
        {
            $email = str_ireplace('mailto:','',$contact);
            if (is_email($email)) {
                $results[] = array(
                    'linenum' => $linenum,
                    'attribute' => $attribute,
                    'status' => 'found',
                    'domain' => $scheme,
                    'message' => 'Email contact found ' . $contact
                );
            } else {
                $results[] = array(
                    'linenum' => $linenum,
                    'attribute' => $attribute,
                    'status' => 'error',
                    'domain' => $scheme,
                    'message' => 'Email contact not valid ' . $contact
                );
            }
        } elseif ($scheme == 'tel') {
            $phone = str_ireplace('tel:','',$contact);
            if (strlen(filter_var($contact, FILTER_SANITIZE_NUMBER_INT)) >= 10) {
                $results[] = array(
                    'linenum' => $linenum,
                    'attribute' => $attribute,
                    'status' => 'found',
                    'domain' => $scheme,
                    'message' => 'Phone contact found ' . $contact
                );
            } else {
                $results[] = array(
                    'linenum' => $linenum,
                    'attribute' => $attribute,
                    'status' => 'found',
                    'domain' => $scheme,
                    'message' => 'Phone contact not valid ' . $contact
                );
            }
        } elseif ($scheme == 'http' || $scheme == 'https') {
            // Fetch contact URL
            $response = wp_remote_get($contact);
            $wp_error = is_wp_error($response);
            $status_code = wp_remote_retrieve_response_code($response);
            $status_message = wp_remote_retrieve_response_message($response);
            if ($wp_error == true) {
                $wp_error_message = $response->get_error_message();
                $error_message = " (" . $status_message . ", " . $wp_error_message . ")";
            } else {
                $wp_error_message = '';
                $error_message = " (" . $status_message . ")";
            }
            if (!$wp_error && $status_code == 200) {
                $results[] = array(
                    'linenum' => $linenum,
                    'attribute' => $attribute,
                    'status' => 'found',
                    'domain' => str_ireplace('www.', '', parse_url($contact, PHP_URL_HOST)),
                    'message' => 'Contact page found at ' . $contact
                );
            } else {
                $results[] = array(
                    'linenum' => $linenum,
                    'attribute' => $attribute,
                    'status' => 'not found',
                    'domain' => str_ireplace('www.', '', parse_url($contact, PHP_URL_HOST)),
                    'message' => 'Contact page not found at ' . $contact . $error_message
                );
            }
        } else {
            // Check if missing "mailto:" or "tel" in contact
            if (is_email($contact)) {
                $results[] = array(
                    'linenum' => $linenum,
                    'attribute' => $attribute,
                    'status' => 'found',
                    'domain' => 'mailto',
                    'message' => 'Email contact found ' . $contact
                );
            } elseif (strlen(filter_var($contact, FILTER_SANITIZE_NUMBER_INT)) >= 10) {
                $results[] = array(
                    'linenum' => $linenum,
                    'attribute' => $attribute,
                    'status' => 'found',
                    'domain' => 'tel',
                    'message' => 'Phone contact found ' . $contact
                );
            } else {
                $results[] = array(
                    'linenum' => $linenum,
                    'attribute' => $attribute,
                    'status' => 'error',
                    'domain' => 'unknown scheme',
                    'contact' => 'Unknown contact ' . $contact
                );
            }
        }
    }
    return $results;
}
// Helper function for disclosures
function validate_disclosures($num_entries, $linenums, $attrs, $values) {
    $results = [];
    // Loop through each trust.txt file entry
    for ($i = 0; $i < $num_entries; $i++) {
        $linenum = $linenums[$i];
        $attribute = $attrs[$i];
        $disclosure = $values[$i];
        // Skip if entry not a disclosure= entry
        if ($attribute !== 'disclosure=') {
            continue;
        }
        // Fetch contact URL
        $response = wp_remote_get($disclosure);
        $wp_error = is_wp_error($response);
        $status_code = wp_remote_retrieve_response_code($response);
        $status_message = wp_remote_retrieve_response_message($response);
        if ($wp_error == true) {
            $wp_error_message = $response->get_error_message();
            $error_message = " (" . $status_message . ", " . $wp_error_message . ")";
        } else {
            $wp_error_message = '';
            $error_message = " (" . $status_message . ")";
        }
        if (!$wp_error && $status_code == 200) {
            $results[] = array(
                'linenum' => $linenum,
                'attribute' => $attribute,
                'status' => 'found',
                'domain' => str_ireplace('www.', '', parse_url($disclosure, PHP_URL_HOST)),
                'message' => 'Disclosure page found at ' . $disclosure
            );
        } else {
            $results[] = array(
                'linenum' => $linenum,
                'attribute' => $attribute,
                'status' => 'error',
                'domain' => str_ireplace('www.', '', parse_url($disclosure, PHP_URL_HOST)),
                'message' => 'Disclosure page not found at ' . $disclosure . $error_message
            );
        }
    }
    return $results;
}
// Helper function to validate datatrainingallowed entries
function validate_datatrainingallowed($num_entries, $linenums, $attrs, $values, $domain) {
    $results = [];
    $value = '';
    $count = 0;
    // Loop through each trust.txt file entry
    for ($i = 0; $i < $num_entries; $i++) {
        $linenum = $linenums[$i];
        $attribute = $attrs[$i];
        $datatrainingallowed = $values[$i];
        // Skip if entry not a datatrainingallowed= entry
        if ($attribute !== 'datatrainingallowed=') {
            continue;
        }
        // Check if datatrainingallowed is a yes or no
        if ($datatrainingallowed == 'yes' || $datatrainingallowed == 'no') {
            // Check if datatrainingallowed has been previously set to a conflicting value
            if ($value == '') {
                $value = $datatrainingallowed;
                $results[] = array(
                    'linenum' => $linenum,
                    'attribute' => $attribute,
                    'status' => 'found',
                    'domain' => $domain,
                    'message' => 'Valid datatrainingallowed entry ' . $datatrainingallowed
                );
            } elseif ($value != $datatrainingallowed) {
                $value = $datatrainingallowed;
                $results[] = array(
                    'linenum' => $linenum,
                    'attribute' => $attribute,
                    'status' => 'error',
                    'domain' => $domain,
                    'message' => 'Conflicting datatrainingallowed entries ' . $datatrainingallowed
                );
            } 
        } else {
            $results[] = array(
                'linenum' => $linenum,
                'attribute' => $attribute,
                'status' => 'error',
                'domain' => $domain,
                'message' => 'Invalid datatrainingallowed entry ' . $datatrainingallowed
            );
        }
        $count = $count + 1;
    }
    return $results;
}
