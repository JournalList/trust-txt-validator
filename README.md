# trust-txt-validator
WordPress plugin to provide a REST Web Service that validates a website's trust.txt file. 

1. Explanation of the Code:

    1. Parsing the original trust.txt:
        The original trust.txt file is fetched from the provided domain, and the entries for belongto=, control=, controlledby=, customer=, member=, social=, and vendor= are extracted.

    2. For each non-social= entry, fetch and parse the referenced trust.txt Files:
        For each URL in the belongto=, control=, controlledby=, customer=, member=, and vendor= entries, the code fetches the corresponding trust.txt file from that URL.
        It checks for the presence of the corresponding reverse entries (belongto <=> member, control <=> controlledby, customer <=> vendor) in the referenced trust.txt files.
        The results of these checks are stored in an array and returned as part of the API response.
       
    3. For each social= entry:
        Fetches the HTML content of the referenced social network account page.
        Checks if the pocial network account age contains the Trust URI in the form trust://<original_domain>!.
        The restults of these checks are stored in an array and returned as part of the API response.

    4. Return the Results:
        For each referenced trust.txt, the API response includes whether the corresponding reverse entries (belongto <=> member, control <=> controlledby, customer <=> vendor) were found.
        For each social= entry, the API respones includes whether the Trust URI (trust://<original_domain>!) was found on the social network account page.
        If any trust.txt file or social network accouont page couldn’t be retrieved, an error message is included.

2. Make the Plugin Available in WordPress:
   
    Activate the Plugin

      Go to your WordPress Admin Dashboard.
      Navigate to "Plugins" -> "Installed Plugins".
      Click on "Add New Plugin".
      Click on "Upload Plugin".
      Find your "Trust.txt REST API Validator" plugin and click "Install Now".
    C  lick on "Activate Plugin"

4. How to Use the REST Web Service

  Once the plugin is activated, you can now make POST requests to the REST endpoint. Here’s how to interact with it:

  Endpoint:

    POST https://your-wordpress-site.com/wp-json/trust-txt/v1/validate

  Request Format:

  Send a POST request with the following JSON body:

    {
      "url": "https://example.com"
    }

  Example Request Using cURL:

    curl -X POST https://your-wordpress-site.com/wp-json/trust-txt/v1/validate -H "Content-Type: application/json" -d '{"url": "https://example.com"}'

  
