<?php
// Prevent any output before JSON headers
ob_start();
error_reporting(0); // Suppress all PHP errors to prevent HTML output
ini_set('display_errors', 0);

// Function to safely output JSON and exit
function safe_json_response($data, $http_code = 200) {
    // Clean any previous output
    if (ob_get_level()) {
        ob_clean();
    }


        // Set headers
    http_response_code($http_code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST');
    header('Access-Control-Allow-Headers: Content-Type');
    
    // Output JSON and exit
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Error handling to ensure we always return JSON
set_error_handler(function($severity, $message, $file, $line) {
    safe_json_response(['error' => 'Service temporarily unavailable. Please try again later.'], 500);
});

// Exception handling
set_exception_handler(function($exception) {
    safe_json_response(['error' => 'Service temporarily unavailable. Please try again later.'], 500);
});

// Set initial headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Check if request method is valid
$request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Allow both POST and GET for InfinityFree compatibility
if ($request_method !== 'POST' && $request_method !== 'GET') {
    safe_json_response(['error' => 'Method not allowed'], 405);
}

// Get the input - handle both POST and GET for InfinityFree
$message = '';

if ($request_method === 'POST') {
    // Try to get POST data
    $post_data = file_get_contents('php://input');
    if ($post_data) {
        $input = json_decode($post_data, true);
        $message = $input['message'] ?? '';
    }
    
    // Fallback to $_POST if JSON parsing fails
    if (empty($message) && isset($_POST['message'])) {
        $message = $_POST['message'];
    }
} else {
    // GET method fallback for testing
    $message = $_GET['message'] ?? '';
}

// Security: Input validation and sanitization
if (empty($message)) {
    safe_json_response(['error' => 'Message is required'], 400);
}

// Security: Limit message length to prevent abuse
if (strlen($message) > 1000) {
    safe_json_response(['error' => 'Message too long. Please keep it under 1000 characters.'], 400);
}

// Security: Basic input sanitization
$message = trim($message);
$message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

// Security: Check for potential SQL injection patterns (even though we don't use DB)
$sql_patterns = [
    '/(\bSELECT\b|\bINSERT\b|\bUPDATE\b|\bDELETE\b|\bDROP\b|\bCREATE\b|\bALTER\b)/i',
    '/(\bUNION\b|\bOR\b\s+1\s*=\s*1|\bAND\b\s+1\s*=\s*1)/i',
    '/(\'|\"|;|--|\*|\/\*|\*\/)/i'
];

foreach ($sql_patterns as $pattern) {
    if (preg_match($pattern, $message)) {
        safe_json_response(['error' => 'Invalid input detected. Please ask a normal question about UniQR.'], 400);
    }
}

// Simplified rate limiting for InfinityFree (sessions may not work reliably)
$rate_limit_file = 'chatbot_rate_limit.txt';
$current_time = time();
$max_requests = 20; // Increased limit for InfinityFree
$time_window = 300; // 5 minutes

try {
    if (file_exists($rate_limit_file)) {
        $rate_data = json_decode(file_get_contents($rate_limit_file), true);
        if ($rate_data && isset($rate_data['time'], $rate_data['count'])) {
            $time_diff = $current_time - $rate_data['time'];
            
            if ($time_diff < $time_window) {
                if ($rate_data['count'] >= $max_requests) {
                    safe_json_response(['error' => 'Too many requests. Please wait a few minutes before asking another question.'], 429);
                }
                $rate_data['count']++;
            } else {
                $rate_data = ['time' => $current_time, 'count' => 1];
            }
        } else {
            $rate_data = ['time' => $current_time, 'count' => 1];
        }
    } else {
        $rate_data = ['time' => $current_time, 'count' => 1];
    }
    
    @file_put_contents($rate_limit_file, json_encode($rate_data));
} catch (Exception $e) {
    // Continue without rate limiting if file operations fail
}

// Include the knowledge base safely
if (file_exists('chatbot-knowledge.php')) {
    try {
        require_once 'chatbot-knowledge.php';
        
        // Get the comprehensive knowledge base from external file
        $system_context = getUniQRKnowledgeBase();
        
        // Add additional knowledge sections
        $system_context .= "\n\n" . getFrequentlyAskedQuestions();
        $system_context .= "\n\n" . getSystemAnnouncements();
    } catch (Exception $e) {
        // Fallback knowledge base if file fails
        $system_context = "You are UniQR Assistant, a helpful AI chatbot for the UniQR student attendance system at DEBESMSCAT. You help students with attendance tracking, sanctions, appeals, and general UniQR questions. For technical issues, direct users to contact the official UniQR Facebook page.";
    }
} else {
    // Fallback if knowledge file doesn't exist
    $system_context = "You are UniQR Assistant, a helpful AI chatbot for the UniQR student attendance system at DEBESMSCAT. You help students with attendance tracking, sanctions, appeals, and general UniQR questions. For technical issues, direct users to contact the official UniQR Facebook page.";
}

// Google Gemini API configuration
$api_key = 'AIzaSyARNzpx85N2Rm5fLPCdhG-xbl_WrJzx1GI';
$api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent';

// Prepare the request data
$data = [
    'contents' => [
        [
            'parts' => [
                [
                    'text' => $system_context . "\n\nUser question: " . $message
                ]
            ]
        ]
    ],
    'generationConfig' => [
        'temperature' => 0.7,
        'topK' => 40,
        'topP' => 0.95,
        'maxOutputTokens' => 1024,
    ],
    'safetySettings' => [
        [
            'category' => 'HARM_CATEGORY_HARASSMENT',
            'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
        ],
        [
            'category' => 'HARM_CATEGORY_HATE_SPEECH',
            'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
        ],
        [
            'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
            'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
        ],
        [
            'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
            'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
        ]
    ]
];

// Make the API request with InfinityFree-compatible settings
$ch = curl_init();
if ($ch === false) {
    safe_json_response(['error' => 'cURL not available. Please try again later.'], 500);
}

curl_setopt_array($ch, [
    CURLOPT_URL => $api_url,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-goog-api-key: ' . $api_key,
        'User-Agent: UniQR-Chatbot/1.0'
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// Handle cURL errors
if ($error) {
    safe_json_response(['error' => 'Unable to connect to AI service. Please try again later.'], 500);
}

// Handle HTTP errors
if ($http_code !== 200) {
    safe_json_response(['error' => 'AI service is temporarily unavailable. Please try again in a few moments.'], 500);
}

// Parse the response
$result = json_decode($response, true);

if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
    $ai_response = $result['candidates'][0]['content']['parts'][0]['text'];
    safe_json_response(['response' => $ai_response]);
} else {
    // Provide a fallback response if AI service fails
    $fallback_responses = [
        'hello' => 'Hello! I\'m your UniQR Assistant. How can I help you with your attendance, sanctions, or appeals today?',
        'help' => 'I can help you with UniQR questions about attendance tracking, sanctions, appeals, membership fees, and account settings. What would you like to know?',
        'attendance' => 'For attendance questions: Your attendance is recorded when officers scan your QR code during events. Check your "My Attendance" section in the student portal to view your records.',
        'sanctions' => 'Sanctions are automatically calculated based on absences. You can view and pay sanctions in the "My Sanctions" section of your student portal.',
        'appeals' => 'To submit an appeal for an absence, go to "My Appeals" in your student portal and provide a valid reason with supporting documentation.',
        'password' => 'To reset your password, use the "Forgot Password" link on the login page. If you don\'t have an email registered, contact our Facebook page.',
        'default' => 'I\'m having trouble connecting to the AI service right now. For immediate help, please contact the official UniQR Facebook page at facebook.com/uniqrofficial or check the FAQ section on the website.'
    ];
    
    $message_lower = strtolower($message);
    $response_text = $fallback_responses['default'];
    
    foreach ($fallback_responses as $key => $fallback) {
        if ($key !== 'default' && strpos($message_lower, $key) !== false) {
            $response_text = $fallback;
            break;
        }
    }
    
    safe_json_response(['response' => $response_text]);
}
?>
