<?php
/**
 * Input Validation and Sanitization
 */

class Validator {
    private array $errors = [];
    
    /**
     * Validate username
     */
    public function validateUsername(string $username): bool {
        if (strlen($username) < 3 || strlen($username) > 50) {
            $this->addError('username', 'Username must be 3-50 characters');
            return false;
        }
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
            $this->addError('username', 'Username can only contain alphanumerics, dots, dashes, underscores');
            return false;
        }
        return true;
    }
    
    /**
     * Validate email
     */
    public function validateEmail(string $email): bool {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addError('email', 'Invalid email address');
            return false;
        }
        if (strlen($email) > 120) {
            $this->addError('email', 'Email address too long');
            return false;
        }
        return true;
    }
    
    /**
     * Validate password
     */
    public function validatePassword(string $password, bool $require_strong = true): bool {
        if (strlen($password) < 8) {
            $this->addError('password', 'Password must be at least 8 characters');
            return false;
        }
        
        if ($require_strong) {
            $has_upper = preg_match('/[A-Z]/', $password);
            $has_lower = preg_match('/[a-z]/', $password);
            $has_digit = preg_match('/\d/', $password);
            $has_special = preg_match('/[!@#$%^&*]/', $password);
            
            if (!($has_upper && $has_lower && $has_digit && $has_special)) {
                $this->addError('password', 
                    'Password must contain uppercase, lowercase, digit, and special character');
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Validate phone number
     */
    public function validatePhoneNumber(string $phone): bool {
        // Remove common separators
        $phone = preg_replace('/[\s\-\(\)\.]+/', '', $phone);
        
        // Must start with + or digit
        if (!preg_match('/^\+?[0-9]{7,15}$/', $phone)) {
            $this->addError('phone', 'Invalid phone number format');
            return false;
        }
        
        return true;
    }
    
    /**
     * Normalize phone number
     */
    public function normalizePhoneNumber(string $phone, string $default_country_code = DEFAULT_COUNTRY_CODE): string {
        // Remove non-numeric characters except +
        $phone = preg_replace('/[^\d+]/', '', $phone);
        
        // If starts with 0, replace with country code
        if (strpos($phone, '0') === 0) {
            $phone = $default_country_code . substr($phone, 1);
        }
        
        // If no +, add it
        if (strpos($phone, '+') !== 0) {
            // If starts with country code without +, add +
            $cc = ltrim($default_country_code, '+');
            if (strpos($phone, $cc) === 0) {
                $phone = '+' . $phone;
            } else {
                $phone = $default_country_code . $phone;
            }
        }
        
        return $phone;
    }
    
    /**
     * Validate contact name
     */
    public function validateContactName(string $name): bool {
        if (strlen($name) < 2 || strlen($name) > 100) {
            $this->addError('name', 'Name must be 2-100 characters');
            return false;
        }
        return true;
    }
    
    /**
     * Validate campaign name
     */
    public function validateCampaignName(string $name): bool {
        if (strlen($name) < 3 || strlen($name) > 150) {
            $this->addError('name', 'Campaign name must be 3-150 characters');
            return false;
        }
        return true;
    }
    
    /**
     * Validate message content
     */
    public function validateMessageContent(string $content): bool {
        if (strlen($content) < 1) {
            $this->addError('message', 'Message cannot be empty');
            return false;
        }
        if (strlen($content) > 65535) {
            $this->addError('message', 'Message too long');
            return false;
        }
        return true;
    }
    
    /**
     * Validate scheduled date
     */
    public function validateScheduledDate(string $date): bool {
        $timestamp = strtotime($date);
        if ($timestamp === false || $timestamp === -1) {
            $this->addError('scheduled_at', 'Invalid date format');
            return false;
        }
        if ($timestamp <= time()) {
            $this->addError('scheduled_at', 'Scheduled date must be in the future');
            return false;
        }
        return true;
    }
    
    /**
     * Validate media file upload
     */
    public function validateMediaUpload(array $file): bool {
        // Check upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->addError('file', $this->getUploadErrorMessage($file['error']));
            return false;
        }
        
        // Check file size
        if ($file['size'] > MAX_UPLOAD_SIZE) {
            $this->addError('file', 'File size exceeds maximum allowed size');
            return false;
        }
        
        // Check MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mime, ALLOWED_MIME_TYPES)) {
            $this->addError('file', 'File type not allowed. Allowed types: ' . implode(', ', ALLOWED_MIME_TYPES));
            return false;
        }
        
        // Check file extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ALLOWED_EXTENSIONS)) {
            $this->addError('file', 'File extension not allowed');
            return false;
        }
        
        return true;
    }
    
    /**
     * Get upload error message
     */
    private function getUploadErrorMessage(int $error): string {
        return match($error) {
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temp directory',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
            UPLOAD_ERR_EXTENSION => 'Extension stopped upload',
            default => 'Unknown upload error'
        };
    }
    
    /**
     * Add validation error
     */
    public function addError(string $field, string $message): void {
        $this->errors[$field] = $message;
    }
    
    /**
     * Get all errors
     */
    public function getErrors(): array {
        return $this->errors;
    }
    
    /**
     * Check if validation passed
     */
    public function passed(): bool {
        return empty($this->errors);
    }
    
    /**
     * Check if validation failed
     */
    public function failed(): bool {
        return !$this->passed();
    }
}

/**
 * Response Helper
 */
class Response {
    /**
     * Send JSON response
     */
    public static function json(bool $success, string $message = '', $data = null, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data
        ]);
        exit;
    }
    
    /**
     * Send success response
     */
    public static function success($data = null, string $message = 'Success', int $status = 200): void {
        self::json(true, $message, $data, $status);
    }
    
    /**
     * Send error response
     */
    public static function error(string $message, int $status = 400, $data = null): void {
        self::json(false, $message, $data, $status);
    }
    
    /**
     * Send validation error response
     */
    public static function validationError(array $errors, string $message = 'Validation failed'): void {
        self::json(false, $message, $errors, 422);
    }
    
    /**
     * Send unauthorized response
     */
    public static function unauthorized(string $message = 'Unauthorized'): void {
        self::json(false, $message, null, 401);
    }
    
    /**
     * Send forbidden response
     */
    public static function forbidden(string $message = 'Forbidden'): void {
        self::json(false, $message, null, 403);
    }
    
    /**
     * Send not found response
     */
    public static function notFound(string $message = 'Not found'): void {
        self::json(false, $message, null, 404);
    }
}

/**
 * Sanitize helper
 */
function sanitize_input(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Get request input
 */
function get_input(string $key, $default = null) {
    return $_REQUEST[$key] ?? $default;
}

/**
 * Get request method
 */
function request_method(): string {
    return strtoupper($_SERVER['REQUEST_METHOD']);
}

/**
 * Check if POST request
 */
function is_post(): bool {
    return request_method() === 'POST';
}

/**
 * Check if AJAX request
 */
function is_ajax(): bool {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}
