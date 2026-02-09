<?php
/**
 * Grammar Checker API - Enhanced Version
 * 
 * Features:
 * - Multilingual support with auto-detection
 * - Style and tone preservation
 * - Grammar rule explanations with examples
 * - Writing style customization
 * - Tone adjustment
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/php-error.log');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit();
}

// ========================================
// CONFIGURATION
// ========================================
define('OPENAI_API_KEY', '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'); // *** REPLACE WITH YOUR API KEY ***
define('OPENAI_API_URL', 'https://api.openai.com/v1/chat/completions');
define('MODEL', 'gpt-4o-mini');
define('MAX_TOKENS', 3000);
define('TEMPERATURE', 0.3);

// Language names for display
$LANGUAGE_NAMES = [
    'en' => 'English',
    'es' => 'Spanish',
    'fr' => 'French',
    'de' => 'German',
    'it' => 'Italian',
    'pt' => 'Portuguese',
    'nl' => 'Dutch',
    'pl' => 'Polish',
    'ru' => 'Russian',
    'zh' => 'Chinese',
    'ja' => 'Japanese',
    'ko' => 'Korean',
    'ar' => 'Arabic'
];

// ========================================
// MAIN EXECUTION
// ========================================
try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Check if this is a rule request
    if (isset($input['action']) && $input['action'] === 'get_rule') {
        $rule = getGrammarRule($input['correction']);
        echo json_encode([
            'success' => true,
            'rule' => $rule
        ], JSON_PRETTY_PRINT);
        exit();
    }
    
    // Normal grammar check request
    if (!isset($input['text']) || empty(trim($input['text']))) {
        throw new Exception('No text provided');
    }
    
    $text = trim($input['text']);
    $language = isset($input['language']) ? $input['language'] : 'auto';
    $style = isset($input['style']) ? $input['style'] : 'neutral';
    $tone = isset($input['tone']) ? $input['tone'] : 'preserve';
    
    if (strlen($text) > 10000) {
        throw new Exception('Text is too long. Maximum 10,000 characters.');
    }
    
    if (OPENAI_API_KEY === 'YOUR_OPENAI_API_KEY') {
        throw new Exception('OpenAI API key not configured. Please update the API file with your key.');
    }
    
    // Analyze grammar with enhanced features
    $result = analyzeGrammarEnhanced($text, $language, $style, $tone);
    
    echo json_encode([
        'success' => true,
        'corrections' => $result['corrections'],
        'detected_language' => $result['detected_language'],
        'text_length' => strlen($text),
        'issues_found' => count($result['corrections']),
        'model_used' => MODEL
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'success' => false
    ]);
    error_log('Grammar API Error: ' . $e->getMessage());
}

// ========================================
// ENHANCED GRAMMAR ANALYSIS
// ========================================

function analyzeGrammarEnhanced($text, $language, $style, $tone) {
    global $LANGUAGE_NAMES;
    
    // Build the system prompt with all parameters
    $systemPrompt = buildEnhancedSystemPrompt($language, $style, $tone);
    $userPrompt = "Please analyze this text:\n\n" . $text;
    
    $requestData = [
        'model' => MODEL,
        'messages' => [
            [
                'role' => 'system',
                'content' => $systemPrompt
            ],
            [
                'role' => 'user',
                'content' => $userPrompt
            ]
        ],
        'temperature' => TEMPERATURE,
        'max_tokens' => MAX_TOKENS
    ];
    
    $ch = curl_init(OPENAI_API_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_API_KEY
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    
    curl_close($ch);
    
    if ($curlError) {
        throw new Exception('cURL error: ' . $curlError);
    }
    
    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMessage = 'OpenAI API error (HTTP ' . $httpCode . ')';
        
        if (isset($errorData['error']['message'])) {
            $errorMessage = $errorData['error']['message'];
        }
        
        throw new Exception($errorMessage);
    }
    
    $result = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Failed to parse API response: ' . json_last_error_msg());
    }
    
    if (!isset($result['choices'][0]['message']['content'])) {
        throw new Exception('Invalid API response format');
    }
    
    $content = trim($result['choices'][0]['message']['content']);
    
    // Clean markdown
    $content = preg_replace('/^```json\s*/m', '', $content);
    $content = preg_replace('/^```\s*/m', '', $content);
    $content = preg_replace('/\s*```$/m', '', $content);
    $content = trim($content);
    
    $data = json_decode($content, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('JSON Parse Error: ' . json_last_error_msg());
        error_log('Content: ' . substr($content, 0, 500));
        throw new Exception('Failed to parse AI response');
    }
    
    if (!is_array($data)) {
        throw new Exception('Invalid response format');
    }
    
    // Extract corrections and detected language
    $corrections = isset($data['corrections']) ? $data['corrections'] : $data;
    $detectedLang = isset($data['detected_language']) ? $data['detected_language'] : 'en';
    
    // Validate corrections
    $validCorrections = validateCorrections($corrections, $text);
    
    return [
        'corrections' => $validCorrections,
        'detected_language' => $detectedLang
    ];
}

// ========================================
// BUILD ENHANCED SYSTEM PROMPT
// ========================================

function buildEnhancedSystemPrompt($language, $style, $tone) {
    $languageInstruction = '';
    if ($language === 'auto') {
        $languageInstruction = 'FIRST detect the language of the text and include it in your response as "detected_language". Then analyze the text in that language.';
    } else {
        $languageInstruction = "The text is in language code: $language. Analyze it accordingly.";
    }
    
    $styleInstruction = getStyleInstruction($style);
    $toneInstruction = getToneInstruction($tone);
    
    $prompt = "You are a professional multilingual grammar and style checker.

LANGUAGE HANDLING:
$languageInstruction

WRITING STYLE:
$styleInstruction

TONE ADJUSTMENT:
$toneInstruction

CRITICAL INSTRUCTIONS:
- Preserve the author's original voice, personality, and writing style
- Do NOT make corrections sound robotic, generic, or overly formal
- Maintain natural flow and authenticity
- Corrections should improve clarity without changing the writer's character
- Respect cultural and linguistic nuances

For EACH error you find, provide:
1. original: The exact text with the error (as it appears in input)
2. correction: The corrected version (preserving style and tone)
3. explanation: Brief, clear explanation
4. position: Character position where error starts (0-based)
5. rule_name: Short name of the grammar rule (e.g., 'Subject-Verb Agreement', 'Apostrophe Usage')

Return ONLY valid JSON in this format:
{
  \"detected_language\": \"en\",
  \"corrections\": [
    {
      \"original\": \"error text\",
      \"correction\": \"fixed text\",
      \"explanation\": \"why it's wrong\",
      \"position\": 0,
      \"rule_name\": \"Grammar Rule Name\"
    }
  ]
}

If no errors found, return: {\"detected_language\": \"en\", \"corrections\": []}

DO NOT include markdown, preambles, or any text outside the JSON.";

    return $prompt;
}

function getStyleInstruction($style) {
    $styles = [
        'neutral' => 'Maintain a balanced, natural tone suitable for general writing.',
        'formal' => 'Ensure corrections follow formal writing conventions, suitable for business or academic contexts.',
        'casual' => 'Keep corrections conversational and relaxed, as you would speak to a friend.',
        'academic' => 'Apply academic writing standards with precision and scholarly tone.',
        'creative' => 'Preserve creative expression and artistic language choices.',
        'professional' => 'Maintain professional business communication standards.',
        'conversational' => 'Keep the natural flow of spoken language and personal voice.'
    ];
    
    return isset($styles[$style]) ? $styles[$style] : $styles['neutral'];
}

function getToneInstruction($tone) {
    $tones = [
        'preserve' => 'Preserve the original tone exactly as written. Do not alter the author\'s voice.',
        'natural' => 'Improve flow to sound more natural and conversational, but avoid making it artificial or stiff.',
        'confident' => 'Strengthen weak phrasings to sound more assertive and confident.',
        'friendly' => 'Warm up the tone slightly to sound more approachable and friendly.',
        'concise' => 'Tighten wordy phrases to be more direct and concise.'
    ];
    
    return isset($tones[$tone]) ? $tones[$tone] : $tones['preserve'];
}

// ========================================
// VALIDATE CORRECTIONS
// ========================================

function validateCorrections($corrections, $text) {
    if (!is_array($corrections)) {
        return [];
    }
    
    $validCorrections = [];
    
    foreach ($corrections as $correction) {
        if (!isset($correction['original']) || 
            !isset($correction['correction']) || 
            !isset($correction['explanation']) || 
            !isset($correction['position'])) {
            continue;
        }
        
        $pos = (int)$correction['position'];
        $original = $correction['original'];
        $originalLength = strlen($original);
        
        if ($pos < 0 || $pos >= strlen($text)) {
            continue;
        }
        
        $actualText = substr($text, $pos, $originalLength);
        
        if ($actualText === $original) {
            $validCorrections[] = [
                'original' => $original,
                'correction' => $correction['correction'],
                'explanation' => $correction['explanation'],
                'position' => $pos,
                'rule_name' => isset($correction['rule_name']) ? $correction['rule_name'] : 'Grammar Error',
                'ignored' => false
            ];
        } elseif (strcasecmp($actualText, $original) === 0) {
            $validCorrections[] = [
                'original' => $actualText,
                'correction' => $correction['correction'],
                'explanation' => $correction['explanation'],
                'position' => $pos,
                'rule_name' => isset($correction['rule_name']) ? $correction['rule_name'] : 'Grammar Error',
                'ignored' => false
            ];
        } else {
            $foundPos = strpos($text, $original, max(0, $pos - 50));
            if ($foundPos !== false) {
                $validCorrections[] = [
                    'original' => $original,
                    'correction' => $correction['correction'],
                    'explanation' => $correction['explanation'],
                    'position' => $foundPos,
                    'rule_name' => isset($correction['rule_name']) ? $correction['rule_name'] : 'Grammar Error',
                    'ignored' => false
                ];
            } else {
                $foundPos = stripos($text, $original);
                if ($foundPos !== false) {
                    $actualText = substr($text, $foundPos, $originalLength);
                    $validCorrections[] = [
                        'original' => $actualText,
                        'correction' => $correction['correction'],
                        'explanation' => $correction['explanation'],
                        'position' => $foundPos,
                        'rule_name' => isset($correction['rule_name']) ? $correction['rule_name'] : 'Grammar Error',
                        'ignored' => false
                    ];
                }
            }
        }
    }
    
    usort($validCorrections, function($a, $b) {
        return $a['position'] - $b['position'];
    });
    
    $deduplicated = [];
    $seen = [];
    
    foreach ($validCorrections as $correction) {
        $key = $correction['position'] . '|' . $correction['original'];
        if (!isset($seen[$key])) {
            $deduplicated[] = $correction;
            $seen[$key] = true;
        }
    }
    
    return $deduplicated;
}

// ========================================
// GET DETAILED GRAMMAR RULE
// ========================================

function getGrammarRule($correction) {
    $systemPrompt = "You are a grammar teacher. Provide a detailed explanation of a grammar rule with examples.

The user made this error:
- Original: {$correction['original']}
- Correction: {$correction['correction']}
- Brief explanation: {$correction['explanation']}
- Rule name: {$correction['rule_name']}

Provide:
1. A detailed explanation of WHY this is an error and the rule involved
2. 3-4 CORRECT examples demonstrating proper usage
3. 3-4 INCORRECT examples showing common mistakes
4. A simple multiple-choice quiz question to test understanding

Return ONLY valid JSON in this format:
{
  \"explanation\": \"Detailed explanation of the rule...\",
  \"correct_examples\": [
    \"First correct example sentence.\",
    \"Second correct example sentence.\",
    \"Third correct example sentence.\"
  ],
  \"incorrect_examples\": [
    \"First incorrect example sentence.\",
    \"Second incorrect example sentence.\",
    \"Third incorrect example sentence.\"
  ],
  \"quiz\": {
    \"question\": \"Which sentence is correct?\",
    \"options\": [
      \"Option A text\",
      \"Option B text\",
      \"Option C text\",
      \"Option D text\"
    ],
    \"correct\": 0
  }
}

DO NOT include markdown, explanations, or any text outside the JSON.";

    $requestData = [
        'model' => MODEL,
        'messages' => [
            [
                'role' => 'system',
                'content' => $systemPrompt
            ],
            [
                'role' => 'user',
                'content' => 'Explain this grammar rule.'
            ]
        ],
        'temperature' => 0.4,
        'max_tokens' => 1500
    ];
    
    $ch = curl_init(OPENAI_API_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_API_KEY
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception('Could not fetch rule details');
    }
    
    $result = json_decode($response, true);
    
    if (!isset($result['choices'][0]['message']['content'])) {
        throw new Exception('Invalid response');
    }
    
    $content = trim($result['choices'][0]['message']['content']);
    
    // Clean markdown
    $content = preg_replace('/^```json\s*/m', '', $content);
    $content = preg_replace('/^```\s*/m', '', $content);
    $content = preg_replace('/\s*```$/m', '', $content);
    $content = trim($content);
    
    $rule = json_decode($content, true);
    
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($rule)) {
        // Fallback if parsing fails
        return [
            'explanation' => $correction['explanation'],
            'correct_examples' => [
                'Example 1: ' . $correction['correction'],
                'Example 2: Similar correct usage.',
                'Example 3: Another correct usage.'
            ],
            'incorrect_examples' => [
                'Example 1: ' . $correction['original'],
                'Example 2: Similar incorrect usage.',
                'Example 3: Another incorrect usage.'
            ],
            'quiz' => null
        ];
    }
    
    return $rule;
}