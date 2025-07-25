<?php
include 'config/db.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'create_habit':
            $name = trim($_POST['name']);
            $frequency = (int)$_POST['frequency'];
            $color = trim($_POST['color']);
            $icon_svg = trim($_POST['icon_svg']);
            
            $stmt = $conn->prepare("INSERT INTO habits (user_id, name, frequency, color, icon_svg) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("isiss", $user_id, $name, $frequency, $color, $icon_svg);
            
            if ($stmt->execute()) {
                $habit_id = $conn->insert_id;
                
                // Check for habit creation achievements
                checkHabitCreationAchievements($conn, $user_id);
                
                echo json_encode(['success' => true, 'habit_id' => $habit_id]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to create habit']);
            }
            exit();
            
        case 'update_habit':
            $habit_id = (int)$_POST['habit_id'];
            $name = trim($_POST['name']);
            $frequency = (int)$_POST['frequency'];
            $color = trim($_POST['color']);
            $icon_svg = trim($_POST['icon_svg']);
            
            $stmt = $conn->prepare("UPDATE habits SET name = ?, frequency = ?, color = ?, icon_svg = ? WHERE id = ? AND user_id = ?");
            $stmt->bind_param("sissis", $name, $frequency, $color, $icon_svg, $habit_id, $user_id);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to update habit']);
            }
            exit();
            
        case 'delete_habit':
            $habit_id = (int)$_POST['habit_id'];
            
            // Delete habit completions first
            $stmt = $conn->prepare("DELETE FROM habit_completions WHERE habit_id = ?");
            $stmt->bind_param("i", $habit_id);
            $stmt->execute();
            
            // Delete the habit
            $stmt = $conn->prepare("DELETE FROM habits WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $habit_id, $user_id);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to delete habit']);
            }
            exit();
            
        case 'toggle_habit_completion':
            $habit_id = (int)$_POST['habit_id'];
            $date = $_POST['date'];
            $completed = $_POST['completed'] === 'true';
            
            if ($completed) {
                $stmt = $conn->prepare("INSERT IGNORE INTO habit_completions (habit_id, completion_date) VALUES (?, ?)");
                $stmt->bind_param("is", $habit_id, $date);
            } else {
                $stmt = $conn->prepare("DELETE FROM habit_completions WHERE habit_id = ? AND completion_date = ?");
                $stmt->bind_param("is", $habit_id, $date);
            }
            
            if ($stmt->execute()) {
                // Check for achievements when habit is completed
                if ($completed) {
                    checkAchievements($conn, $user_id, $habit_id);
                }
                
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to update completion']);
            }
            exit();
            
        case 'save_note':
            $date = $_POST['date'];
            $content = trim($_POST['content']);
            
            if (empty($content)) {
                $stmt = $conn->prepare("DELETE FROM notes WHERE user_id = ? AND note_date = ?");
                $stmt->bind_param("is", $user_id, $date);
            } else {
                $stmt = $conn->prepare("INSERT INTO notes (user_id, note_date, content) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE content = VALUES(content)");
                $stmt->bind_param("iss", $user_id, $date, $content);
            }
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to save note']);
            }
            exit();
            
        case 'get_note':
            $date = $_POST['date'];
            
            $stmt = $conn->prepare("SELECT content FROM notes WHERE user_id = ? AND note_date = ?");
            $stmt->bind_param("is", $user_id, $date);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                echo json_encode(['success' => true, 'content' => $row['content']]);
            } else {
                echo json_encode(['success' => true, 'content' => '']);
            }
            exit();
            
        case 'delete_note':
            $date = $_POST['date'];
            
            $stmt = $conn->prepare("DELETE FROM notes WHERE user_id = ? AND note_date = ?");
            $stmt->bind_param("is", $user_id, $date);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to delete note']);
            }
            exit();
            
        case 'get_achievements':
            $achievements = getUserAchievements($conn, $user_id);
            echo json_encode(['success' => true, 'achievements' => $achievements]);
            exit();
    }
}

// Function to check and unlock achievements
function checkAchievements($conn, $user_id, $habit_id) {
    // Get all completions for this habit
    $stmt = $conn->prepare("SELECT completion_date FROM habit_completions WHERE habit_id = ? ORDER BY completion_date DESC");
    $stmt->bind_param("i", $habit_id);
    $stmt->execute();
    $completions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    if (!empty($completions)) {
        $currentStreak = calculateCurrentStreak($completions);
        $longestStreak = calculateLongestStreak($completions);
        
        // Check streak achievements
        checkStreakAchievements($conn, $user_id, $currentStreak, $longestStreak);
    }
    
    // Check total completion achievements
    checkTotalCompletionAchievements($conn, $user_id);
}

function checkHabitCreationAchievements($conn, $user_id) {
    // Count total habits created by user
    $stmt = $conn->prepare("SELECT COUNT(*) as habit_count FROM habits WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $habitCount = $result['habit_count'];
    
    // Check for habit creation milestones
    $milestones = [
        ['count' => 5, 'type' => 'habit_creator', 'level' => 1, 'name' => 'Planner'],
        ['count' => 10, 'type' => 'habit_creator', 'level' => 2, 'name' => 'Organizer'],
        ['count' => 20, 'type' => 'habit_creator', 'level' => 3, 'name' => 'Life Designer']
    ];
    
    foreach ($milestones as $milestone) {
        if ($habitCount >= $milestone['count']) {
            unlockAchievement($conn, $user_id, $milestone['type'], $milestone['level'], $milestone['name']);
        }
    }
}

function checkStreakAchievements($conn, $user_id, $currentStreak, $longestStreak) {
    $streakMilestones = [
        ['days' => 3, 'type' => 'streak', 'level' => 1, 'name' => 'Getting Started'],
        ['days' => 7, 'type' => 'streak', 'level' => 2, 'name' => '7-Day Streak'],
        ['days' => 14, 'type' => 'streak', 'level' => 3, 'name' => 'Fortnight Fighter'],
        ['days' => 21, 'type' => 'streak', 'level' => 4, 'name' => 'Consistency King'],
        ['days' => 30, 'type' => 'streak', 'level' => 5, 'name' => 'Month Master'],
        ['days' => 90, 'type' => 'streak', 'level' => 6, 'name' => 'Quarter Champion'],
        ['days' => 180, 'type' => 'streak', 'level' => 7, 'name' => 'Half-Year Hero'],
        ['days' => 365, 'type' => 'streak', 'level' => 8, 'name' => 'Year Legend']
    ];
    
    $bestStreak = max($currentStreak, $longestStreak);
    
    foreach ($streakMilestones as $milestone) {
        if ($bestStreak >= $milestone['days']) {
            unlockAchievement($conn, $user_id, $milestone['type'], $milestone['level'], $milestone['name']);
        }
    }
}

function checkTotalCompletionAchievements($conn, $user_id) {
    // Count total habit completions for user
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total_completions 
        FROM habit_completions hc 
        JOIN habits h ON hc.habit_id = h.id 
        WHERE h.user_id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $totalCompletions = $result['total_completions'];
    
    $completionMilestones = [
        ['count' => 50, 'type' => 'completion', 'level' => 1, 'name' => 'Habit Master'],
        ['count' => 100, 'type' => 'completion', 'level' => 2, 'name' => 'Dedication Champion'],
        ['count' => 250, 'type' => 'completion', 'level' => 3, 'name' => 'Consistency Expert'],
        ['count' => 500, 'type' => 'completion', 'level' => 4, 'name' => 'Habit Legend'],
        ['count' => 1000, 'type' => 'completion', 'level' => 5, 'name' => 'Ultimate Master']
    ];
    
    foreach ($completionMilestones as $milestone) {
        if ($totalCompletions >= $milestone['count']) {
            unlockAchievement($conn, $user_id, $milestone['type'], $milestone['level'], $milestone['name']);
        }
    }
}

function unlockAchievement($conn, $user_id, $achievement_type, $level, $name) {
    // Check if achievement already exists
    $stmt = $conn->prepare("SELECT id FROM achievements WHERE user_id = ? AND achievement_type = ? AND level_achieved = ?");
    $stmt->bind_param("isi", $user_id, $achievement_type, $level);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        // Achievement doesn't exist, create it
        $stmt = $conn->prepare("INSERT INTO achievements (user_id, achievement_type, level_achieved, achievement_name, unlocked_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("isis", $user_id, $achievement_type, $level, $name);
        $stmt->execute();
    }
}

// Enhanced function to get user habits with completions for calendar display
function getUserHabits($conn, $user_id, $year = null, $month = null) {
    if (!$year) $year = date('Y');
    if (!$month) $month = date('n');
    
    $stmt = $conn->prepare("SELECT * FROM habits WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $habits = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($habits as &$habit) {
        // Get completions for extended calendar view (previous month to next month)
        $prevMonth = $month - 1;
        $prevYear = $year;
        if ($prevMonth < 1) {
            $prevMonth = 12;
            $prevYear--;
        }
        
        $nextMonth = $month + 1;
        $nextYear = $year;
        if ($nextMonth > 12) {
            $nextMonth = 1;
            $nextYear++;
        }
        
        // Get completions for current month and spillover dates
        $startDate = "$prevYear-" . str_pad($prevMonth, 2, '0', STR_PAD_LEFT) . "-15"; // Last 2 weeks of prev month
        $endDate = "$nextYear-" . str_pad($nextMonth, 2, '0', STR_PAD_LEFT) . "-15"; // First 2 weeks of next month
        
        $stmt = $conn->prepare("SELECT completion_date FROM habit_completions WHERE habit_id = ? AND completion_date BETWEEN ? AND ?");
        $stmt->bind_param("iss", $habit['id'], $startDate, $endDate);
        $stmt->execute();
        $completions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Store all completions for calendar display
        $habit['all_completions'] = [];
        foreach ($completions as $completion) {
            $habit['all_completions'][] = $completion['completion_date'];
        }
        
        // Get completions for current month only (for progress calculation)
        $currentStartDate = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
        $currentEndDate = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-31";
        
        $stmt = $conn->prepare("SELECT completion_date FROM habit_completions WHERE habit_id = ? AND completion_date BETWEEN ? AND ?");
        $stmt->bind_param("iss", $habit['id'], $currentStartDate, $currentEndDate);
        $stmt->execute();
        $currentCompletions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        $habit['completed_days'] = array_map(function($c) {
            return (int)date('j', strtotime($c['completion_date']));
        }, $currentCompletions);
        
        // Calculate streaks (all time)
        $stmt = $conn->prepare("SELECT completion_date FROM habit_completions WHERE habit_id = ? ORDER BY completion_date DESC");
        $stmt->bind_param("i", $habit['id']);
        $stmt->execute();
        $allCompletions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        $habit['current_streak'] = calculateCurrentStreak($allCompletions);
        $habit['longest_streak'] = calculateLongestStreak($allCompletions);
    }
    
    return $habits;
}

// Fixed streak calculation functions
function calculateCurrentStreak($completions) {
    if (empty($completions)) return 0;
    
    // Convert completion dates to timestamps and sort by date (most recent first)
    $dates = array_map(function($c) {
        return strtotime($c['completion_date']);
    }, $completions);
    
    // Remove duplicates and sort in descending order (most recent first)
    $dates = array_unique($dates);
    rsort($dates);
    
    if (empty($dates)) return 0;
    
    $streak = 0;
    $today = strtotime(date('Y-m-d'));
    $yesterday = strtotime(date('Y-m-d', strtotime('-1 day')));
    
    // Check if the most recent completion is today or yesterday
    $mostRecentDate = $dates[0];
    if ($mostRecentDate != $today && $mostRecentDate != $yesterday) {
        return 0; // Streak is broken if no completion today or yesterday
    }
    
    // Start checking from the most recent date
    $currentDate = ($mostRecentDate == $today) ? $today : $yesterday;
    
    // Count consecutive days
    for ($i = 0; $i < count($dates); $i++) {
        if ($dates[$i] == $currentDate) {
            $streak++;
            $currentDate = strtotime(date('Y-m-d', $currentDate - 86400)); // Go back one day
        } else {
            break; // Streak is broken
        }
    }
    
    return $streak;
}

function calculateLongestStreak($completions) {
    if (empty($completions)) return 0;
    
    // Convert completion dates to timestamps and sort by date
    $dates = array_map(function($c) {
        return strtotime($c['completion_date']);
    }, $completions);
    
    // Remove duplicates and sort in ascending order
    $dates = array_unique($dates);
    sort($dates);
    
    if (empty($dates)) return 0;
    if (count($dates) == 1) return 1;
    
    $longestStreak = 1;
    $currentStreak = 1;
    
    // Compare consecutive dates
    for ($i = 1; $i < count($dates); $i++) {
        $daysDifference = ($dates[$i] - $dates[$i-1]) / 86400; // Difference in days
        
        if ($daysDifference == 1) {
            // Consecutive day
            $currentStreak++;
            $longestStreak = max($longestStreak, $currentStreak);
        } else {
            // Streak broken, reset current streak
            $currentStreak = 1;
        }
    }
    
    return $longestStreak;
}

// Get user achievements
function getUserAchievements($conn, $user_id) {
    $stmt = $conn->prepare("SELECT * FROM achievements WHERE user_id = ? ORDER BY unlocked_at DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get current month and year for habits display
$currentYear = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$currentMonth = isset($_GET['month']) ? (int)$_GET['month'] : date('n');

$habits = getUserHabits($conn, $user_id, $currentYear, $currentMonth);
$achievements = getUserAchievements($conn, $user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>LoopedIn Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Lexend:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    * {
      box-sizing: border-box;
    }
    
    body {
      margin: 0;
      min-height: 100vh;
      background: #0a0a0f;
      font-family: 'Inter', Arial, sans-serif;
      display: flex;
      flex-direction: column;
      height: 100vh;
      overflow: hidden;
    }
    
    .navbar {
      width: 100vw;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 38px;
      height: 86px;
      box-sizing: border-box;
      background: transparent;
      position: relative;
      z-index: 10;
    }
    
    .navbar-left {
      display: flex;
      align-items: center;
      gap: 14px;
      text-decoration: none;
      cursor: pointer;
      transition: opacity 0.2s ease;
    }
    
    .navbar-left:hover {
      opacity: 0.8;
    }
    
    .navbar-logo {
      width: 38px;
      height: 38px;
      display: inline-block;
      vertical-align: middle;
    }
    
    .navbar-title {
      font-family: 'Lexend', 'Inter', Arial, sans-serif;
      font-size: 1.55rem;
      font-weight: 700;
      color: #fff;
      letter-spacing: 1px;
    }
    
    .navbar-icons {
      display: flex;
      align-items: center;
      gap: 28px;
      z-index: 2;
    }
    
    .navbar-icon {
      width: 28px;
      height: 28px;
      cursor: pointer;
      opacity: 0.92;
      transition: opacity 0.15s, transform 0.15s;
      display: flex;
      align-items: center;
      justify-content: center;
      background: none;
      border: none;
      padding: 0;
      position: relative;
    }
    
    .navbar-icon:hover {
      opacity: 1;
      transform: scale(1.1);
    }
    
    .navbar-icon.active::after {
      content: '';
      position: absolute;
      bottom: -8px;
      left: 50%;
      transform: translateX(-50%);
      width: 4px;
      height: 4px;
      background: #ffb44c;
      border-radius: 50%;
    }
    
    .logout-icon {
      width: 28px;
      height: 28px;
      cursor: pointer;
      opacity: 0.92;
      transition: opacity 0.15s, transform 0.15s;
      display: flex;
      align-items: center;
      justify-content: center;
      background: none;
      border: none;
      padding: 0;
      position: relative;
    }
    
    .logout-icon:hover {
      opacity: 1;
      transform: scale(1.1);
    }
    
    .logout-icon:hover svg path {
      stroke: #ff4c60;
    }
    
    .main-content {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      width: 100vw;
      min-width: 0;
      padding: 20px;
      overflow-y: auto;
    }
    
    /* Calendar Navigation */
    .calendar-nav {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 24px;
      margin-bottom: 24px;
      background: linear-gradient(145deg, #1a1c28 0%, #181a23 100%);
      border-radius: 1.2rem;
      padding: 16px 24px;
      border: 1px solid rgba(255, 255, 255, 0.05);
      box-shadow: 0 4px 16px rgba(31, 38, 135, 0.1);
    }
    
    .nav-btn {
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      color: #fff;
      width: 40px;
      height: 40px;
      border-radius: 10px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.2rem;
      font-weight: 600;
      transition: all 0.2s ease;
    }
    
    .nav-btn:hover {
      background: rgba(255, 180, 76, 0.1);
      border-color: #ffb44c;
      transform: translateY(-1px);
    }
    
    .nav-btn:active {
      transform: translateY(0);
    }
    
    .current-month-year {
      color: #fff;
      font-family: 'Lexend', sans-serif;
      font-size: 1.4rem;
      font-weight: 600;
      min-width: 200px;
      text-align: center;
      letter-spacing: 0.5px;
    }
    
    .dashboard-card {
      background: #181a23;
      border-radius: 1.5rem;
      box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.13);
      padding: 48px 32px 40px 32px;
      width: 360px;
      max-width: 92vw;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
    }
    
    .dashboard-icon {
      width: 80px;
      height: 80px;
      margin-bottom: 32px;
      display: block;
      filter: drop-shadow(0 2px 8px #ffb44c44);
    }
    
    .dashboard-title {
      color: #fff;
      font-size: 1.25rem;
      font-weight: 600;
      margin-bottom: 12px;
      letter-spacing: 0.5px;
    }
    
    .dashboard-desc {
      color: #b0b4c1;
      font-size: 1.02rem;
      margin-bottom: 26px;
    }
    
    .create-btn {
      background: linear-gradient(135deg, #ffb44c 0%, #ff4c60 100%);
      color: #fff;
      border: none;
      border-radius: 0.7rem;
      font-family: 'Inter', Arial, sans-serif;
      font-weight: 600;
      font-size: 1.07rem;
      padding: 12px 28px;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 4px 12px rgba(255, 180, 76, 0.15);
      position: relative;
      overflow: hidden;
    }
    
    .create-btn::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
      transition: left 0.5s;
    }
    
    .create-btn:hover::before {
      left: 100%;
    }
    
    .create-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(255, 180, 76, 0.25);
    }

    /* Enhanced Habit Card Styles */
    .habit-card { 
      background: linear-gradient(145deg, #1a1c28 0%, #181a23 100%);
      border-radius: 1.5rem; 
      box-shadow: 0 8px 32px rgba(31,38,135,.15), 0 2px 8px rgba(0,0,0,.1);
      padding: 28px 24px 22px; 
      width: 320px; 
      display: flex; 
      flex-direction: column; 
      gap: 20px;
      cursor: pointer;
      transition: all 0.3s ease;
      border: 1px solid rgba(255, 255, 255, 0.05);
      position: relative;
      overflow: hidden;
    }
    
    .habit-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, var(--habit-color, #ffb44c), var(--habit-color-secondary, #ff4c60));
      opacity: 0;
      transition: opacity 0.3s ease;
    }
    
    .habit-card:hover::before {
      opacity: 1;
    }
    
    .habit-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 12px 48px rgba(31,38,135,.25), 0 4px 16px rgba(0,0,0,.15);
    }
    
    .habit-card-top { 
      display: flex; 
      align-items: center; 
      gap: 18px; 
      position: relative;
    }
    
    .habit-icon { 
      width: 52px; 
      height: 52px; 
      border-radius: 14px; 
      display: flex; 
      align-items: center; 
      justify-content: center; 
      box-shadow: 0 4px 16px rgba(255,180,76,.2);
      position: relative;
      transition: all 0.3s ease;
    }
    
    .habit-icon::after {
      content: '';
      position: absolute;
      inset: -2px;
      border-radius: 16px;
      background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.1), transparent);
      opacity: 0;
      transition: opacity 0.3s ease;
    }
    
    .habit-card:hover .habit-icon::after {
      opacity: 1;
    }
    
    .habit-meta { 
      flex: 1; 
      display: flex; 
      flex-direction: column; 
      gap: 8px; 
    }
    
    .habit-name { 
      font: 700 1.22rem 'Lexend',sans-serif; 
      color: #fff; 
      letter-spacing: .3px;
      line-height: 1.2;
    }
    
    .habit-stats { 
      display: flex; 
      gap: 20px; 
      font-size: 1rem; 
      align-items: center;
    }
    
    .habit-streak { 
      color: #ffb44c; 
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    
    .habit-progress-ring {
      position: absolute;
      top: 12px;
      right: 12px;
      width: 50px;
      height: 50px;
    }
    
    .habit-progress-ring svg {
      width: 100%;
      height: 100%;
      transform: rotate(-90deg);
    }
    
    .habit-progress-ring circle {
      fill: none;
      stroke-width: 3;
      stroke-linecap: round;
    }
    
    .habit-progress-ring .bg-circle {
      stroke: rgba(255, 255, 255, 0.1);
    }
    
    .habit-progress-ring .progress-circle {
      stroke: #6f8cff;
      stroke-dasharray: 100 100;
      stroke-dashoffset: 100;
      transition: stroke-dashoffset 0.5s ease;
    }
    
    .habit-progress-ring .progress-text {
      font-size: 0.75rem;
      font-weight: 600;
      fill: #6f8cff;
      text-anchor: middle;
      dominant-baseline: central;
      transform: rotate(90deg);
      transform-origin: 25px 25px;
    }

    /* Enhanced Calendar Grid */
    .habit-calendar { 
      background: linear-gradient(145deg, #24253d 0%, #23243a 100%);
      border-radius: 12px; 
      padding: 18px 16px 14px; 
      margin-top: 8px;
      border: 1px solid rgba(255, 255, 255, 0.05);
    }
    
    .cal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 16px;
    }
    
    .cal-month {
      font-size: 1rem;
      font-weight: 600;
      color: #fff;
      font-family: 'Lexend', sans-serif;
    }
    
    .cal-year {
      font-size: 0.9rem;
      color: #b0b4c1;
      font-weight: 500;
    }
    
    .cal-weekdays {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      gap: 4px;
      margin-bottom: 8px;
      padding: 0 2px;
    }
    
    .cal-weekday {
      font-size: 0.75rem;
      color: #8a8d9a;
      font-weight: 600;
      text-align: center;
      padding: 6px 0;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    
    .cal-grid {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      gap: 4px;
      justify-items: center;
      padding: 0 2px;
      min-height: 192px; /* Ensure 6 rows of 32px cells */
    }
    
    .cal-cell {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.85rem;
      font-weight: 500;
      color: #b0b4c1;
      background: rgba(255, 255, 255, 0.03);
      border: 1.5px solid transparent;
      transition: all 0.2s ease;
      cursor: pointer;
      position: relative;
      overflow: hidden;
    }
    
    .cal-cell:hover {
      background: rgba(255, 255, 255, 0.08);
      transform: scale(1.05);
    }
    
    .cal-cell.today {
      border-color: #ffb44c;
      color: #ffb44c;
      font-weight: 600;
      box-shadow: 0 0 0 2px rgba(255, 180, 76, 0.2);
    }
    
    .cal-cell.done {
      background: linear-gradient(135deg, #ff4c60 0%, #ff6b7d 100%);
      border-color: #ff4c60;
      color: #fff;
      font-weight: 600;
      box-shadow: 0 2px 8px rgba(255, 76, 96, 0.3);
    }
    
    .cal-cell.done::after {
      content: '✓';
      position: absolute;
      top: 2px;
      right: 2px;
      font-size: 0.6rem;
      color: rgba(255, 255, 255, 0.8);
    }
    
    .cal-cell.future {
      opacity: 0.4;
      cursor: not-allowed;
    }
    
    .cal-cell.future:hover {
      transform: none;
      background: rgba(255, 255, 255, 0.03);
    }
    
    .cal-cell.other-month {
      opacity: 0.3;
      color: #6a6d7a;
      font-size: 0.75rem;
    }
    
    .cal-cell.other-month:hover {
      opacity: 0.4;
      background: rgba(255, 255, 255, 0.05);
    }
    
    .cal-cell.other-month.done {
      background: linear-gradient(135deg, rgba(255, 76, 96, 0.4) 0%, rgba(255, 107, 125, 0.4) 100%);
      opacity: 0.6;
    }

    /* Enhanced Modal Styles */
    .modal-bg {
      position: fixed;
      top: 0;
      left: 0;
      width: 100vw;
      height: 100vh;
      background: rgba(10, 10, 15, 0.95);
      backdrop-filter: blur(12px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 2000;
      animation: modalFadeIn 0.3s ease;
    }
    
    @keyframes modalFadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }
    
    .modal-card {
      background: linear-gradient(145deg, #1a1c28 0%, #181a23 100%);
      border-radius: 1.5rem;
      box-shadow: 0 20px 60px rgba(31, 38, 135, 0.3), 0 8px 32px rgba(0, 0, 0, 0.2);
      padding: 36px 32px 32px;
      width: 420px;
      max-width: 96vw;
      max-height: 90vh;
      color: #fff;
      font-family: 'Inter', Arial, sans-serif;
      position: relative;
      animation: modalSlideIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
      border: 1px solid rgba(255, 255, 255, 0.08);
      overflow-y: auto;
    }
    
    @keyframes modalSlideIn {
      from { transform: scale(0.9) translateY(40px); opacity: 0; }
      to { transform: scale(1) translateY(0); opacity: 1; }
    }
    
    .modal-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 24px;
    }
    
    .modal-title {
      font-family: 'Lexend', 'Inter', Arial, sans-serif;
      font-size: 1.3rem;
      font-weight: 700;
      letter-spacing: 0.3px;
      color: #fff;
    }
    
    .modal-close {
      background: rgba(255, 255, 255, 0.05);
      border: none;
      color: #ff4c60;
      font-size: 1.5rem;
      line-height: 1;
      cursor: pointer;
      transition: all 0.2s ease;
      font-weight: 600;
      width: 32px;
      height: 32px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    
    .modal-close:hover {
      background: rgba(255, 76, 96, 0.1);
      transform: scale(1.1);
    }
    
    .modal-form {
      display: flex;
      flex-direction: column;
      gap: 20px;
    }
    
    .modal-label {
      font-family: 'Inter', Arial, sans-serif;
      font-weight: 600;
      color: #e0e0e7;
      font-size: 1.05rem;
      margin-bottom: 8px;
      letter-spacing: 0.3px;
    }
    
    .modal-input {
      background: rgba(255, 255, 255, 0.05);
      border: 1.5px solid rgba(255, 255, 255, 0.1);
      color: #fff;
      font-size: 1rem;
      border-radius: 0.8rem;
      padding: 14px 16px;
      font-family: 'Inter', Arial, sans-serif;
      transition: all 0.2s ease;
      backdrop-filter: blur(10px);
    }
    
    .modal-input:focus {
      border-color: #ffb44c;
      outline: none;
      box-shadow: 0 0 0 3px rgba(255, 180, 76, 0.1);
    }
    
    .modal-input::placeholder {
      color: #6a6d7a;
    }
    
    .modal-frequency-row {
      display: flex;
      align-items: center;
      gap: 12px;
      background: rgba(255, 255, 255, 0.03);
      padding: 12px 16px;
      border-radius: 0.8rem;
      border: 1px solid rgba(255, 255, 255, 0.05);
    }
    
    .modal-freq-input {
      width: 50px;
      padding: 8px 10px;
      border-radius: 8px;
      border: 1.5px solid rgba(255, 255, 255, 0.1);
      background: rgba(255, 255, 255, 0.05);
      color: #fff;
      text-align: center;
      font-size: 1rem;
      font-weight: 600;
    }
    
    .modal-freq-label {
      color: #ffb44c;
      font-weight: 600;
      font-size: 1.05rem;
    }
    
    .modal-freq-btn {
      background: linear-gradient(135deg, #ffb44c 0%, #ff4c60 100%);
      color: #fff;
      border: none;
      border-radius: 8px;
      width: 36px;
      height: 36px;
      font-size: 1.2rem;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.2s ease;
      box-shadow: 0 2px 8px rgba(255, 180, 76, 0.2);
    }
    
    .modal-freq-btn:hover {
      transform: scale(1.05);
      box-shadow: 0 4px 12px rgba(255, 180, 76, 0.3);
    }
    
    .modal-color-row {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      gap: 12px;
      padding: 16px;
      background: rgba(255, 255, 255, 0.03);
      border-radius: 0.8rem;
      border: 1px solid rgba(255, 255, 255, 0.05);
    }
    
    .modal-color-btn {
      width: 32px;
      height: 32px;
      border-radius: 10px;
      border: 2px solid transparent;
      cursor: pointer;
      transition: all 0.2s ease;
      outline: none;
      position: relative;
      overflow: hidden;
    }
    
    .modal-color-btn::after {
      content: '';
      position: absolute;
      inset: 0;
      border-radius: 8px;
      background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.2), transparent);
      opacity: 0;
      transition: opacity 0.3s ease;
    }
    
    .modal-color-btn:hover::after {
      opacity: 1;
    }
    
    .modal-color-btn.selected {
      border-color: #fff;
      transform: scale(1.1);
      box-shadow: 0 4px 12px rgba(255, 255, 255, 0.2);
    }
    
    /* Enhanced Icon Picker */
    .icon-picker {
      background: rgba(255, 255, 255, 0.03);
      border-radius: 0.8rem;
      border: 1px solid rgba(255, 255, 255, 0.05);
      padding: 16px;
    }
    
    .icon-grid {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      gap: 12px;
      max-height: 180px;
      overflow-y: auto;
      padding-right: 8px;
    }
    
    .icon-grid.collapsed {
      max-height: 140px;
      overflow: hidden;
    }
    
    .icon-grid::-webkit-scrollbar {
      width: 6px;
    }
    
    .icon-grid::-webkit-scrollbar-track {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 3px;
    }
    
    .icon-grid::-webkit-scrollbar-thumb {
      background: rgba(255, 180, 76, 0.3);
      border-radius: 3px;
    }
    
    .icon-grid::-webkit-scrollbar-thumb:hover {
      background: rgba(255, 180, 76, 0.5);
    }
    
    .modal-icon-btn {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 10px;
      border: 2px solid transparent;
      width: 42px;
      height: 42px;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: all 0.2s ease;
      padding: 0;
      position: relative;
      overflow: hidden;
    }
    
    .modal-icon-btn::after {
      content: '';
      position: absolute;
      inset: 0;
      border-radius: 8px;
      background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.1), transparent);
      opacity: 0;
      transition: opacity 0.3s ease;
    }
    
    .modal-icon-btn:hover::after {
      opacity: 1;
    }
    
    .modal-icon-btn.selected {
      border-color: #ffb44c;
      background: rgba(255, 180, 76, 0.1);
      transform: scale(1.05);
      box-shadow: 0 4px 12px rgba(255, 180, 76, 0.2);
    }
    
    .modal-icon-btn.used {
      opacity: 0.35;
      filter: grayscale(1);
    }
    
    .show-more-btn {
      margin-top: 16px;
      background: none;
      border: none;
      color: #6f8cff;
      font-weight: 600;
      cursor: pointer;
      font-size: 0.9rem;
      padding: 8px 0;
      transition: color 0.2s ease;
      width: 100%;
      text-align: center;
    }
    
    .show-more-btn:hover {
      color: #ffb44c;
    }
    
    .modal-create-btn {
      background: linear-gradient(135deg, #ffb44c 0%, #ff4c60 100%);
      color: #fff;
      border: none;
      border-radius: 0.8rem;
      font-family: 'Inter', Arial, sans-serif;
      font-weight: 600;
      font-size: 1.1rem;
      padding: 16px 0;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 4px 16px rgba(255, 180, 76, 0.2);
      width: 100%;
      position: relative;
      overflow: hidden;
    }
    
    .modal-create-btn::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
      transition: left 0.5s;
    }
    
    .modal-create-btn:hover::before {
      left: 100%;
    }
    
    .modal-create-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 24px rgba(255, 180, 76, 0.3);
    }

    /* Habit Detail Modal */
    .habit-detail-modal {
      width: 360px;
    }
    
    .habit-detail-header {
      display: flex;
      align-items: center;
      gap: 16px;
      margin-bottom: 24px;
      padding: 16px;
      background: rgba(255, 255, 255, 0.03);
      border-radius: 0.8rem;
      border: 1px solid rgba(255, 255, 255, 0.05);
    }
    
    .habit-detail-icon {
      width: 44px;
      height: 44px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 4px 12px rgba(255, 180, 76, 0.2);
    }
    
    .habit-detail-title {
      font-family: 'Lexend', sans-serif;
      font-size: 1.25rem;
      font-weight: 700;
      color: #fff;
      line-height: 1.2;
    }
    
    .habit-action-btn {
      width: 100%;
      padding: 16px;
      margin: 12px 0;
      background: linear-gradient(135deg, #ffb44c 0%, #ff4c60 100%);
      color: #fff;
      border: none;
      border-radius: 0.8rem;
      font-family: 'Inter', Arial, sans-serif;
      font-weight: 600;
      font-size: 1.05rem;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 4px 16px rgba(255, 180, 76, 0.2);
      position: relative;
      overflow: hidden;
    }
    
    .habit-action-btn::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
      transition: left 0.5s;
    }
    
    .habit-action-btn:hover::before {
      left: 100%;
    }
    
    .habit-action-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 24px rgba(255, 180, 76, 0.3);
    }
    
    .habit-action-btn.completed {
      background: linear-gradient(135deg, #23243a 0%, #2a2b3e 100%);
      color: #ff4c60;
      box-shadow: 0 4px 16px rgba(255, 76, 96, 0.1);
    }
    
    .habit-action-btn.completed:hover {
      box-shadow: 0 6px 24px rgba(255, 76, 96, 0.2);
    }
    
    .habit-secondary-btn {
      width: 100%;
      padding: 16px;
      margin: 12px 0;
      background: rgba(255, 255, 255, 0.05);
      color: #fff;
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 0.8rem;
      font-family: 'Inter', Arial, sans-serif;
      font-weight: 600;
      font-size: 1.05rem;
      cursor: pointer;
      transition: all 0.3s ease;
      backdrop-filter: blur(10px);
    }
    
    .habit-secondary-btn:hover {
      background: rgba(255, 255, 255, 0.08);
      transform: translateY(-1px);
      box-shadow: 0 4px 16px rgba(255, 255, 255, 0.05);
    }
    
    .habit-delete-btn {
      width: 100%;
      padding: 16px;
      margin: 16px 0 0 0;
      background: linear-gradient(135deg, #ff4c60 0%, #ff6b7d 100%);
      color: #fff;
      border: none;
      border-radius: 0.8rem;
      font-family: 'Inter', Arial, sans-serif;
      font-weight: 600;
      font-size: 1.05rem;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 4px 16px rgba(255, 76, 96, 0.2);
      position: relative;
      overflow: hidden;
    }
    
    .habit-delete-btn::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
      transition: left 0.5s;
    }
    
    .habit-delete-btn:hover::before {
      left: 100%;
    }
    
    .habit-delete-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 24px rgba(255, 76, 96, 0.3);
    }

    /* ENHANCED: Dynamic Achievements Modal */
    .achievements-modal {
      width: 700px;
      max-width: 95vw;
    }
    
    .achievements-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 24px 16px;
      justify-items: center;
      margin-bottom: 32px;
    }
    
    .achievement-card {
      background: #191a23;
      border-radius: 18px;
      padding: 24px 0 16px;
      width: 100%;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      transition: all 0.3s ease;
      border: 1px solid rgba(255, 255, 255, 0.05);
      position: relative;
      overflow: hidden;
    }
    
    .achievement-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(255, 180, 76, 0.1);
    }
    
    .achievement-card.unlocked::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, #ffb44c, #ff4c60);
    }
    
    .achievement-icon {
      width: 70px;
      height: 70px;
      margin-bottom: 12px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 2rem;
      transition: all 0.3s ease;
      position: relative;
    }
    
    .achievement-icon.unlocked {
      background: linear-gradient(135deg, #ffb44c 0%, #ff4c60 100%);
      box-shadow: 0 4px 16px rgba(255, 180, 76, 0.3);
      animation: achievementGlow 2s ease-in-out infinite alternate;
    }
    
    @keyframes achievementGlow {
      from { box-shadow: 0 4px 16px rgba(255, 180, 76, 0.3); }
      to { box-shadow: 0 8px 24px rgba(255, 180, 76, 0.5); }
    }
    
    .achievement-icon.locked {
      background: #2a2b3e;
      filter: grayscale(1);
      opacity: 0.6;
    }
    
    .achievement-label {
      color: #fff;
      font-size: 1.05rem;
      font-weight: 600;
      font-family: 'Lexend', sans-serif;
    }
    
    .achievement-label.locked {
      color: #8a8d9a;
    }
    
    .achievement-progress {
      margin-top: 8px;
      font-size: 0.85rem;
      color: #b0b4c1;
      background: rgba(255, 255, 255, 0.05);
      padding: 4px 8px;
      border-radius: 12px;
      min-height: 24px;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    
    .achievement-progress.unlocked {
      color: #ffb44c;
      background: rgba(255, 180, 76, 0.1);
    }
    
    /* Secret Achievements - Progressive Display */
    .secret-achievements {
      margin-top: 32px;
    }
    
    .secret-title {
      color: #fff;
      font-weight: 600;
      font-size: 1.1rem;
      margin-bottom: 16px;
      font-family: 'Lexend', sans-serif;
    }
    
    .secret-achievement {
      background: #191a23;
      border-radius: 14px;
      padding: 18px 16px 16px 60px;
      position: relative;
      margin-bottom: 12px;
      border: 1px solid rgba(255, 255, 255, 0.05);
      transition: all 0.3s ease;
      opacity: 0.6;
    }
    
    .secret-achievement.unlocked {
      opacity: 1;
      background: #1e1f2a;
      border-color: rgba(255, 180, 76, 0.2);
    }
    
    .secret-achievement.unlocked::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 2px;
      background: linear-gradient(90deg, #6f8cff, #8fa4ff);
    }
    
    .secret-achievement:hover {
      background: #1e1f2a;
      transform: translateY(-1px);
    }
    
    .secret-achievement:last-child {
      margin-bottom: 0;
    }
    
    .secret-icon {
      width: 40px;
      height: 40px;
      position: absolute;
      left: 12px;
      top: 14px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, #6f8cff 0%, #8fa4ff 100%);
      font-size: 1.2rem;
    }
    
    .secret-achievement.unlocked .secret-icon {
      animation: secretGlow 3s ease-in-out infinite alternate;
    }
    
    @keyframes secretGlow {
      from { box-shadow: 0 2px 8px rgba(111, 140, 255, 0.3); }
      to { box-shadow: 0 4px 16px rgba(111, 140, 255, 0.6); }
    }
    
    .secret-name {
      color: #fff;
      font-weight: 600;
      font-size: 1.04rem;
      margin-bottom: 4px;
      font-family: 'Lexend', sans-serif;
    }
    
    .secret-achievement.locked .secret-name {
      color: #8a8d9a;
    }
    
    .secret-desc {
      color: #b0b4c1;
      font-size: 0.98rem;
      line-height: 1.4;
    }
    
    .secret-achievement.locked .secret-desc {
      color: #6a6d7a;
    }

    /* Statistics Modal */
    .stats-modal {
      width: 420px;
      max-width: 95vw;
    }
    
    .stats-header {
      text-align: center;
      margin-bottom: 24px;
    }
    
    .stats-year {
      font-size: 1.6rem;
      font-weight: 700;
      color: #fff;
      margin-bottom: 24px;
      font-family: 'Lexend', sans-serif;
    }
    
    .stats-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
      margin-bottom: 24px;
    }
    
    .stat-card {
      background: linear-gradient(145deg, #24253d 0%, #23243a 100%);
      border-radius: 12px;
      padding: 20px;
      text-align: center;
      border: 1px solid rgba(255, 255, 255, 0.05);
      position: relative;
      overflow: hidden;
    }
    
    .stat-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, var(--stat-color, #ffb44c), var(--stat-color-end, #ff4c60));
    }
    
    .stat-label {
      color: #b0b4c1;
      font-size: 0.9rem;
      margin-bottom: 8px;
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    
    .stat-value {
      font-size: 2.2rem;
      font-weight: 700;
      color: #fff;
      font-family: 'Lexend', sans-serif;
    }
    
    .stat-current {
      color: #ffb44c;
    }
    
    .stat-longest {
      color: #00c9a7;
    }
    
    .weekday-chart {
      background: linear-gradient(145deg, #24253d 0%, #23243a 100%);
      border-radius: 12px;
      padding: 20px;
      margin-bottom: 24px;
      border: 1px solid rgba(255, 255, 255, 0.05);
    }
    
    .weekday-title {
      color: #fff;
      font-size: 1.1rem;
      font-weight: 600;
      margin-bottom: 20px;
      font-family: 'Lexend', sans-serif;
    }
    
    .weekday-bars {
      display: flex;
      align-items: end;
      gap: 8px;
      height: 120px;
      padding: 0 8px;
    }
    
    .weekday-bar {
      flex: 1;
      background: linear-gradient(135deg, #00c9a7 0%, #00e6a7 100%);
      border-radius: 6px 6px 4px 4px;
      display: flex;
      align-items: end;
      justify-content: center;
      color: #fff;
      font-size: 0.85rem;
      font-weight: 600;
      position: relative;
      transition: all 0.3s ease;
      min-height: 20px;
      box-shadow: 0 2px 8px rgba(0, 201, 167, 0.2);
    }
    
    .weekday-bar:hover {
      transform: scaleY(1.05);
      box-shadow: 0 4px 16px rgba(0, 201, 167, 0.3);
    }
    
    .weekday-label {
      position: absolute;
      bottom: -24px;
      font-size: 0.75rem;
      color: #b0b4c1;
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    
    .total-completed {
      background: linear-gradient(145deg, #24253d 0%, #23243a 100%);
      border-radius: 12px;
      padding: 24px;
      text-align: center;
      border: 1px solid rgba(255, 255, 255, 0.05);
      position: relative;
      overflow: hidden;
    }
    
    .total-completed::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, #6f8cff, #8fa4ff);
    }
    
    .total-title {
      color: #b0b4c1;
      font-size: 1rem;
      margin-bottom: 12px;
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    
    .total-value {
      font-size: 3.5rem;
      font-weight: 700;
      color: #6f8cff;
      font-family: 'Lexend', sans-serif;
      line-height: 1;
    }

    /* Notes Modal */
    .notes-modal {
      width: 500px;
      max-width: 95vw;
    }
    
    .notes-header {
      text-align: center;
      margin-bottom: 24px;
    }
    
    .notes-date-picker {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 12px;
      margin-bottom: 16px;
      padding: 12px 16px;
      background: rgba(255, 255, 255, 0.03);
      border-radius: 0.8rem;
      border: 1px solid rgba(255, 255, 255, 0.05);
    }
    
    .date-nav-btn {
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      color: #fff;
      width: 32px;
      height: 32px;
      border-radius: 8px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1rem;
      font-weight: 600;
      transition: all 0.2s ease;
    }
    
    .date-nav-btn:hover {
      background: rgba(255, 180, 76, 0.1);
      border-color: #ffb44c;
    }
    
    .notes-date {
      color: #fff;
      font-size: 1.1rem;
      font-weight: 600;
      min-width: 180px;
      text-align: center;
      font-family: 'Lexend', sans-serif;
    }
    
    .notes-textarea {
      width: 100%;
      height: 240px;
      background: rgba(255, 255, 255, 0.05);
      border: 1.5px solid rgba(255, 255, 255, 0.1);
      border-radius: 0.8rem;
      color: #fff;
      font-family: 'Inter', Arial, sans-serif;
      font-size: 1rem;
      padding: 16px;
      resize: vertical;
      transition: all 0.2s ease;
      backdrop-filter: blur(10px);
      line-height: 1.6;
      white-space: pre-wrap;
    }
    
    .notes-textarea:focus {
      border-color: #ffb44c;
      outline: none;
      box-shadow: 0 0 0 3px rgba(255, 180, 76, 0.1);
    }
    
    .notes-textarea::placeholder {
      color: #6a6d7a;
      font-style: italic;
    }
    
    .notes-actions {
      display: flex;
      gap: 12px;
      margin-top: 20px;
    }
    
    .notes-save-btn {
      flex: 1;
      padding: 16px;
      background: linear-gradient(135deg, #ffb44c 0%, #ff4c60 100%);
      color: #fff;
      border: none;
      border-radius: 0.8rem;
      font-family: 'Inter', Arial, sans-serif;
      font-weight: 600;
      font-size: 1.05rem;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 4px 16px rgba(255, 180, 76, 0.2);
      position: relative;
      overflow: hidden;
    }
    
    .notes-save-btn::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
      transition: left 0.5s;
    }
    
    .notes-save-btn:hover::before {
      left: 100%;
    }
    
    .notes-save-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 24px rgba(255, 180, 76, 0.3);
    }
    
    .notes-delete-btn {
      padding: 16px 20px;
      background: linear-gradient(135deg, #ff4c60 0%, #ff6b7d 100%);
      color: #fff;
      border: none;
      border-radius: 0.8rem;
      font-family: 'Inter', Arial, sans-serif;
      font-weight: 600;
      font-size: 1.05rem;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 4px 16px rgba(255, 76, 96, 0.2);
      position: relative;
      overflow: hidden;
    }
    
    .notes-delete-btn::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
      transition: left 0.5s;
    }
    
    .notes-delete-btn:hover::before {
      left: 100%;
    }
    
    .notes-delete-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 24px rgba(255, 76, 96, 0.3);
    }

    /* Quick Actions */
    .quick-actions {
      display: flex;
      gap: 12px;
      margin-bottom: 20px;
    }
    
    .quick-action {
      flex: 1;
      padding: 12px 16px;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 0.8rem;
      color: #fff;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.2s ease;
      text-align: center;
      font-size: 0.9rem;
    }
    
    .quick-action:hover {
      background: rgba(255, 255, 255, 0.08);
      transform: translateY(-1px);
    }
    
    .quick-action.active {
      background: rgba(255, 180, 76, 0.1);
      border-color: #ffb44c;
      color: #ffb44c;
    }

    /* Toast Notifications */
    .toast {
      position: fixed;
      top: 20px;
      right: 20px;
      background: linear-gradient(135deg, #00c9a7 0%, #00e6a7 100%);
      color: #fff;
      padding: 12px 20px;
      border-radius: 0.8rem;
      font-weight: 500;
      font-size: 0.9rem;
      box-shadow: 0 4px 16px rgba(0, 201, 167, 0.3);
      z-index: 3000;
      transform: translateX(100%);
      transition: transform 0.3s ease;
    }
    
    .toast.show {
      transform: translateX(0);
    }
    
    .toast.error {
      background: linear-gradient(135deg, #ff4c60 0%, #ff6b7d 100%);
      box-shadow: 0 4px 16px rgba(255, 76, 96, 0.3);
    }
    
    .toast.info {
      background: linear-gradient(135deg, #6f8cff 0%, #8fa4ff 100%);
      box-shadow: 0 4px 16px rgba(111, 140, 255, 0.3);
    }
    
    .toast.achievement {
      background: linear-gradient(135deg, #ffb44c 0%, #ff4c60 100%);
      box-shadow: 0 4px 16px rgba(255, 180, 76, 0.4);
      border: 1px solid rgba(255, 255, 255, 0.2);
    }

    /* Responsive Design */
    @media (max-width: 768px) {
      .navbar {
        padding: 0 20px;
      }
      
      .navbar-title {
        font-size: 1.3rem;
      }
      
      .navbar-icons {
        gap: 20px;
      }
      
      .habit-card {
        width: 100%;
        max-width: 340px;
      }
      
      #habits-grid {
        grid-template-columns: 1fr !important;
        gap: 20px;
      }
      
      .modal-card {
        width: 95vw;
        margin: 0 20px;
      }
      
      .achievements-grid {
        grid-template-columns: repeat(2, 1fr);
      }
      
      .cal-cell {
        width: 28px;
        height: 28px;
      }
      
      .habit-icon {
        width: 48px;
        height: 48px;
      }
      
      .calendar-nav {
        flex-direction: column;
        gap: 16px;
      }
      
      .current-month-year {
        min-width: auto;
      }
    }
    
    @media (max-width: 480px) {
      .dashboard-card {
        width: 95vw;
        padding: 32px 20px;
      }
      
      .navbar {
        padding: 0 16px;
      }
      
      .main-content {
        padding: 16px;
      }
      
      .habit-card {
        padding: 20px 16px;
      }
      
      .achievements-grid {
        grid-template-columns: 1fr;
      }
      
      .cal-cell {
        width: 26px;
        height: 26px;
        font-size: 0.8rem;
      }
      
      .notes-actions {
        flex-direction: column;
      }
    }
  </style>
</head>
<body>
  <!-- Navbar -->
  <div class="navbar">
    <a href="dashboard.php" class="navbar-left">
      <svg class="navbar-logo" viewBox="0 0 48 48" fill="none">
        <rect x="6" y="8" width="36" height="28" rx="8" fill="#191a23" stroke="#ffb44c" stroke-width="2.5"/>
        <rect x="6" y="18" width="36" height="18" rx="4" fill="#ffb44c" />
        <rect x="14" y="24" width="5" height="5" rx="1.5" fill="#ff4c60"/>
        <rect x="22" y="24" width="5" height="5" rx="1.5" fill="#fff"/>
        <rect x="30" y="24" width="5" height="5" rx="1.5" fill="#fff"/>
        <rect x="14" y="31" width="5" height="5" rx="1.5" fill="#fff"/>
        <rect x="22" y="31" width="5" height="5" rx="1.5" fill="#fff"/>
        <rect x="30" y="31" width="5" height="5" rx="1.5" fill="#fff"/>
        <rect x="17" y="8" width="3" height="7" rx="1.5" fill="#ffb44c"/>
        <rect x="28" y="8" width="3" height="7" rx="1.5" fill="#ffb44c"/>
      </svg>
      <span class="navbar-title">LoopedIn</span>
    </a>
    <div class="navbar-icons">
      <button class="navbar-icon active" id="addHabitBtn" title="Add Habit">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
          <rect x="12" y="5" width="4" height="18" rx="2" fill="#fff"/>
          <rect x="5" y="12" width="18" height="4" rx="2" fill="#fff"/>
        </svg>
      </button>
      <button class="navbar-icon" id="notesBtn" title="Notes">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
          <rect x="7" y="6" width="14" height="16" rx="2.5" fill="#fff" stroke="#ffb44c" stroke-width="1.2"/>
          <rect x="8.1" y="7.5" width="1.1" height="2.2" rx="0.5" fill="#ffb44c"/>
          <rect x="8.1" y="11" width="1.1" height="2.2" rx="0.5" fill="#ffb44c"/>
          <rect x="8.1" y="14.5" width="1.1" height="2.2" rx="0.5" fill="#ffb44c"/>
          <rect x="8.1" y="18" width="1.1" height="2.2" rx="0.5" fill="#ffb44c"/>
          <rect x="10.5" y="9" width="8" height="1.2" rx="0.6" fill="#e0e0e0"/>
          <rect x="10.5" y="12.5" width="8" height="1.2" rx="0.6" fill="#e0e0e0"/>
          <rect x="10.5" y="16" width="8" height="1.2" rx="0.6" fill="#e0e0e0"/>
        </svg>
      </button>
      <button class="navbar-icon" id="achievementsBtn" title="Achievements">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
          <rect x="6" y="8" width="16" height="16" rx="3" fill="#fff" stroke="#ffb44c" stroke-width="1.5"/>
          <rect x="8" y="14" width="3" height="3" rx="1" fill="#ffb44c"/>
          <rect x="13" y="14" width="3" height="3" rx="1" fill="#ffb44c"/>
          <rect x="8" y="18" width="3" height="3" rx="1" fill="#ff4c60"/>
          <rect x="13" y="18" width="3" height="3" rx="1" fill="#00c9a7"/>
          <rect x="17" y="10" width="2" height="4" rx="1" fill="#ffb44c"/>
          <circle cx="22" cy="8" r="4" fill="#ff4c60"/>
          <text x="22" y="12" font-family="Arial" font-size="6" fill="#fff" text-anchor="middle">8</text>
        </svg>
      </button>
      <button class="logout-icon" id="logoutBtn" title="Logout" onclick="location.href='index.php'">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
          <path d="M16 17l5-5-5-5" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M21 12H9" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M9 5v14" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>
    </div>
  </div>

  <!-- Main Content -->
  <div class="main-content">
    <!-- Calendar Navigation -->
    <div class="calendar-nav">
      <button class="nav-btn" onclick="changeMonth(-1)">‹</button>
      <div class="current-month-year" id="currentMonthYear">
        <?php 
        $monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                      'July', 'August', 'September', 'October', 'November', 'December'];
        echo $monthNames[$currentMonth - 1] . ' ' . $currentYear;
        ?>
      </div>
      <button class="nav-btn" onclick="changeMonth(1)">›</button>
      <button class="nav-btn" onclick="changeYear(-1)">‹‹</button>
      <button class="nav-btn" onclick="changeYear(1)">››</button>
    </div>

    <!-- Empty State Dashboard Card -->
    <div class="dashboard-card" id="empty-dashboard-card" style="display: <?php echo empty($habits) ? 'flex' : 'none'; ?>;">
      <svg class="dashboard-icon" viewBox="0 0 100 100" fill="none">
        <rect x="10" y="20" width="80" height="60" rx="8" fill="#191a23" stroke="#ffb44c" stroke-width="2"/>
        <rect x="10" y="35" width="80" height="45" rx="4" fill="#ffb44c"/>
        <rect x="25" y="45" width="8" height="8" rx="2" fill="#ff4c60"/>
        <rect x="40" y="45" width="8" height="8" rx="2" fill="#fff"/>
        <rect x="55" y="45" width="8" height="8" rx="2" fill="#fff"/>
        <rect x="70" y="45" width="8" height="8" rx="2" fill="#fff"/>
        <rect x="25" y="60" width="8" height="8" rx="2" fill="#fff"/>
        <rect x="40" y="60" width="8" height="8" rx="2" fill="#fff"/>
        <rect x="55" y="60" width="8" height="8" rx="2" fill="#fff"/>
        <rect x="70" y="60" width="8" height="8" rx="2" fill="#fff"/>
        <rect x="28" y="20" width="6" height="12" rx="3" fill="#ffb44c"/>
        <rect x="66" y="20" width="6" height="12" rx="3" fill="#ffb44c"/>
      </svg>
      <div class="dashboard-title">Ready to build habits?</div>
      <div class="dashboard-desc">Track your daily habits and build lasting routines</div>
      <button class="create-btn" onclick="openHabitModal()">Create First Habit</button>
    </div>

    <!-- Habits Grid -->
    <div id="habits-grid" style="display: <?php echo empty($habits) ? 'none' : 'grid'; ?>; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 24px; width: 100%; max-width: 1200px; justify-items: center;">
      <?php foreach ($habits as $habit): ?>
        <div class="habit-card" data-habit-id="<?php echo $habit['id']; ?>" onclick="openHabitDetailModal(<?php echo $habit['id']; ?>)">
          <div class="habit-card-top">
            <div class="habit-icon" style="background: <?php echo htmlspecialchars($habit['color']); ?>;">
              <?php echo $habit['icon_svg']; ?>
            </div>
            <div class="habit-meta">
              <div class="habit-name"><?php echo htmlspecialchars($habit['name']); ?></div>
              <div class="habit-stats">
                <div class="habit-streak">🔥 <?php echo $habit['current_streak']; ?></div>
              </div>
            </div>
            <div class="habit-progress-ring">
              <svg>
                <circle class="bg-circle" cx="25" cy="25" r="20"></circle>
                <circle class="progress-circle" cx="25" cy="25" r="20" style="stroke-dashoffset: <?php 
                  $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $currentMonth, $currentYear);
                  echo 100 - round((count($habit['completed_days']) / $daysInMonth) * 100); 
                ?>"></circle>
                <text class="progress-text" x="25" y="25"><?php 
                  $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $currentMonth, $currentYear);
                  echo round((count($habit['completed_days']) / $daysInMonth) * 100); 
                ?>%</text>
              </svg>
            </div>
          </div>
          
          <div class="habit-calendar">
            <div class="cal-header">
              <div class="cal-month"><?php echo $monthNames[$currentMonth - 1]; ?></div>
              <div class="cal-year"><?php echo $currentYear; ?></div>
            </div>
            <div class="cal-weekdays">
              <div class="cal-weekday">S</div>
              <div class="cal-weekday">M</div>
              <div class="cal-weekday">T</div>
              <div class="cal-weekday">W</div>
              <div class="cal-weekday">T</div>
              <div class="cal-weekday">F</div>
              <div class="cal-weekday">S</div>
            </div>
            <div class="cal-grid">
              <?php
              // Enhanced calendar generation with proper spillover dates
              $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $currentMonth, $currentYear);
              $firstDayOfWeek = date('w', mktime(0, 0, 0, $currentMonth, 1, $currentYear));
              
              // Calculate previous month info for spillover
              $prevMonth = $currentMonth - 1;
              $prevYear = $currentYear;
              if ($prevMonth < 1) {
                $prevMonth = 12;
                $prevYear--;
              }
              $daysInPrevMonth = cal_days_in_month(CAL_GREGORIAN, $prevMonth, $prevYear);
              
              // Calculate next month info for spillover
              $nextMonth = $currentMonth + 1;
              $nextYear = $currentYear;
              if ($nextMonth > 12) {
                $nextMonth = 1;
                $nextYear++;
              }
              
              // Current date info
              $today = (int)date('j');
              $currentMonthCheck = (int)date('n');
              $currentYearCheck = (int)date('Y');
              
              $cellCount = 0;
              
              // Previous month spillover days
              for ($i = $firstDayOfWeek - 1; $i >= 0; $i--) {
                $day = $daysInPrevMonth - $i;
                $dateString = "$prevYear-" . str_pad($prevMonth, 2, '0', STR_PAD_LEFT) . "-" . str_pad($day, 2, '0', STR_PAD_LEFT);
                $isCompleted = in_array($dateString, $habit['all_completions']);
                $classes = ['cal-cell', 'other-month'];
                if ($isCompleted) $classes[] = 'done';
                echo '<div class="' . implode(' ', $classes) . '" data-date="' . $dateString . '" data-habit-id="' . $habit['id'] . '" onclick="event.stopPropagation(); toggleHabitCompletionByDate(' . $habit['id'] . ', \'' . $dateString . '\', this)">' . $day . '</div>';
                $cellCount++;
              }
              
              // Current month days
              for ($day = 1; $day <= $daysInMonth; $day++) {
                $dateString = "$currentYear-" . str_pad($currentMonth, 2, '0', STR_PAD_LEFT) . "-" . str_pad($day, 2, '0', STR_PAD_LEFT);
                $isCompleted = in_array($dateString, $habit['all_completions']);
                $isToday = ($day === $today && $currentMonth === $currentMonthCheck && $currentYear === $currentYearCheck);
                $isFuture = ($currentYear > $currentYearCheck) || 
                           ($currentYear === $currentYearCheck && $currentMonth > $currentMonthCheck) ||
                           ($currentYear === $currentYearCheck && $currentMonth === $currentMonthCheck && $day > $today);
                
                $classes = ['cal-cell'];
                if ($isCompleted) $classes[] = 'done';
                if ($isToday) $classes[] = 'today';
                if ($isFuture) $classes[] = 'future';
                
                echo '<div class="' . implode(' ', $classes) . '" data-date="' . $dateString . '" data-habit-id="' . $habit['id'] . '" onclick="event.stopPropagation(); toggleHabitCompletionByDate(' . $habit['id'] . ', \'' . $dateString . '\', this)">' . $day . '</div>';
                $cellCount++;
              }
              
              // Next month spillover days (fill remaining cells to make 42 total - 6 rows × 7 columns)
              $remainingCells = 42 - $cellCount;
              for ($day = 1; $day <= $remainingCells; $day++) {
                $dateString = "$nextYear-" . str_pad($nextMonth, 2, '0', STR_PAD_LEFT) . "-" . str_pad($day, 2, '0', STR_PAD_LEFT);
                $isCompleted = in_array($dateString, $habit['all_completions']);
                $classes = ['cal-cell', 'other-month'];
                if ($isCompleted) $classes[] = 'done';
                echo '<div class="' . implode(' ', $classes) . '" data-date="' . $dateString . '" data-habit-id="' . $habit['id'] . '" onclick="event.stopPropagation(); toggleHabitCompletionByDate(' . $habit['id'] . ', \'' . $dateString . '\', this)">' . $day . '</div>';
              }
              ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Habit Creation Modal -->
  <div id="habitModal" class="modal-bg" style="display: none;">
    <div class="modal-card">
      <div class="modal-header">
        <h2 class="modal-title" id="modalTitle">Create New Habit</h2>
        <button class="modal-close" onclick="closeHabitModal()">×</button>
      </div>
      <form class="modal-form" id="habitForm">
        <div>
          <label class="modal-label">Habit Name</label>
          <input type="text" class="modal-input" id="habit-title" placeholder="e.g., Morning Run" required>
        </div>
        
        <div>
          <label class="modal-label">Frequency</label>
          <div class="modal-frequency-row">
            <button type="button" class="modal-freq-btn" onclick="adjustFrequency(-1)">-</button>
            <input type="number" class="modal-freq-input" id="freq-num" value="1" min="1" max="7">
            <span class="modal-freq-label">times per week</span>
            <button type="button" class="modal-freq-btn" onclick="adjustFrequency(1)">+</button>
          </div>
        </div>
        
        <div>
          <label class="modal-label">Color</label>
          <div class="modal-color-row">
            <button type="button" class="modal-color-btn selected" data-color="#ffb44c" style="background: #ffb44c;" onclick="selectColor(this)"></button>
            <button type="button" class="modal-color-btn" data-color="#ff4c60" style="background: #ff4c60;" onclick="selectColor(this)"></button>
            <button type="button" class="modal-color-btn" data-color="#6f8cff" style="background: #6f8cff;" onclick="selectColor(this)"></button>
            <button type="button" class="modal-color-btn" data-color="#00c9a7" style="background: #00c9a7;" onclick="selectColor(this)"></button>
            <button type="button" class="modal-color-btn" data-color="#8b5cf6" style="background: #8b5cf6;" onclick="selectColor(this)"></button>
            <button type="button" class="modal-color-btn" data-color="#f59e0b" style="background: #f59e0b;" onclick="selectColor(this)"></button>
            <button type="button" class="modal-color-btn" data-color="#ef4444" style="background: #ef4444;" onclick="selectColor(this)"></button>
          </div>
        </div>
        
        <div>
          <label class="modal-label">Icon</label>
          <div class="icon-picker">
            <div class="icon-grid" id="iconGrid">
              <button type="button" class="modal-icon-btn selected" onclick="selectIcon(this)">🏃</button>
              <button type="button" class="modal-icon-btn" onclick="selectIcon(this)">📚</button>
              <button type="button" class="modal-icon-btn" onclick="selectIcon(this)">💧</button>
              <button type="button" class="modal-icon-btn" onclick="selectIcon(this)">🧘</button>
              <button type="button" class="modal-icon-btn" onclick="selectIcon(this)">🏋️</button>
              <button type="button" class="modal-icon-btn" onclick="selectIcon(this)">🎨</button>
              <button type="button" class="modal-icon-btn" onclick="selectIcon(this)">🎵</button>
              <button type="button" class="modal-icon-btn" onclick="selectIcon(this)">✍️</button>
              <button type="button" class="modal-icon-btn" onclick="selectIcon(this)">🌱</button>
              <button type="button" class="modal-icon-btn" onclick="selectIcon(this)">🍎</button>
              <button type="button" class="modal-icon-btn" onclick="selectIcon(this)">😴</button>
              <button type="button" class="modal-icon-btn" onclick="selectIcon(this)">🌅</button>
              <button type="button" class="modal-icon-btn" onclick="selectIcon(this)">🚶</button>
              <button type="button" class="modal-icon-btn" onclick="selectIcon(this)">🧠</button>
            </div>
          </div>
        </div>
        
        <button type="submit" class="modal-create-btn" id="submitHabitBtn">Create Habit</button>
      </form>
    </div>
  </div>

  <!-- Habit Detail Modal -->
  <div id="habitDetailModal" class="modal-bg" style="display: none;">
    <div class="modal-card habit-detail-modal">
      <div class="modal-header">
        <h2 class="modal-title">Habit Details</h2>
        <button class="modal-close" onclick="closeHabitDetailModal()">×</button>
      </div>
      
      <div class="habit-detail-header">
        <div class="habit-detail-icon" id="habitDetailIcon"></div>
        <div class="habit-detail-title" id="habitDetailTitle"></div>
      </div>
      
      <div class="quick-actions">
        <button class="quick-action" id="yesterdayBtn" onclick="selectQuickDay(-1)">Yesterday</button>
        <button class="quick-action active" id="todayBtn" onclick="selectQuickDay(0)">Today</button>
        <button class="quick-action" id="tomorrowBtn" onclick="selectQuickDay(1)">Tomorrow</button>
      </div>
      
      <button class="habit-action-btn" id="habitActionBtn" onclick="toggleCurrentHabitCompletion()">
        Mark as Complete
      </button>
      
      <button class="habit-secondary-btn" onclick="openStatisticsModal()">View Statistics</button>
      <button class="habit-secondary-btn" onclick="editHabit()">Edit Habit</button>
      <button class="habit-delete-btn" onclick="deleteHabit()">Delete Habit</button>
    </div>
  </div>

  <!-- Statistics Modal -->
  <div id="statisticsModal" class="modal-bg" style="display: none;">
    <div class="modal-card stats-modal">
      <div class="modal-header">
        <h2 class="modal-title">Statistics</h2>
        <button class="modal-close" onclick="closeStatisticsModal()">×</button>
      </div>
      
      <div class="stats-header">
        <div class="stats-year"><?php echo $currentYear; ?></div>
      </div>
      
      <div class="stats-row">
        <div class="stat-card">
          <div class="stat-label">Current Streak</div>
          <div class="stat-value stat-current" id="currentStreak">0</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Longest Streak</div>
          <div class="stat-value stat-longest" id="longestStreak">0</div>
        </div>
      </div>
      
      <div class="total-completed">
        <div class="total-title">Total Completed</div>
        <div class="total-value" id="totalCompleted">0</div>
      </div>
    </div>
  </div>

  <!-- Achievements Modal -->
  <div id="achievementsModal" class="modal-bg" style="display: none;">
    <div class="modal-card achievements-modal">
      <div class="modal-header">
        <h2 class="modal-title">Achievements</h2>
        <button class="modal-close" onclick="closeAchievementsModal()">×</button>
      </div>
      
      <div class="achievements-grid" id="achievementsGrid">
        <!-- Achievements will be populated by JavaScript -->
      </div>
      
      <div class="secret-achievements" id="secretAchievements">
        <h3 class="secret-title">Secret Achievements</h3>
        <!-- Secret achievements will be populated by JavaScript -->
      </div>
    </div>
  </div>

  <!-- Notes Modal -->
  <div id="notesModal" class="modal-bg" style="display: none;">
    <div class="modal-card notes-modal">
      <div class="modal-header">
        <h2 class="modal-title">Daily Notes</h2>
        <button class="modal-close" onclick="closeNotesModal()">×</button>
      </div>
      
      <div class="notes-header">
        <div class="notes-date-picker">
          <button class="date-nav-btn" onclick="changeNotesDate(-1)">‹</button>
          <div class="notes-date" id="notesDate"></div>
          <button class="date-nav-btn" onclick="changeNotesDate(1)">›</button>
        </div>
      </div>
      
      <textarea class="notes-textarea" id="notesTextarea" placeholder="What's on your mind today?"></textarea>
      
      <div class="notes-actions">
        <button class="notes-save-btn" onclick="saveNotes()">Save Note</button>
        <button class="notes-delete-btn" onclick="deleteNote()">Delete</button>
      </div>
    </div>
  </div>

  <script>
    // Initialize variables
    let habits = <?php echo json_encode($habits); ?>;
    let currentHabitId = null;
    let editingHabitId = null;
    let selectedDay = new Date().getDate();
    let selectedColor = '#ffb44c';
    let selectedIcon = '🏃';
    let currentMonth = <?php echo $currentMonth; ?>;
    let currentYear = <?php echo $currentYear; ?>;
    let notesDate = new Date();
    let userAchievements = <?php echo json_encode($achievements); ?>;

    // Month names for display
    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                       'July', 'August', 'September', 'October', 'November', 'December'];

    // Achievement levels with updated definitions
    const ACHIEVEMENT_LEVELS = [
      { name: 'Getting Started', days: 3, emoji: '🌱', type: 'streak' },
      { name: '7-Day Streak', days: 7, emoji: '⚡', type: 'streak' },
      { name: 'Fortnight Fighter', days: 14, emoji: '🔥', type: 'streak' },
      { name: 'Consistency King', days: 21, emoji: '🔄', type: 'streak' },
      { name: 'Month Master', days: 30, emoji: '💪', type: 'streak' },
      { name: 'Quarter Champion', days: 90, emoji: '🏆', type: 'streak' },
      { name: 'Half-Year Hero', days: 180, emoji: '🎯', type: 'streak' },
      { name: 'Year Legend', days: 365, emoji: '👑', type: 'streak' }
    ];

    const COMPLETION_ACHIEVEMENTS = [
      { name: 'Habit Master', count: 50, emoji: '🎨', type: 'completion' },
      { name: 'Dedication Champion', count: 100, emoji: '🧘', type: 'completion' },
      { name: 'Consistency Expert', count: 250, emoji: '⭐', type: 'completion' },
      { name: 'Habit Legend', count: 500, emoji: '🎯', type: 'completion' },
      { name: 'Ultimate Master', count: 1000, emoji: '👑', type: 'completion' }
    ];

    const CREATION_ACHIEVEMENTS = [
      { name: 'Planner', count: 5, emoji: '📋', type: 'habit_creator' },
      { name: 'Organizer', count: 10, emoji: '🗂️', type: 'habit_creator' },
      { name: 'Life Designer', count: 20, emoji: '🎨', type: 'habit_creator' }
    ];

    // Event listeners
    document.addEventListener('DOMContentLoaded', function() {
      updateNotesDate();
      loadUserAchievements();
      
      // Set up navbar icon events
      document.getElementById('addHabitBtn').addEventListener('click', function() {
        openHabitModal();
        setActiveNavIcon('addHabitBtn');
      });
      
      document.getElementById('notesBtn').addEventListener('click', function() {
        openNotesModal();
        setActiveNavIcon('notesBtn');
      });
      
      document.getElementById('achievementsBtn').addEventListener('click', function() {
        openAchievementsModal();
        setActiveNavIcon('achievementsBtn');
      });
      
      // Set up habit form submission
      document.getElementById('habitForm').addEventListener('submit', function(e) {
        e.preventDefault();
        submitHabit();
      });
    });

    // Function to load user achievements from server
    function loadUserAchievements() {
      fetch('dashboard.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'action=get_achievements'
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          userAchievements = data.achievements;
          updateAchievements();
        }
      })
      .catch(error => {
        console.error('Error loading achievements:', error);
      });
    }

    // Logout function
    function logout() {
      if (confirm('Are you sure you want to logout?')) {
        window.location.href = 'auth/logout.php';
      }
    }

    // Enhanced calendar navigation functions
    function changeMonth(delta) {
      currentMonth += delta;
      
      if (currentMonth > 12) {
        currentMonth = 1;
        currentYear++;
      } else if (currentMonth < 1) {
        currentMonth = 12;
        currentYear--;
      }
      
      updateCalendarDisplay();
    }

    function changeYear(delta) {
      currentYear += delta;
      updateCalendarDisplay();
    }

    function updateCalendarDisplay() {
      // Update the display
      document.getElementById('currentMonthYear').textContent = monthNames[currentMonth - 1] + ' ' + currentYear;
      
      // Reload the page with new month/year parameters
      const url = new URL(window.location);
      url.searchParams.set('month', currentMonth);
      url.searchParams.set('year', currentYear);
      window.location.href = url.toString();
    }

    // Notes date navigation
    function changeNotesDate(delta) {
      notesDate.setDate(notesDate.getDate() + delta);
      updateNotesDate();
      loadNoteForDate();
    }

    function updateNotesDate() {
      const options = { 
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
      };
      const dateStr = notesDate.toLocaleDateString('en-US', options);
      document.getElementById('notesDate').textContent = dateStr;
    }

    function loadNoteForDate() {
      const dateStr = notesDate.toISOString().split('T')[0];
      
      const formData = new FormData();
      formData.append('action', 'get_note');
      formData.append('date', dateStr);
      
      fetch('dashboard.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          document.getElementById('notesTextarea').value = data.content;
        }
      })
      .catch(error => {
        console.error('Error loading note:', error);
      });
    }

    function deleteNote() {
      if (confirm('Are you sure you want to delete this note?')) {
        const dateStr = notesDate.toISOString().split('T')[0];
        
        const formData = new FormData();
        formData.append('action', 'delete_note');
        formData.append('date', dateStr);
        
        fetch('dashboard.php', {
          method: 'POST',
          body: formData
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            document.getElementById('notesTextarea').value = '';
            showToast('Note deleted successfully!', 'success');
          } else {
            showToast(data.error || 'Failed to delete note', 'error');
          }
        })
        .catch(error => {
          console.error('Error deleting note:', error);
          showToast('An error occurred', 'error');
        });
      }
    }

    function setActiveNavIcon(activeId) {
      document.querySelectorAll('.navbar-icon').forEach(icon => {
        icon.classList.remove('active');
      });
      document.getElementById(activeId).classList.add('active');
    }

    function openHabitModal() {
      resetHabitForm();
      document.getElementById('habitModal').style.display = 'flex';
    }

    function closeHabitModal() {
      document.getElementById('habitModal').style.display = 'none';
      editingHabitId = null;
    }

    function resetHabitForm() {
      document.getElementById('modalTitle').textContent = 'Create New Habit';
      document.getElementById('submitHabitBtn').textContent = 'Create Habit';
      document.getElementById('habit-title').value = '';
      document.getElementById('freq-num').value = '1';
      
      // Reset color selection
      document.querySelectorAll('.modal-color-btn').forEach(btn => {
        btn.classList.remove('selected');
        if (btn.dataset.color === '#ffb44c') {
          btn.classList.add('selected');
        }
      });
      
      // Reset icon selection
      document.querySelectorAll('.modal-icon-btn').forEach(btn => {
        btn.classList.remove('selected');
        if (btn.textContent === '🏃') {
          btn.classList.add('selected');
        }
      });
      
      selectedColor = '#ffb44c';
      selectedIcon = '🏃';
    }

    function selectColor(button) {
      document.querySelectorAll('.modal-color-btn').forEach(btn => {
        btn.classList.remove('selected');
      });
      button.classList.add('selected');
      selectedColor = button.dataset.color;
    }

    function selectIcon(button) {
      document.querySelectorAll('.modal-icon-btn').forEach(btn => {
        btn.classList.remove('selected');
      });
      button.classList.add('selected');
      selectedIcon = button.textContent;
    }

    function adjustFrequency(change) {
      const input = document.getElementById('freq-num');
      const current = parseInt(input.value);
      const newValue = Math.max(1, Math.min(7, current + change));
      input.value = newValue;
    }

    function submitHabit() {
      const name = document.getElementById('habit-title').value.trim();
      const frequency = parseInt(document.getElementById('freq-num').value);
      
      if (!name) {
        showToast('Please enter a habit name', 'error');
        return;
      }
      
      const formData = new FormData();
      formData.append('action', editingHabitId ? 'update_habit' : 'create_habit');
      formData.append('name', name);
      formData.append('frequency', frequency);
      formData.append('color', selectedColor);
      formData.append('icon_svg', selectedIcon);
      
      if (editingHabitId) {
        formData.append('habit_id', editingHabitId);
      }
      
      fetch('dashboard.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          showToast(editingHabitId ? 'Habit updated successfully!' : 'Habit created successfully!', 'success');
          closeHabitModal();
          
          // Reload achievements
          loadUserAchievements();
          
          // Refresh to show updated habits
          setTimeout(() => {
            window.location.reload();
          }, 1000);
        } else {
          showToast(data.error || 'An error occurred', 'error');
        }
      })
      .catch(error => {
        console.error('Error:', error);
        showToast('An error occurred', 'error');
      });
    }

    function openHabitDetailModal(habitId) {
      currentHabitId = habitId;
      const habit = habits.find(h => h.id === habitId);
      
      if (!habit) return;
      
      document.getElementById('habitDetailIcon').style.background = habit.color;
      document.getElementById('habitDetailIcon').innerHTML = habit.icon_svg;
      document.getElementById('habitDetailTitle').textContent = habit.name;
      
      updateHabitActionButton();
      document.getElementById('habitDetailModal').style.display = 'flex';
    }

    function closeHabitDetailModal() {
      document.getElementById('habitDetailModal').style.display = 'none';
      currentHabitId = null;
    }

    function updateHabitActionButton() {
      if (!currentHabitId) return;
      
      const habit = habits.find(h => h.id === currentHabitId);
      if (!habit) return;
      
      const today = new Date();
      const currentDate = today.getFullYear() + '-' + 
                         String(today.getMonth() + 1).padStart(2, '0') + '-' + 
                         String(selectedDay).padStart(2, '0');
      
      const isCompleted = habit.all_completions.includes(currentDate);
      const actionBtn = document.getElementById('habitActionBtn');
      
      if (isCompleted) {
        actionBtn.textContent = 'Mark as Incomplete';
        actionBtn.classList.add('completed');
      } else {
        actionBtn.textContent = 'Mark as Complete';
        actionBtn.classList.remove('completed');
      }
    }

    function toggleCurrentHabitCompletion() {
      if (!currentHabitId) return;
      
      const habit = habits.find(h => h.id === currentHabitId);
      if (!habit) return;
      
      const today = new Date();
      const currentDate = today.getFullYear() + '-' + 
                         String(today.getMonth() + 1).padStart(2, '0') + '-' + 
                         String(selectedDay).padStart(2, '0');
      
      const isCompleted = habit.all_completions.includes(currentDate);
      
      const formData = new FormData();
      formData.append('action', 'toggle_habit_completion');
      formData.append('habit_id', currentHabitId);
      formData.append('date', currentDate);
      formData.append('completed', !isCompleted);
      
      fetch('dashboard.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          // Update local data
          if (isCompleted) {
            habit.all_completions = habit.all_completions.filter(d => d !== currentDate);
          } else {
            habit.all_completions.push(currentDate);
          }
          
          // Update UI
          updateHabitActionButton();
          updateHabitCard(habit);
          
          // Reload achievements
          loadUserAchievements();
          
          showToast(isCompleted ? 'Marked as incomplete' : 'Marked as complete!', 'success');
        } else {
          showToast(data.error || 'An error occurred', 'error');
        }
      })
      .catch(error => {
        console.error('Error:', error);
        showToast('An error occurred', 'error');
      });
    }

    // Enhanced habit completion toggle by date
    function toggleHabitCompletionByDate(habitId, dateString, cellElement) {
      // Validate that this is a valid date cell
      if (!cellElement.dataset.date || !cellElement.dataset.habitId) {
        console.log('Invalid calendar cell clicked');
        return;
      }
      
      // Check if this is a future date (only for current month, allow past dates from other months)
      const clickedDate = new Date(dateString);
      const today = new Date();
      today.setHours(23, 59, 59, 999); // Set to end of today for comparison
      
      if (clickedDate > today && !cellElement.classList.contains('other-month')) {
        console.log('Cannot click on future dates in current month');
        return;
      }
      
      const habit = habits.find(h => h.id === habitId);
      if (!habit) return;
      
      const isCompleted = habit.all_completions.includes(dateString);
      
      const formData = new FormData();
      formData.append('action', 'toggle_habit_completion');
      formData.append('habit_id', habitId);
      formData.append('date', dateString);
      formData.append('completed', !isCompleted);
      
      fetch('dashboard.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          // Update local data
          if (isCompleted) {
            habit.all_completions = habit.all_completions.filter(d => d !== dateString);
            cellElement.classList.remove('done');
          } else {
            habit.all_completions.push(dateString);
            cellElement.classList.add('done');
          }
          
          // Update habit card
          updateHabitCard(habit);
          
          // Reload achievements after completion
          loadUserAchievements();
          
          showToast(isCompleted ? 'Marked as incomplete' : 'Marked as complete!', 'success');
        } else {
          showToast(data.error || 'An error occurred', 'error');
        }
      })
      .catch(error => {
        console.error('Error:', error);
        showToast('An error occurred', 'error');
      });
    }

    function selectQuickDay(offset) {
      const today = new Date().getDate();
      selectedDay = today + offset;
      
      // Update button states
      document.querySelectorAll('.quick-action').forEach(btn => {
        btn.classList.remove('active');
      });
      
      if (offset === -1) document.getElementById('yesterdayBtn').classList.add('active');
      else if (offset === 0) document.getElementById('todayBtn').classList.add('active');
      else if (offset === 1) document.getElementById('tomorrowBtn').classList.add('active');
      
      updateHabitActionButton();
    }

    function openNotesModal() {
      notesDate = new Date();
      updateNotesDate();
      loadNoteForDate();
      document.getElementById('notesModal').style.display = 'flex';
    }

    function closeNotesModal() {
      document.getElementById('notesModal').style.display = 'none';
    }

    function saveNotes() {
      const content = document.getElementById('notesTextarea').value;
      const dateStr = notesDate.toISOString().split('T')[0];
      
      const formData = new FormData();
      formData.append('action', 'save_note');
      formData.append('date', dateStr);
      formData.append('content', content);
      
      fetch('dashboard.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          showToast('Note saved successfully!', 'success');
        } else {
          showToast(data.error || 'Failed to save note', 'error');
        }
      })
      .catch(error => {
        console.error('Error saving note:', error);
        showToast('An error occurred', 'error');
      });
    }

    function openAchievementsModal() {
      updateAchievements();
      document.getElementById('achievementsModal').style.display = 'flex';
    }

    function closeAchievementsModal() {
      document.getElementById('achievementsModal').style.display = 'none';
    }

    function openStatisticsModal() {
      if (!currentHabitId) return;
      
      const habit = habits.find(h => h.id === currentHabitId);
      if (!habit) return;
      
      document.getElementById('currentStreak').textContent = habit.current_streak;
      document.getElementById('longestStreak').textContent = habit.longest_streak;
      document.getElementById('totalCompleted').textContent = habit.completed_days.length;
      
      document.getElementById('statisticsModal').style.display = 'flex';
    }

    function closeStatisticsModal() {
      document.getElementById('statisticsModal').style.display = 'none';
    }

    function editHabit() {
      if (!currentHabitId) return;
      
      const habit = habits.find(h => h.id === currentHabitId);
      if (!habit) return;
      
      // Set edit mode
      editingHabitId = currentHabitId;
      
      // Update modal title and button
      document.getElementById('modalTitle').textContent = 'Edit Habit';
      document.getElementById('submitHabitBtn').textContent = 'Update Habit';
      
      // Pre-fill form with habit data
      document.getElementById('habit-title').value = habit.name;
      document.getElementById('freq-num').value = habit.frequency;
      
      // Pre-select color
      document.querySelectorAll('.modal-color-btn').forEach(btn => {
        btn.classList.remove('selected');
        if (btn.dataset.color === habit.color) {
          btn.classList.add('selected');
        }
      });
      selectedColor = habit.color;
      
      // Pre-select icon
      document.querySelectorAll('.modal-icon-btn').forEach(btn => {
        btn.classList.remove('selected');
        if (btn.textContent === habit.icon_svg) {
          btn.classList.add('selected');
        }
      });
      selectedIcon = habit.icon_svg;
      
      // Close detail modal and open edit modal
      closeHabitDetailModal();
      document.getElementById('habitModal').style.display = 'flex';
      setActiveNavIcon('addHabitBtn');
    }

    function deleteHabit() {
      if (!currentHabitId) return;
      
      if (confirm('Are you sure you want to delete this habit? This action cannot be undone.')) {
        const formData = new FormData();
        formData.append('action', 'delete_habit');
        formData.append('habit_id', currentHabitId);
        
        fetch('dashboard.php', {
          method: 'POST',
          body: formData
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            showToast('Habit deleted successfully!', 'success');
            closeHabitDetailModal();
            window.location.reload(); // Refresh to show updated habits
          } else {
            showToast(data.error || 'Failed to delete habit', 'error');
          }
        })
        .catch(error => {
          console.error('Error deleting habit:', error);
          showToast('An error occurred', 'error');
        });
      }
    }

    function updateHabitCard(habit) {
      const habitCard = document.querySelector(`[data-habit-id="${habit.id}"]`);
      if (!habitCard) return;
      
      const daysInMonth = new Date(currentYear, currentMonth, 0).getDate();
      const completedCount = habit.completed_days.length;
      const percentage = Math.round((completedCount / daysInMonth) * 100);
      const progressOffset = 100 - percentage;
      
      // Update habit name, streak, progress ring and percentage inside
      habitCard.querySelector('.habit-name').textContent = habit.name;
      habitCard.querySelector('.habit-icon').style.background = habit.color;
      habitCard.querySelector('.habit-icon').innerHTML = habit.icon_svg;
      habitCard.querySelector('.habit-streak').textContent = `🔥 ${habit.current_streak}`;
      
      const progressCircle = habitCard.querySelector('.progress-circle');
      const progressText = habitCard.querySelector('.progress-text');
      progressCircle.style.strokeDashoffset = progressOffset;
      progressText.textContent = `${percentage}%`;
      
      // Update calendar cells
      const cells = habitCard.querySelectorAll('.cal-cell[data-date]');
      cells.forEach(cell => {
        const dateString = cell.dataset.date;
        const isCompleted = habit.all_completions.includes(dateString);
        cell.classList.toggle('done', isCompleted);
      });
    }

    function calculateGlobalStats() {
      if (habits.length === 0) {
        return {
          maxCurrentStreak: 0,
          maxLongestStreak: 0,
          totalCompletions: 0,
          bestStreak: 0,
          totalHabits: 0
        };
      }
      
      let maxCurrentStreak = 0;
      let maxLongestStreak = 0;
      let totalCompletions = 0;
      
      habits.forEach(habit => {
        maxCurrentStreak = Math.max(maxCurrentStreak, habit.current_streak);
        maxLongestStreak = Math.max(maxLongestStreak, habit.longest_streak);
        totalCompletions += habit.all_completions.length;
      });
      
      return {
        maxCurrentStreak,
        maxLongestStreak,
        totalCompletions,
        bestStreak: Math.max(maxCurrentStreak, maxLongestStreak),
        totalHabits: habits.length
      };
    }

    function updateAchievements() {
      const stats = calculateGlobalStats();
      const achievementsGrid = document.getElementById('achievementsGrid');
      const secretAchievements = document.getElementById('secretAchievements');
      
      // Clear existing content
      achievementsGrid.innerHTML = '';
      
      // Combine all achievement types
      const allAchievements = [
        ...ACHIEVEMENT_LEVELS.map(a => ({...a, requirement: a.days})),
        ...COMPLETION_ACHIEVEMENTS.map(a => ({...a, requirement: a.count})),
        ...CREATION_ACHIEVEMENTS.map(a => ({...a, requirement: a.count}))
      ];
      
      // Generate achievement cards
      allAchievements.forEach(achievement => {
        let isUnlocked = false;
        let progress = 0;
        
        // Check if achievement is unlocked based on type
        switch(achievement.type) {
          case 'streak':
            isUnlocked = stats.bestStreak >= achievement.requirement;
            progress = Math.min(stats.bestStreak, achievement.requirement);
            break;
          case 'completion':
            isUnlocked = stats.totalCompletions >= achievement.requirement;
            progress = Math.min(stats.totalCompletions, achievement.requirement);
            break;
          case 'habit_creator':
            isUnlocked = stats.totalHabits >= achievement.requirement;
            progress = Math.min(stats.totalHabits, achievement.requirement);
            break;
        }
        
        // Check if this achievement is already unlocked in database
        const dbAchievement = userAchievements.find(a => 
          a.achievement_name === achievement.name || 
          (a.achievement_type === achievement.type && a.level_achieved >= progress)
        );
        
        if (dbAchievement) {
          isUnlocked = true;
        }
        
        const achievementCard = document.createElement('div');
        achievementCard.className = `achievement-card ${isUnlocked ? 'unlocked' : ''}`;
        
        achievementCard.innerHTML = `
          <div class="achievement-icon ${isUnlocked ? 'unlocked' : 'locked'}">
            ${achievement.emoji}
          </div>
          <div class="achievement-label ${isUnlocked ? '' : 'locked'}">
            ${achievement.name}
          </div>
          <div class="achievement-progress ${isUnlocked ? 'unlocked' : ''}">
            ${isUnlocked ? 'Completed!' : `${progress}/${achievement.requirement}`}
          </div>
        `;
        
        achievementsGrid.appendChild(achievementCard);
      });
      
      // Show secret achievements if user has earned enough
      if (stats.bestStreak >= 7 || userAchievements.length > 0) {
        secretAchievements.style.display = 'block';
      } else {
        secretAchievements.style.display = 'none';
      }
    }

    function showToast(message, type = 'success') {
      const toast = document.createElement('div');
      toast.className = `toast ${type}`;
      toast.textContent = message;
      document.body.appendChild(toast);
      
      setTimeout(() => toast.classList.add('show'), 100);
      setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => document.body.removeChild(toast), 300);
      }, 3000);
    }

    function showAchievementNotification(achievementName) {
      const notification = document.createElement('div');
      notification.className = 'toast achievement show';
      notification.innerHTML = `
        <div style="display: flex; align-items: center; gap: 8px;">
          <span style="font-size: 1.2rem;">🏆</span>
          <span><strong>Achievement Unlocked!</strong><br>${achievementName}</span>
        </div>
      `;
      document.body.appendChild(notification);
      
      // Add confetti effect
      createConfetti();
      
      setTimeout(() => {
        notification.classList.remove('show');
        setTimeout(() => document.body.removeChild(notification), 300);
      }, 4000);
    }

    function createConfetti() {
      const colors = ['#ffb44c', '#ff4c60', '#6f8cff', '#00c9a7', '#8b5cf6'];
      
      for (let i = 0; i < 50; i++) {
        const confetti = document.createElement('div');
        confetti.style.cssText = `
          position: fixed;
          width: 8px;
          height: 8px;
          background: ${colors[Math.floor(Math.random() * colors.length)]};
          top: -10px;
          left: ${Math.random() * 100}vw;
          z-index: 5000;
          pointer-events: none;
          animation: confettiFall 3s linear forwards;
        `;
        
        document.body.appendChild(confetti);
        
        setTimeout(() => {
          if (confetti.parentNode) {
            confetti.parentNode.removeChild(confetti);
          }
        }, 3000);
      }
    }

    // Add confetti animation styles
    const style = document.createElement('style');
    style.textContent = `
      @keyframes confettiFall {
        to {
          transform: translateY(100vh) rotate(360deg);
          opacity: 0;
        }
      }
    `;
    document.head.appendChild(style);

    // Initialize with current day selection
    selectedDay = new Date().getDate();
  </script>
</body>
</html>
