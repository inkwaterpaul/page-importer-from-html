<?php
/**
 * Content Extractor Class
 * Extracts content from HTML files
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class PI_Content_Extractor {

    /**
     * Extract all data from HTML file
     *
     * @param string $file_path Path to HTML file
     * @param bool $use_blocks Whether to convert content to blocks (false when using block pattern)
     * @return array|WP_Error Array with extracted data or WP_Error on failure
     */
    public static function extract_from_file($file_path, $use_blocks = true) {
        try {
            if (!file_exists($file_path)) {
                return new WP_Error('file_not_found', __('File not found', 'html-page-importer'));
            }

            $html_content = @file_get_contents($file_path);

            if ($html_content === false) {
                return new WP_Error('read_error', __('Could not read file', 'html-page-importer'));
            }

        // Extract data
        $title = self::extract_title($html_content);
        $content = self::extract_content($html_content, $use_blocks);
        $date = self::extract_date($html_content);
        $first_image = self::extract_first_image($html_content);

        if (empty($title)) {
            return new WP_Error('no_title', __('No title found in HTML file', 'html-page-importer'));
        }

        if (empty($content)) {
            return new WP_Error('no_content', __('No content found in HTML file', 'html-page-importer'));
        }

            return array(
                'title' => $title,
                'content' => $content,
                'date' => $date,
                'first_image' => $first_image,
                'file_name' => basename($file_path)
            );

        } catch (Exception $e) {
            return new WP_Error('extraction_exception', 'Exception during extraction: ' . $e->getMessage());
        } catch (Error $e) {
            return new WP_Error('extraction_error', 'Fatal error during extraction: ' . $e->getMessage());
        }
    }

    /**
     * Extract title from <h1> tag
     *
     * @param string $html HTML content
     * @return string Title or empty string
     */
    private static function extract_title($html) {
        // Use DOMDocument for better HTML parsing
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $h1_tags = $dom->getElementsByTagName('h1');

        if ($h1_tags->length > 0) {
            $title = $h1_tags->item(0)->textContent;
            return trim($title);
        }

        return '';
    }

    /**
     * Extract content from <div class="page-content">
     * Strips out inline styles, extra attributes, and cleans HTML
     *
     * @param string $html HTML content
     * @param bool $use_blocks Whether to convert to WordPress blocks
     * @return string Cleaned content
     */
    private static function extract_content($html, $use_blocks = true) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $page_content_divs = $xpath->query('//div[@class="page-content"]');

        if ($page_content_divs->length === 0) {
            return '';
        }

        $content_div = $page_content_divs->item(0);

        // Get the inner HTML
        $inner_html = '';
        foreach ($content_div->childNodes as $child) {
            $inner_html .= $dom->saveHTML($child);
        }

        // For patterns, use minimal cleaning to preserve structure
        if (!$use_blocks) {
            $cleaned_content = self::minimal_clean_html($inner_html);
        } else {
            $cleaned_content = self::clean_html($inner_html);
        }

        // Always convert to WordPress blocks
        $block_content = self::convert_to_blocks($cleaned_content);
        return $block_content;
    }

    /**
     * Clean HTML content
     * Removes inline styles, paraeid, paraid, and other unwanted attributes
     *
     * @param string $html HTML content
     * @return string Cleaned HTML
     */
    private static function clean_html($html) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Remove unwanted attributes from all elements
        $all_elements = $xpath->query('//*[@style or @paraeid or @paraid or @class]');

        foreach ($all_elements as $element) {
            // Remove specific attributes
            $element->removeAttribute('style');
            $element->removeAttribute('paraeid');
            $element->removeAttribute('paraid');
            $element->removeAttribute('class');
        }

        // Get cleaned HTML
        $cleaned = $dom->saveHTML();

        // Remove XML declaration and extra wrappers
        $cleaned = preg_replace('/^<!DOCTYPE.+?>/', '', str_replace(array('<?xml encoding="UTF-8">', '<html>', '</html>', '<body>', '</body>'), '', $cleaned));

        // Clean up extra whitespace
        $cleaned = trim($cleaned);

        // Convert relative image URLs to absolute if needed
        // (You may want to adjust this based on your needs)

        return $cleaned;
    }

    /**
     * Minimal clean HTML content for block patterns
     * Only removes problematic attributes like paraeid, paraid
     * Preserves styles and classes that might be needed
     *
     * @param string $html HTML content
     * @return string Minimally cleaned HTML
     */
    private static function minimal_clean_html($html) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Only remove truly problematic attributes
        $all_elements = $xpath->query('//*[@paraeid or @paraid]');

        foreach ($all_elements as $element) {
            // Remove only specific problematic attributes
            $element->removeAttribute('paraeid');
            $element->removeAttribute('paraid');
        }

        // Get cleaned HTML
        $cleaned = $dom->saveHTML();

        // Remove XML declaration and extra wrappers
        $cleaned = preg_replace('/^<!DOCTYPE.+?>/', '', str_replace(array('<?xml encoding="UTF-8">', '<html>', '</html>', '<body>', '</body>'), '', $cleaned));

        // Clean up extra whitespace
        $cleaned = trim($cleaned);

        return $cleaned;
    }

    /**
     * Convert HTML content to WordPress blocks
     *
     * @param string $html HTML content
     * @return string Content formatted as WordPress blocks
     */
    private static function convert_to_blocks($html) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $blocks = '';

        // Process each child node
        foreach ($dom->childNodes as $node) {
            $blocks .= self::node_to_block($node);
        }

        // Remove any XML declaration artifacts that may have leaked through
        $blocks = str_replace('encoding="UTF-8"', '', $blocks);
        $blocks = str_replace('<?xml encoding="UTF-8"?>', '', $blocks);

        return trim($blocks);
    }

    /**
     * Convert a DOM node to a WordPress block
     *
     * @param DOMNode $node DOM node
     * @return string Block markup
     */
    private static function node_to_block($node) {
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            // Skip text nodes that are just whitespace
            if ($node->nodeType === XML_TEXT_NODE && trim($node->textContent) === '') {
                return '';
            }
            return $node->textContent;
        }

        $tag = strtolower($node->nodeName);
        $html = $node->ownerDocument->saveHTML($node);

        switch ($tag) {
            case 'p':
                return "<!-- wp:paragraph -->\n" . $html . "\n<!-- /wp:paragraph -->\n\n";

            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                $level = substr($tag, 1);
                return "<!-- wp:heading {\"level\":" . $level . "} -->\n" . $html . "\n<!-- /wp:heading -->\n\n";

            case 'img':
                $src = $node->getAttribute('src');
                $alt = $node->getAttribute('alt');
                return "<!-- wp:image -->\n<figure class=\"wp-block-image\">" . $html . "</figure>\n<!-- /wp:image -->\n\n";

            case 'ul':
                return "<!-- wp:list -->\n" . $html . "\n<!-- /wp:list -->\n\n";

            case 'ol':
                return "<!-- wp:list {\"ordered\":true} -->\n" . $html . "\n<!-- /wp:list -->\n\n";

            case 'blockquote':
                return "<!-- wp:quote -->\n" . $html . "\n<!-- /wp:quote -->\n\n";

            case 'pre':
            case 'code':
                return "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>" . htmlspecialchars($node->textContent) . "</code></pre>\n<!-- /wp:code -->\n\n";

            case 'table':
                return "<!-- wp:table -->\n<figure class=\"wp-block-table\">" . $html . "</figure>\n<!-- /wp:table -->\n\n";

            case 'hr':
                return "<!-- wp:separator -->\n" . $html . "\n<!-- /wp:separator -->\n\n";

            case 'figure':
                // Check if it contains an image
                $images = $node->getElementsByTagName('img');
                if ($images->length > 0) {
                    return "<!-- wp:image -->\n" . $html . "\n<!-- /wp:image -->\n\n";
                }
                return $html . "\n\n";

            case 'div':
            case 'section':
            case 'article':
                // Process children and wrap in group block
                $content = '';
                foreach ($node->childNodes as $child) {
                    $content .= self::node_to_block($child);
                }
                if (!empty(trim($content))) {
                    return "<!-- wp:group -->\n<div class=\"wp-block-group\">" . $content . "</div>\n<!-- /wp:group -->\n\n";
                }
                return '';

            default:
                // For other elements, process children
                $content = '';
                foreach ($node->childNodes as $child) {
                    $content .= self::node_to_block($child);
                }
                return $content;
        }
    }

    /**
     * Extract date from <small> tag
     *
     * @param string $html HTML content
     * @return string|null Date in Y-m-d H:i:s format or null
     */
    private static function extract_date($html) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $small_tags = $dom->getElementsByTagName('small');

        if ($small_tags->length > 0) {
            // Try all <small> tags to find one with a parseable date
            foreach ($small_tags as $small_tag) {
                $full_text = trim($small_tag->textContent);

                // Try to extract date pattern from text that might have surrounding words
                // Pattern matches dates like "11th August 2023" or "Tuesday 15th August 2023 9:58 AM"
                if (preg_match('/(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)?\s*(\d{1,2}(?:st|nd|rd|th)?\s+(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{4}(?:\s+\d{1,2}:\d{2}\s*(?:AM|PM)?)?)/i', $full_text, $matches)) {
                    $date_text = $matches[1];
                } else {
                    $date_text = $full_text;
                }

                // Remove ordinal suffixes (st, nd, rd, th)
                $date_text = preg_replace('/(\d+)(st|nd|rd|th)/', '$1', $date_text);

                try {
                    $date_obj = new DateTime($date_text);
                    return $date_obj->format('Y-m-d H:i:s');
                } catch (Exception $e) {
                    // If this one fails, try the next <small> tag
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * Extract first image from <div class="page-content">
     *
     * @param string $html HTML content
     * @return string|null Image src URL or filename
     */
    private static function extract_first_image($html) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Look for the first img within page-content
        $images = $xpath->query('//div[@class="page-content"]//img');

        if ($images->length > 0) {
            $img_src = $images->item(0)->getAttribute('src');

            if (!empty($img_src)) {
                // Extract just the filename from the URL
                // Handle URLs like: ../../content/images/original/filename.jpeg%3Fv=4613
                // URL decode first
                $img_src = urldecode($img_src);

                // Extract filename from path
                $filename = basename($img_src);

                // Remove query string if present
                $filename = preg_replace('/\?.*$/', '', $filename);

                return $filename;
            }
        }

        return null;
    }

    /**
     * Validate HTML file
     *
     * @param array $file File array from $_FILES
     * @return bool|WP_Error True if valid, WP_Error otherwise
     */
    public static function validate_file($file) {
        // Check if file exists
        if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
            return new WP_Error('no_file', __('No file uploaded', 'html-page-importer'));
        }

        // Check file extension
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($file_ext, array('html', 'htm'))) {
            return new WP_Error('invalid_extension', __('File must be HTML (.html or .htm)', 'html-page-importer'));
        }

        // Check MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowed_mime_types = array('text/html', 'text/plain', 'application/octet-stream');
        if (!in_array($mime_type, $allowed_mime_types)) {
            return new WP_Error('invalid_mime', __('Invalid file type', 'html-page-importer'));
        }

        // Check file size (max 10MB)
        $max_size = 10 * 1024 * 1024; // 10MB
        if ($file['size'] > $max_size) {
            return new WP_Error('file_too_large', __('File size exceeds 10MB limit', 'html-page-importer'));
        }

        return true;
    }
}
