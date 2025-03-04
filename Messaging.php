<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    echo "Please log in to access this page.";
    exit();  
}

$user_id = $_SESSION['user_id'];

$host = 'localhost';
$dbname = 'fitness_buddy';
$username = 'root';
$password = '';

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Block a user
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['block_user_id'])) {
    $blocked_user_id = $_POST['block_user_id'];

    // Check if the user is already blocked
    $stmt = $conn->prepare("SELECT * FROM blocked_users WHERE blocker_id = :blocker_id AND blocked_id = :blocked_id");
    $stmt->execute(['blocker_id' => $user_id, 'blocked_id' => $blocked_user_id]);
    
    if ($stmt->rowCount() == 0) {
        // Block the user
        $stmt = $conn->prepare("INSERT INTO blocked_users (blocker_id, blocked_id) VALUES (:blocker_id, :blocked_id)");
        $stmt->execute(['blocker_id' => $user_id, 'blocked_id' => $blocked_user_id]);
        echo json_encode(["success" => true, "message" => "User has been blocked"]);
    } else {
        echo json_encode(["success" => false, "message" => "User is already blocked"]);
    }
    exit();
}

// Unblock a user
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['unblock_user_id'])) {
    $unblocked_user_id = $_POST['unblock_user_id'];

    // Unblock the user
    $stmt = $conn->prepare("DELETE FROM blocked_users WHERE blocker_id = :blocker_id AND blocked_id = :blocked_id");
    $stmt->execute(['blocker_id' => $user_id, 'blocked_id' => $unblocked_user_id]);

    echo json_encode(["success" => true, "message" => "User has been unblocked"]);
    exit();
}

// Report a message
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['report_message_id'])) {
    $message_id = $_POST['report_message_id'];
    
    $stmt = $conn->prepare("UPDATE messages SET reported = TRUE WHERE id = :message_id");
    $stmt->execute(['message_id' => $message_id]);
    
    echo json_encode(["success" => true, "message" => "Message has been reported"]);
    exit();
}

// Send a message
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['message']) && isset($_POST['receiver_id'])) {
    $message = $_POST['message'];
    $receiver_id = $_POST['receiver_id'];

    // Check if the receiver has blocked the sender
    $stmt = $conn->prepare("SELECT * FROM blocked_users WHERE blocker_id = :blocker_id AND blocked_id = :blocked_id");
    $stmt->execute(['blocker_id' => $receiver_id, 'blocked_id' => $user_id]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(["error" => true, "message" => "You have been blocked by this user. You cannot send messages."]);
        exit();
    }

    // Check if the sender has blocked the receiver
    $stmt = $conn->prepare("SELECT * FROM blocked_users WHERE blocker_id = :blocker_id AND blocked_id = :blocked_id");
    $stmt->execute(['blocker_id' => $user_id, 'blocked_id' => $receiver_id]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(["error" => true, "message" => "You have blocked this user. You cannot send messages."]);
        exit();
    }

    // Proceed to insert message if no block
    $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message, status) VALUES (:sender_id, :receiver_id, :message, 'sent')");
    $stmt->execute(['sender_id' => $user_id, 'receiver_id' => $receiver_id, 'message' => $message]);
    echo json_encode(["success" => true]);
    exit();
}

// Fetch messages
if (isset($_GET['receiver_id'])) {
    $receiver_id = $_GET['receiver_id'];
    
    $stmt = $conn->prepare("UPDATE messages SET status='read' WHERE receiver_id = :user_id AND sender_id = :receiver_id");
    $stmt->execute(['user_id' => $user_id, 'receiver_id' => $receiver_id]);
    
    $stmt = $conn->prepare("SELECT * FROM messages WHERE (sender_id = :user_id AND receiver_id = :receiver_id) OR (sender_id = :receiver_id AND receiver_id = :user_id) ORDER BY sent_at ASC");
    $stmt->execute(['user_id' => $user_id, 'receiver_id' => $receiver_id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$search = isset($_GET['search']) ? $_GET['search'] : '';
$search_query = "%$search%";
$users_stmt = $conn->prepare("SELECT id, username FROM users WHERE id != :user_id AND username LIKE :search ORDER BY username");
$users_stmt->execute(['user_id' => $user_id, 'search' => $search_query]);
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messaging System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="container mt-4">
    <h1 class="mb-4">Messaging</h1>
    <div class="row">
        <div class="col-md-4">
            <h4>Users</h4>
            <form method="GET" class="mb-3">
                <input type="text" name="search" class="form-control" placeholder="Search users..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-primary mt-2">Search</button>
            </form>
            <ul class="list-group">
                <?php foreach ($users as $user): ?>
                    <li class="list-group-item">
                        <a href="messages.php?receiver_id=<?php echo $user['id']; ?>">
                            <?php echo htmlspecialchars($user['username']); ?>
                        </a>
                        <form method="POST" action="messages.php" style="display:inline;">
                            <input type="hidden" name="block_user_id" value="<?php echo $user['id']; ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Block</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="col-md-8">
            <h4>Conversation</h4>
            <div id="message-box" class="border p-3" style="height: 300px; overflow-y: auto;">
                <?php if (isset($messages)): ?>
                    <?php foreach ($messages as $message): ?>
                        <p><strong><?php echo ($message['sender_id'] == $user_id) ? 'You' : 'User ' . $message['sender_id']; ?>:</strong> 
                            <?php echo htmlspecialchars($message['message']); ?>
                            <?php if ($message['reported'] == 1): ?>
                                <span class="text-danger">[Reported]</span>
                            <?php else: ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="report_message_id" value="<?php echo $message['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Report as Abusive</button>
                                </form>
                            <?php endif; ?>
                        </p>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <form id="message-form" class="mt-3">
                <input type="hidden" name="receiver_id" value="<?php echo $receiver_id ?? ''; ?>">
                <textarea name="message" class="form-control" rows="3" required></textarea>
                <button type="submit" class="btn btn-primary mt-2">Send</button>
            </form>
        </div>
    </div>
</body>
</html>
