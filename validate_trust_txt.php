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
    $status_code = wp_remote_retrieve_response_code($response);
    $status_message = wp_remote_retrieve_response_message($response);
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
    if ($wp_error || $status_code != 200) {
        return new WP_REST_Response(array(
            'is_wp_error' => $wp_error,
            'wp_error_message' => $wp_error_message,
            'status_code' => $status_code,
            'status_message' => $status_message,
            'flag' => 'invalid',
            'url' => $url,
            'domain' => $domain,
            'message' => 'A trust.txt file was not found at ' . $domain
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
        $contact = [];
        $disclosure = [];
        $datatrainingallowed = [];
        $self_reference = [];

        // Loop through each line and check for the keywords
        foreach ($lines as $line) {
            $message = '';
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
            if (strpos($line, 'contact=') === 0) {
                $contact[] = trim(str_replace('contact=', '', $line));
            }
            if (strpos($line, 'disclosure=') === 0) {
                $disclosure[] = trim(str_replace('disclosure=', '', $line));
            }
            if (strpos($line, 'datatrainingallowed=') === 0) {
                $datatrainingallowed[] = trim(str_replace('datatrainingallowed=', '', $line));
            }
        }

        // Validate referenced trust.txt files
        $referenced_validations = validate_referenced_trust_txts($belongto, $controlledby, $vendor, $member, $control, $customer, $domain);

        // Validate social entries for the Trust URI
        $social_validations = validate_social_trust_uri($social, $domain);

        // Validate contact entries
        $contact_validations = validate_contacts($contact);

        // Validate disclosure entries
        $disclosure_validations = validate_disclosures($disclosure);

        // Validate datatrainingallowed entries
        $datatrainingallowed_validations = validate_datatrainingallowed($datatrainingallowed);

        // Check for self referential entries
        if (count($self_reference) == 0) {
            $flag = 'checkmark';
            $message = 'A trust.txt file was found at ' . $domain;
        } else {
            $flag = 'warning';
            $message = 'Self referential entries found in trust.txt file at ' . $domain;
        }

        // Check for multiple controlledby entries
        if (count($controlledby) > 1) {
            $flag = 'invalid';
            if ($message != '') {
                $message = 'Multiple "controlledby=" entries found in trust.txt file at ' . $domain;
            } else {
                $message = $message . '\nMultiple "controlledby=" entries found in trust.txt file at ' . $domain;
            }
        }

        // Prepare the result array
        $result = array(
            'is_wp_error' => $wp_error,
            'wp_error_message' => $wp_error_message,
            'status_code' => $status_code,
            'status_message' => $status_message,
            'error_message' => $error_message,
            'flag' => $flag,
            'url' => $url,
            'domain' => $domain,
            'message' => $message,
            'belongto' => $belongto,
            'control' => $control,
            'controlledby' => $controlledby,
            'customer' => $customer,
            'member' => $member,
            'vendor' => $vendor,
            'social' => $social,
            'contact' => $contact,
            'disclosure' => $disclosure,
            'datatrainingallowed' => $datatrainingallowed,
            'self_reference' => $self_reference,
            'referenced_validations' => $referenced_validations,
            'social_validations' => $social_validations,
            'contact_validations' => $contact_validations,
            'disclosure_validations' => $disclosure_validations,
            'datatrainingallowed_validations' => $datatrainingallowed_validations
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
        if (count($contact) == 0) {
            unset($result["contact"]);
            unset($result["contact_validations"]);
        }
        if (count($disclosure) == 0) {
            unset($result["disclosure"]);
            unset($result["disclosure_validations"]);
        }
        if (count($datatrainingallowed) == 0) {
            unset($result["datatrainingallowed"]);
            unset($result["datatrainingallowed_validations"]);
        }
        if (count($self_reference) == 0) {
            unset($result["self_reference"]);
        }
        return new WP_REST_Response($result, 200);
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
        $status_code = wp_remote_retrieve_response_code($response);
        $status_message = wp_remote_retrieve_response_message($response);
        if ($wp_error == true) {
            $wp_error_message = $response->get_error_message();
            $error_message = " (" . $status_message . ", " . $wp_error_message . ")";
        } else {
            $wp_error_message = '';
            $error_message = " (" . $status_message . ")";
        }
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
                $flag = 'checkmark';
            } else {
                $message = 'Corresponding "' . $rev_attr . '=https://www.' . $original_domain . '/" not found at ' . $reference_url;
                $flag = 'invalid';
            }
            // Add the results for this referenced trust.txt to the restults array
            $results[] = array(
                'is_wp_error' => $wp_error,
                'wp_error_message' => $wp_error_message,
                'status_code' => $status_code,
                'status_message' => $status_message,
                'error_message' => $error_message,
                'flag' => $flag,
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
                'status_message' => $status_message,
                'error_message' => $error_message,
                'flag' => 'unknown',
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
                    'is_wp_error' => $wp_error,
                    'wp_error_message' => $wp_error_message,
                    'status_code' => $status_code,
                    'status_message' => $status_message,
                    'error_message' => $error_message,
                    'flag' => 'checkmark',
                    'trust_uri_found' => true,
                    'trust_uri' => $trust_uri,
                    'social_url' => $social_url,
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
                        'status' => 'not found',
                        'is_wp_error' => $wp_error,
                        'wp_error_message' => $wp_error_message,
                        'status_code' => $status_code,
                        'status_message' => $status_message,
                        'error_message' => $error_message,
                        'flag' => 'unknown',
                        'trust_uri_found' => false,
                        'trust_uri' => $trust_uri,
                        'social_url' => $social_url,
                        'message' => 'Login required for social network account page ' . $social_url
                    );
                } else {
                    $results[] = array(
                        'status' => 'not found',
                        'is_wp_error' => $wp_error,
                        'wp_error_message' => $wp_error_message,
                        'status_code' => $status_code,
                        'status_message' => $status_message,
                        'error_message' => $error_message,
                        'flag' => 'invalid',
                        'trust_uri_found' => false,
                        'trust_uri' => $trust_uri,
                        'social_url' => $social_url,
                        'page_content' => $page_content,
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
                'status_message' => $status_message,
                'error_message' => $error_message,
                'flag' => 'unknown',
                'social_url' => $social_url,
                'message' => 'Could not retrieve the social network account page ' . $social_url
            );
        }
    }
    return $results;
}
// Helper function to validate contact entries
function validate_contacts($contacts) {
    $results = [];
    // For each contact entry
    foreach ($contacts as $contact) {
        // Verify contact scheme is valid
        $scheme = parse_url($contact, PHP_URL_SCHEME);
        if ($scheme == 'mailto')
        {
            $email = str_ireplace('mailto:','',$contact);
            if (is_email($email)) {
                $results[] = array(
                    'valid_contact' => true,
                    'scheme' => $scheme,
                    'contact' => $contact
                );
            } else {
                $results[] = array(
                    'valid_contact' => false,
                    'scheme' => $scheme,
                    'contact' => $contact
                );
            }
        } elseif ($scheme == 'tel') {
            $phone = str_ireplace('tel:','',$contact);
            if (strlen(filter_var($contact, FILTER_SANITIZE_NUMBER_INT)) >= 10) {
                $results[] = array(
                    'valid_contact' => true,
                    'scheme' => $scheme,
                    'contact' => $contact
                );
            } else {
                $results[] = array(
                    'valid_contact' => false,
                    'scheme' => $scheme,
                    'contact' => $contact
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
                    'valid_contact' => true,
                    'scheme' => $scheme,
                    'contact' => $contact
                );
            } else {
                $results[] = array(
                    'valid_contact' => false,
                    'scheme' => $scheme,
                    'contact' => $contact,
                    'is_wp_error' => $wp_error,
                    'wp_error_message' => $wp_error_message,
                    'status_code' => $status_code,
                    'status_message' => $status_message,
                    'message' => $error_message
                );
            }
        } else {
            // Check if missing "mailto:" or "tel" in contact
            if (is_email($contact)) {
                $results[] = array(
                    'valid_contact' => true,
                    'scheme' => 'mailto',
                    'contact' => $contact
                );
            } elseif (strlen(filter_var($contact, FILTER_SANITIZE_NUMBER_INT)) >= 10) {
                $results[] = array(
                    'valid_contact' => true,
                    'scheme' => 'tel',
                    'contact' => $contact
                );
            } else {
                $results[] = array(
                    'valid_contact' => false,
                    'scheme' => $scheme,
                    'contact' => $contact
                );
            }
        }
    }
    return $results;
}
// Helper function for disclosures
function validate_disclosures($disclosures) {
    $results = [];
    // For each disclosure entry
    foreach ($disclosures as $disclosure) {
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
                'valid_disclosure' => true,
                'disclosure' => $disclosure
            );
        } else {
            $results[] = array(
                'valid_disclosure' => false,
                'disclosure' => $disclosure,
                'is_wp_error' => $wp_error,
                'wp_error_message' => $wp_error_message,
                'status_code' => $status_code,
                'status_message' => $status_message,
                'message' => $error_message
            );
        }
    }
    return $results;
}
// Helper function to validate datatrainingallowed entries
function validate_datatrainingallowed($datatrainingalloweds) {
    $results = [];
    $value = '';
    // For each datatrainingallowed entry
    foreach ($datatrainingalloweds as $datatrainingallowed) {
        if ($datatrainingallowed == 'yes' || $datatrainingallowed == 'no') {
            if ($value == '') {
                $value = $datatrainingallowed;
                $results[] = array(
                    'valid_datatrainingallowed' => true,
                    'datatrainingallowed' => $datatrainingallowed,
                    'message' => 'Valid datatrainingallowed entry'
                );
            } elseif ($value != $datatrainingallowed) {
                $value = $datatrainingallowed;
                $results[] = array(
                    'valid_datatrainingallowed' => false,
                    'datatrainingallowed' => $datatrainingallowed,
                    'message' => 'Conflicting datatrainingallowed entries'
                );
            } else {
                $results[] = array(
                    'valid_datatrainingallowed' => false,
                    'datatrainingallowed' => $datatrainingallowed,
                    'message' => 'Invalid datatrainingallowed entry'
                );
            }
        }
    }
    return $results;
}