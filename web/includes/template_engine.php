<?php
/**
 * Safe Message Template Engine
 */

require_once __DIR__ . '/config.php';

class TemplateEngine {
    /**
     * Supported template placeholders
     */
    public const SUPPORTED_VARIABLES = ['name', 'phone', 'company'];

    /**
     * Render raw message text for sending via WhatsApp
     *
     * @param string $templateContent
     * @param array $contactData Keys: name, phone, company
     * @return string Plain text message with placeholders substituted
     */
    public static function render(string $templateContent, array $contactData): string {
        $name = trim($contactData['name'] ?? '');
        $phone = trim($contactData['phone'] ?? '');
        $company = trim($contactData['company'] ?? '');

        $replacements = [
            '{{name}}'    => $name,
            '{{phone}}'   => $phone,
            '{{company}}' => $company,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $templateContent);
    }

    /**
     * Render HTML safe preview
     *
     * @param string $templateContent
     * @param array $contactData
     * @return string HTML safe text with line breaks converted to <br>
     */
    public static function renderHtmlPreview(string $templateContent, array $contactData = []): string {
        $sampleData = array_merge([
            'name'    => 'John Doe',
            'phone'   => '60123456789',
            'company' => 'Acme Corporation'
        ], $contactData);

        $plain = self::render($templateContent, $sampleData);
        return nl2br(htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    /**
     * Detect unsupported variables in template
     *
     * @param string $content
     * @return array List of unsupported variables found, e.g. ['{{age}}']
     */
    public static function findUnsupportedVariables(string $content): array {
        preg_match_all('/\{\{([a-zA-Z0-9_-]+)\}\}/', $content, $matches);
        $found = array_unique($matches[1] ?? []);
        $unsupported = [];
        foreach ($found as $var) {
            if (!in_array($var, self::SUPPORTED_VARIABLES, true)) {
                $unsupported[] = '{{' . $var . '}}';
            }
        }
        return $unsupported;
    }
}
