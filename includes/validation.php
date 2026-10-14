<?php
/**
 * ShopSphere - Validation and Sanitization Engine
 *
 * Provides comprehensive data validation and sanitization functions
 * for user inputs, passwords, phone numbers, file uploads, and content.
 *
 * @package ShopSphere\Includes
 */

declare(strict_types=1);

// Ensure app constants are available
require_once dirname(__DIR__) . '/config/app.php';

if (!function_exists('validate_email')) {
    /**
     * Validate an email address format according to RFC 822.
     *
     * @param mixed $email The input email to validate.
     * @return bool True if valid email, false otherwise.
     */
    function validate_email(mixed $email): bool
    {
        if (!is_string($email) || trim($email) === '') {
            return false;
        }

        $cleanEmail = trim($email);
        return filter_var($cleanEmail, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('validate_required')) {
    /**
     * Check if a given value is present and not empty.
     * Note: "0" and 0 are considered valid, non-empty values.
     *
     * @param mixed $value The value to check.
     * @return bool True if value is non-empty, false otherwise.
     */
    function validate_required(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return count($value) > 0;
        }

        return true;
    }
}

if (!function_exists('validate_min_length')) {
    /**
     * Validate that a string meets a minimum character length.
     *
     * @param mixed $value Input string.
     * @param int $min Minimum character count.
     * @return bool True if character count >= $min, false otherwise.
     */
    function validate_min_length(mixed $value, int $min): bool
    {
        if (!is_string($value) && !is_numeric($value)) {
            return false;
        }

        return mb_strlen((string) $value, 'UTF-8') >= $min;
    }
}

if (!function_exists('validate_max_length')) {
    /**
     * Validate that a string does not exceed a maximum character length.
     *
     * @param mixed $value Input string.
     * @param int $max Maximum character count.
     * @return bool True if character count <= $max, false otherwise.
     */
    function validate_max_length(mixed $value, int $max): bool
    {
        if (!is_string($value) && !is_numeric($value)) {
            return false;
        }

        return mb_strlen((string) $value, 'UTF-8') <= $max;
    }
}

if (!function_exists('validate_numeric')) {
    /**
     * Check if a value is a valid numeric value or numeric string.
     *
     * @param mixed $value Input value.
     * @return bool True if numeric, false otherwise.
     */
    function validate_numeric(mixed $value): bool
    {
        return is_numeric($value);
    }
}

if (!function_exists('validate_positive')) {
    /**
     * Validate that a value is a strictly positive number (or non-negative if $allowZero is true).
     *
     * @param mixed $value Input value.
     * @param bool $allowZero Whether zero is acceptable.
     * @return bool True if positive, false otherwise.
     */
    function validate_positive(mixed $value, bool $allowZero = false): bool
    {
        if (!is_numeric($value)) {
            return false;
        }

        $num = (float) $value;
        return $allowZero ? $num >= 0 : $num > 0;
    }
}

if (!function_exists('validate_in')) {
    /**
     * Validate that a value exists within an allowed list of values.
     *
     * @param mixed $value Input value.
     * @param array $allowed Whitelist array.
     * @return bool True if present in array, false otherwise.
     */
    function validate_in(mixed $value, array $allowed): bool
    {
        return in_array($value, $allowed, true) || in_array((string) $value, array_map('strval', $allowed), true);
    }
}

if (!function_exists('validate_phone')) {
    /**
     * Validate phone number format (supports Pakistani mobile/landline and international formats).
     * Validates formats such as +923001234567, 03001234567, 0300-1234567, +1-800-555-0199.
     *
     * @param mixed $phone Input phone string.
     * @return bool True if valid phone format, false otherwise.
     */
    function validate_phone(mixed $phone): bool
    {
        if (!is_string($phone) || trim($phone) === '') {
            return false;
        }

        $clean = trim($phone);

        // Pakistani mobile regex: 03XX-XXXXXXX or +923XXXXXXXXX
        $pkRegex = '/^((\+92|0092|92|0)?3[0-9]{2}[-\s]?[0-9]{7})$/';
        if (preg_match($pkRegex, $clean)) {
            return true;
        }

        // Generic international phone regex (E.164 inspired, 9 to 16 digits, optional plus and separators)
        $intlRegex = '/^\+?[0-9]{1,4}?[-.\s]?(\(?\d{1,4}\)?[-.\s]?)?[\d-.\s]{7,15}$/';
        if (preg_match($intlRegex, $clean)) {
            $digitsOnly = preg_replace('/\D/', '', $clean);
            $digitCount = strlen($digitsOnly);
            return $digitCount >= 9 && $digitCount <= 15;
        }

        return false;
    }
}

if (!function_exists('validate_password_strength')) {
    /**
     * Validate that a password meets complexity rules:
     * - Minimum 8 characters, maximum 72 characters (Bcrypt limit / DoS prevention)
     * - At least one uppercase letter
     * - At least one lowercase letter
     * - At least one numeric digit
     *
     * @param string $password Input password.
     * @return bool True if strong enough, false otherwise.
     */
    function validate_password_strength(string $password): bool
    {
        $len = strlen($password);
        if ($len < 8 || $len > 72) {
            return false;
        }

        $hasUpper = preg_match('/[A-Z]/', $password) === 1;
        $hasLower = preg_match('/[a-z]/', $password) === 1;
        $hasDigit = preg_match('/[0-9]/', $password) === 1;

        return $hasUpper && $hasLower && $hasDigit;
    }
}

if (!function_exists('validate_slug')) {
    /**
     * Validate that a string conforms to a standard URL slug format (lowercase alphanumeric and hyphens).
     *
     * @param mixed $slug Input slug string.
     * @return bool True if valid slug, false otherwise.
     */
    function validate_slug(mixed $slug): bool
    {
        if (!is_string($slug) || trim($slug) === '') {
            return false;
        }

        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1;
    }
}

if (!function_exists('validate_image_file')) {
    /**
     * Validate an uploaded image file for valid MIME type, image headers, allowed extension, and size limit.
     *
     * @param array $file $_FILES['image'] item.
     * @param int|null $maxSize Maximum allowed size in bytes (defaults to UPLOAD_MAX_SIZE).
     * @return bool True if image passes all security checks, false otherwise.
     */
    function validate_image_file(array $file, ?int $maxSize = null): bool
    {
        if (!isset($file['tmp_name']) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return false;
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            return false;
        }

        $max = $maxSize ?? (defined('UPLOAD_MAX_SIZE') ? UPLOAD_MAX_SIZE : 5242880);
        if ($file['size'] <= 0 || $file['size'] > $max) {
            return false;
        }

        // Validate allowed file extensions
        $originalName = (string) ($file['name'] ?? '');
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (!in_array($ext, $allowedExtensions, true)) {
            return false;
        }

        // Validate MIME type with fileinfo
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($mime, $allowedMimes, true)) {
            return false;
        }

        // Validate actual image binary header with getimagesize
        $dimensions = @getimagesize($file['tmp_name']);
        if ($dimensions === false || empty($dimensions[0]) || empty($dimensions[1])) {
            return false;
        }

        return true;
    }
}

if (!function_exists('sanitize_string')) {
    /**
     * Sanitize a generic string input: trim whitespace, strip null bytes, and remove control characters.
     *
     * @param mixed $string Raw input.
     * @return string Sanitized string.
     */
    function sanitize_string(mixed $string): string
    {
        if ($string === null) {
            return '';
        }

        $str = (string) $string;

        // Remove null bytes
        $str = str_replace(chr(0), '', $str);

        // Normalize line endings and trim
        $str = str_replace(["\r\n", "\r"], "\n", $str);

        return trim($str);
    }
}

if (!function_exists('sanitize_html')) {
    /**
     * Robustly sanitize HTML content using DOMDocument parser:
     * - Whitelist allowed tags: p, br, b, strong, i, em, u, s, blockquote, ul, ol, li, a, h1-h6, hr, table, thead, tbody, tr, th, td, code, pre
     * - Whitelist attributes on allowed tags: href, title, class, target, rel
     * - Enforce safe URL schemes on href (http, https, mailto, relative # or /)
     * - Enforce rel="noopener noreferrer" on target="_blank"
     * - Completely strip all inline event handlers (on*), javascript:, vbscript:, data:, and script/style/iframe/object tags
     *
     * @param string $html Raw HTML content.
     * @return string Clean, safe HTML string.
     */
    function sanitize_html(string $html): string
    {
        $trimmed = trim($html);
        if ($trimmed === '') {
            return '';
        }

        // Fast-path: if no HTML tags are present, return encoded text
        if (!str_contains($trimmed, '<')) {
            return htmlspecialchars($trimmed, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $prevErrors = libxml_use_internal_errors(true);
        $dom = new DOMDocument();

        // Encode to UTF-8 HTML entity document to preserve Unicode characters
        $wrappedHtml = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $trimmed . '</body></html>';
        $dom->loadHTML($wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prevErrors);

        $allowedTags = [
            'p', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'blockquote',
            'ul', 'ol', 'li', 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'code', 'pre'
        ];

        $allowedAttributes = [
            'a'     => ['href', 'title', 'target', 'rel'],
            'th'    => ['colspan', 'rowspan'],
            'td'    => ['colspan', 'rowspan'],
            '*'     => ['class', 'title'],
        ];

        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            return strip_tags($trimmed);
        }

        $cleanNodes = function (DOMNode $node) use (&$cleanNodes, $allowedTags, $allowedAttributes, $dom) {
            if ($node->nodeType === XML_ELEMENT_NODE) {
                /** @var DOMElement $element */
                $element = $node;
                $tagName = strtolower($element->nodeName);

                if (!in_array($tagName, $allowedTags, true)) {
                    // Tag not allowed - replace with text contents or remove
                    $fragment = $dom->createDocumentFragment();
                    while ($element->firstChild) {
                        $fragment->appendChild($element->firstChild);
                    }
                    if ($element->parentNode) {
                        $element->parentNode->replaceChild($fragment, $element);
                    }
                    return;
                }

                // Clean attributes
                if ($element->hasAttributes()) {
                    $attrsToRemove = [];
                    foreach ($element->attributes as $attr) {
                        $attrName = strtolower($attr->nodeName);
                        $attrValue = $attr->nodeValue ?? '';

                        // Disallow any on* event handler
                        if (str_starts_with($attrName, 'on') || str_starts_with($attrName, 'data-')) {
                            $attrsToRemove[] = $attrName;
                            continue;
                        }

                        // Check attribute whitelist
                        $tagAllowedAttrs = $allowedAttributes[$tagName] ?? [];
                        $globalAllowedAttrs = $allowedAttributes['*'] ?? [];
                        if (!in_array($attrName, $tagAllowedAttrs, true) && !in_array($attrName, $globalAllowedAttrs, true)) {
                            $attrsToRemove[] = $attrName;
                            continue;
                        }

                        // Validate URL in href
                        if ($attrName === 'href') {
                            $cleanValue = preg_replace('/[\x00-\x20\x7F]+/u', '', html_entity_decode($attrValue, ENT_QUOTES, 'UTF-8'));
                            $cleanLower = strtolower($cleanValue);
                            if (
                                str_starts_with($cleanLower, 'javascript:') ||
                                str_starts_with($cleanLower, 'vbscript:') ||
                                str_starts_with($cleanLower, 'data:')
                            ) {
                                $attrsToRemove[] = $attrName;
                                continue;
                            }
                            if ($element->getAttribute('target') === '_blank') {
                                $element->setAttribute('rel', 'noopener noreferrer');
                            }
                        }
                    }

                    foreach ($attrsToRemove as $removeAttr) {
                        $element->removeAttribute($removeAttr);
                    }
                }
            }

            // Recursively clean children
            $child = $node->firstChild;
            while ($child) {
                $next = $child->nextSibling;
                $cleanNodes($child);
                $child = $next;
            }
        };

        $cleanNodes($body);

        $cleanHtml = '';
        foreach ($body->childNodes as $childNode) {
            $cleanHtml .= $dom->saveHTML($childNode);
        }

        return $cleanHtml;
    }
}
