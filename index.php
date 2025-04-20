<?php
/**
 * Professional Telegram Bot
 * 
 * This bot uses webhooks for efficient message handling and Docker for deployment
 * on render.com web services.
 * 
 * @version 1.0
 */

// Bot configuration
define('BOT_TOKEN', 'Place_Your_Token_Here');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('USERS_FILE', 'users.json');
define('ERROR_LOG', 'error.log');
define('WEBHOOK_SECRET', 'your_secret_token'); // Use this for additional security

// Error logging with enhanced details
function logError($message, $context = []) {
    $timestamp = date('Y-m-d H:i:s');
    $contextData = !empty($context) ? ' | Context: ' . json_encode($context) : '';
    file_put_contents(ERROR_LOG, "[$timestamp] $message$contextData\n", FILE_APPEND);
}

// Data management with error handling
function loadUsers() {
    try {
        if (!file_exists(USERS_FILE)) {
            // Create with proper permissions for Docker environment
            file_put_contents(USERS_FILE, json_encode([], JSON_PRETTY_PRINT));
            chmod(USERS_FILE, 0666); // Ensure writeable in container
        }
        $data = file_get_contents(USERS_FILE);
        if (empty($data)) {
            return [];
        }
        $users = json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("JSON decode error: " . json_last_error_msg());
        }
        return $users ?: [];
    } catch (Exception $e) {
        logError("Load users failed: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        return [];
    }
}

function saveUsers($users) {
    try {
        $data = json_encode($users, JSON_PRETTY_PRINT);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("JSON encode error: " . json_last_error_msg());
        }
        $bytes = file_put_contents(USERS_FILE, $data);
        if ($bytes === false) {
            throw new Exception("Could not write to users file");
        }
        return true;
    } catch (Exception $e) {
        logError("Save users failed: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        return false;
    }
}

// Setup webhook for Telegram
function setupWebhook($url) {
    try {
        // Delete any existing webhook first
        file_get_contents(API_URL . 'deleteWebhook');
        
        // Set new webhook
        $webhook_url = $url . (strpos($url, '?') === false ? '?' : '&') . 'token=' . WEBHOOK_SECRET;
        $response = file_get_contents(API_URL . 'setWebhook?url=' . urlencode($webhook_url));
        $result = json_decode($response, true);
        
        if (!$result['ok']) {
            logError("Webhook setup failed", ['response' => $response]);
            return false;
        }
        
        return true;
    } catch (Exception $e) {
        logError("Webhook initialization failed: " . $e->getMessage());
        return false;
    }
}

// Enhanced message sending with retry logic
function sendMessage($chat_id, $text, $keyboard = null, $retry = 2) {
    try {
        $params = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        
        if ($keyboard) {
            $params['reply_markup'] = json_encode([
                'inline_keyboard' => $keyboard
            ]);
        }
        
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode($params),
                'timeout' => 10
            ]
        ];
        
        $context = stream_context_create($options);
        $response = file_get_contents(API_URL . 'sendMessage', false, $context);
        $result = json_decode($response, true);
        
        if (!$result['ok']) {
            throw new Exception("Telegram API error: " . ($result['description'] ?? 'Unknown error'));
        }
        
        return true;
    } catch (Exception $e) {
        logError("Send message failed: " . $e->getMessage(), ['chat_id' => $chat_id]);
        
        // Retry logic
        if ($retry > 0) {
            sleep(1);
            return sendMessage($chat_id, $text, $keyboard, $retry - 1);
        }
        
        return false;
    }
}

// Main keyboard with improved styling
function getMainKeyboard() {
    return [
        [['text' => '💰 Earn Points', 'callback_data' => 'earn'], ['text' => '💳 My Balance', 'callback_data' => 'balance']],
        [['text' => '🏆 Leaderboard', 'callback_data' => 'leaderboard'], ['text' => '👥 My Referrals', 'callback_data' => 'referrals']],
        [['text' => '🏧 Withdraw', 'callback_data' => 'withdraw'], ['text' => '❓ Help Center', 'callback_data' => 'help']]
    ];
}

// Process commands and callbacks with improved structure
function processUpdate($update) {
    $users = loadUsers();
    
    if (isset($update['message'])) {
        $chat_id = $update['message']['chat']['id'];
        $text = trim($update['message']['text'] ?? '');
        $first_name = $update['message']['from']['first_name'] ?? 'User';
        
        // Create new user if doesn't exist
        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . time()), 0, 8),
                'referred_by' => null,
                'name' => $first_name,
                'joined' => date('Y-m-d H:i:s')
            ];
        }
        
        if (strpos($text, '/start') === 0) {
            $ref = substr($text, 7); // Extract referral code if present
            
            if (!empty($ref) && !$users[$chat_id]['referred_by']) {
                foreach ($users as $id => $user) {
                    if ($user['ref_code'] === $ref && $id != $chat_id) {
                        $users[$chat_id]['referred_by'] = $id;
                        $users[$id]['referrals']++;
                        $users[$id]['balance'] += 50; // Referral bonus
                        sendMessage($id, "🎉 <b>New Referral!</b>\n\nUser <b>{$first_name}</b> joined using your referral link.\n\n<b>+50 points bonus added to your account!</b>");
                        break;
                    }
                }
            }
            
            $ref_link = "https://t.me/" . BOT_USERNAME . "?start={$users[$chat_id]['ref_code']}";
            $msg = "👋 <b>Welcome, {$first_name}!</b>\n\n".
                   "Earn points, invite friends, and withdraw your earnings!\n\n".
                   "🔹 <b>Your Referral Code:</b> <code>{$users[$chat_id]['ref_code']}</code>\n".
                   "🔹 <b>Your Referral Link:</b> <code>{$ref_link}</code>\n\n".
                   "Use the buttons below to navigate through the bot features.";
            sendMessage($chat_id, $msg, getMainKeyboard());
        } elseif ($text == '/balance') {
            $msg = "💳 <b>Your Balance</b>\n\n".
                   "Points: <b>{$users[$chat_id]['balance']}</b>\n".
                   "Referrals: <b>{$users[$chat_id]['referrals']}</b>";
            sendMessage($chat_id, $msg, getMainKeyboard());
        } elseif ($text == '/help') {
            $msg = "❓ <b>Help Center</b>\n\n".
                   "💰 <b>Earn:</b> Get 10 points every minute\n".
                   "👥 <b>Refer:</b> Earn 50 points per referral\n".
                   "🏧 <b>Withdraw:</b> Minimum 100 points\n\n".
                   "Use the buttons below to navigate!";
            sendMessage($chat_id, $msg, getMainKeyboard());
        } else {
            $msg = "I don't understand that command. Please use the buttons below or try /start, /balance, or /help.";
            sendMessage($chat_id, $msg, getMainKeyboard());
        }
        
    } elseif (isset($update['callback_query'])) {
        $chat_id = $update['callback_query']['message']['chat']['id'];
        $data = $update['callback_query']['data'];
        $callback_id = $update['callback_query']['id'];
        
        // Answer callback query to stop loading animation
        file_get_contents(API_URL . "answerCallbackQuery?callback_query_id={$callback_id}");
        
        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . time()), 0, 8),
                'referred_by' => null,
                'name' => $update['callback_query']['from']['first_name'] ?? 'User',
                'joined' => date('Y-m-d H:i:s')
            ];
        }
        
        switch ($data) {
            case 'earn':
                $time_diff = time() - $users[$chat_id]['last_earn'];
                if ($time_diff < 60) {
                    $remaining = 60 - $time_diff;
                    $msg = "⏳ <b>Please wait!</b>\n\n{$remaining} seconds before earning again.";
                } else {
                    $earn = 10;
                    $users[$chat_id]['balance'] += $earn;
                    $users[$chat_id]['last_earn'] = time();
                    $msg = "✅ <b>Points Earned!</b>\n\n".
                           "You earned <b>{$earn} points</b>!\n".
                           "New balance: <b>{$users[$chat_id]['balance']} points</b>\n\n".
                           "You can earn again in 60 seconds.";
                }
                break;
                
            case 'balance':
                $msg = "💳 <b>Your Balance</b>\n\n".
                       "Points: <b>{$users[$chat_id]['balance']}</b>\n".
                       "Referrals: <b>{$users[$chat_id]['referrals']}</b>";
                break;
                
            case 'leaderboard':
                // Create a copy for sorting
                $leaderboard = [];
                foreach ($users as $id => $user) {
                    $leaderboard[$id] = $user['balance'];
                }
                
                // Sort by balance (highest first)
                arsort($leaderboard);
                
                // Format top 5
                $msg = "🏆 <b>Top Earners</b>\n\n";
                $i = 1;
                foreach (array_slice($leaderboard, 0, 5, true) as $id => $balance) {
                    $name = $users[$id]['name'] ?? "User";
                    $msg .= "{$i}. <b>{$name}</b>: {$balance} points\n";
                    $i++;
                }
                
                // Add user position if not in top 5
                $position = array_search($chat_id, array_keys($leaderboard)) + 1;
                if ($position > 5) {
                    $msg .= "\nYour position: <b>#{$position}</b> with {$users[$chat_id]['balance']} points";
                }
                break;
                
            case 'referrals':
                $ref_link = "https://t.me/" . BOT_USERNAME . "?start={$users[$chat_id]['ref_code']}";
                $msg = "👥 <b>Referral System</b>\n\n".
                       "Your code: <code>{$users[$chat_id]['ref_code']}</code>\n".
                       "Your referrals: <b>{$users[$chat_id]['referrals']}</b>\n\n".
                       "Share this link to invite friends:\n".
                       "<code>{$ref_link}</code>\n\n".
                       "<b>Earn 50 points per referral!</b>";
                break;
                
            case 'withdraw':
                $min = 100;
                if ($users[$chat_id]['balance'] < $min) {
                    $msg = "🏧 <b>Withdrawal</b>\n\n".
                           "Minimum: <b>{$min} points</b>\n".
                           "Your balance: <b>{$users[$chat_id]['balance']} points</b>\n\n".
                           "You need <b>" . ($min - $users[$chat_id]['balance']) . " more points</b> to withdraw!";
                } else {
                    $amount = $users[$chat_id]['balance'];
                    $users[$chat_id]['balance'] = 0;
                    $msg = "🏧 <b>Withdrawal Requested!</b>\n\n".
                           "Amount: <b>{$amount} points</b>\n\n".
                           "Our team will process your withdrawal shortly.\n".
                           "Thank you for your patience!";
                    
                    // Log withdrawal request (could be extended to notify admin)
                    logError("Withdrawal request", [
                        'chat_id' => $chat_id, 
                        'amount' => $amount,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]);
                }
                break;
                
            case 'help':
                $msg = "❓ <b>Help Center</b>\n\n".
                       "💰 <b>Earn:</b> Get 10 points every minute\n".
                       "👥 <b>Refer:</b> Earn 50 points per referral\n".
                       "🏧 <b>Withdraw:</b> Minimum 100 points\n\n".
                       "Use the buttons below to navigate!";
                break;
                
            default:
                $msg = "Unknown command. Please try again.";
        }
        
        sendMessage($chat_id, $msg, getMainKeyboard());
    }
    
    saveUsers($users);
}

// Extract username from webhook data
function getBotUsername() {
    try {
        $response = file_get_contents(API_URL . 'getMe');
        $botInfo = json_decode($response, true);
        if ($botInfo['ok'] && isset($botInfo['result']['username'])) {
            return $botInfo['result']['username'];
        }
    } catch (Exception $e) {
        logError("Failed to get bot username: " . $e->getMessage());
    }
    return "YourBot"; // Fallback
}

// Main webhook handler
if (php_sapi_name() === 'cli') {
    // Command line setup for initial configuration
    echo "Setting up webhook...\n";
    $url = readline("Enter your webhook URL (e.g., https://your-app.onrender.com): ");
    if (setupWebhook($url)) {
        echo "Webhook successfully set to: $url\n";
    } else {
        echo "Failed to set webhook. Check error.log for details.\n";
    }
} else {
    // Web request handling
    
    // Verify webhook secret token for security
    $token = $_GET['token'] ?? '';
    if ($token !== WEBHOOK_SECRET) {
        http_response_code(403);
        echo "Access denied";
        exit;
    }
    
    // Process update
    try {
        define('BOT_USERNAME', getBotUsername());
        
        $update = json_decode(file_get_contents('php://input'), true);
        if ($update) {
            processUpdate($update);
        }
        
        // Always return 200 OK to Telegram
        http_response_code(200);
        echo "OK";
    } catch (Exception $e) {
        logError("Webhook error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        http_response_code(200); // Still return 200 to prevent Telegram from retrying
        echo "Error processed";
    }
}