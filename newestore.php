<?php
session_start();

// --- DATABASE CONNECTIVITY & SETUP ---
$host = 'localhost';
$user = 'root';
$pass = ''; // Default XAMPP password is empty
$db   = 'aurax_db';

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);

try {
    // Connect without DB first to create it if it doesn't exist
    $conn = new mysqli($host, $user, $pass);
    $conn->query("CREATE DATABASE IF NOT EXISTS $db");
    $conn->select_db($db);

    // Create Users Table
    $conn->query("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role ENUM('user', 'admin') DEFAULT 'user'
    )");

    // Insert Default Admin if no users exist
    $res = $conn->query("SELECT id FROM users");
    if ($res->num_rows === 0) {
        $hashed_pw = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'admin')");
        $admin_user = 'admin';
        $stmt->bind_param("ss", $admin_user, $hashed_pw);
        $stmt->execute();
    }

    // Create Products Table
    $conn->query("CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        price DECIMAL(10,2) NOT NULL,
        category VARCHAR(50) NOT NULL,
        image VARCHAR(255) NOT NULL
    )");

    // Insert Mock Products if empty
    $res = $conn->query("SELECT id FROM products");
    if ($res->num_rows === 0) {
        $mock_products = [
            ['Neon Chrono Watch', 299.00, 'Accessories', 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?q=80&w=800&auto=format&fit=crop'],
            ['Aero Kicks V2', 189.00, 'Footwear', 'https://images.unsplash.com/photo-1552346154-21d32810baa3?q=80&w=800&auto=format&fit=crop'],
            ['Cyberpunk Jacket', 349.00, 'Apparel', 'https://images.unsplash.com/photo-1551028719-0141bb623ce1?q=80&w=800&auto=format&fit=crop'],
            ['Holo Glasses', 129.00, 'Eyewear', 'https://images.unsplash.com/photo-1511499767150-a48a237f0083?q=80&w=800&auto=format&fit=crop'],
            ['Quantum Earbuds', 159.00, 'Audio', 'https://images.unsplash.com/photo-1590658268037-6f1115ea9081?q=80&w=800&auto=format&fit=crop']
        ];
        $stmt = $conn->prepare("INSERT INTO products (name, price, category, image) VALUES (?, ?, ?, ?)");
        foreach ($mock_products as $p) {
            $stmt->bind_param("sdss", $p[0], $p[1], $p[2], $p[3]);
            $stmt->execute();
        }
    }
    
    $db_connected = true;
} catch (Exception $e) {
    $db_connected = false;
    $db_error = $e->getMessage();
}

// --- AUTHENTICATION LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($db_connected) {
        $stmt = $conn->prepare("SELECT id, role, password FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($user_row = $res->fetch_assoc()) {
            if (password_verify($password, $user_row['password'])) {
                $_SESSION['user_id'] = $user_row['id'];
                $_SESSION['role'] = $user_row['role'];
                $_SESSION['username'] = $username;
                header("Location: ?page=" . ($user_row['role'] === 'admin' ? 'admin' : 'home'));
                exit;
            }
        }
        $login_error = "Invalid credentials!";
    } else {
        $login_error = "Database offline. Login unavailable.";
    }
}

// Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: ?page=home");
    exit;
}

// Fetch Products
$products = [];
if ($db_connected) {
    $result = $conn->query("SELECT * FROM products");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $products[$row['id']] = $row;
        }
    }
}

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Router
$page = $_GET['page'] ?? 'home';

// Handle AJAX requests for Cart Operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] !== 'login') {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'add') {
        $id = (int)$_POST['id'];
        if (isset($products[$id])) {
            if (isset($_SESSION['cart'][$id])) {
                $_SESSION['cart'][$id]++;
            } else {
                $_SESSION['cart'][$id] = 1;
            }
            echo json_encode(['success' => true, 'cart_count' => array_sum($_SESSION['cart'])]);
            exit;
        }
    } elseif ($action === 'remove') {
        $id = (int)$_POST['id'];
        if (isset($_SESSION['cart'][$id])) {
            unset($_SESSION['cart'][$id]);
            echo json_encode(['success' => true, 'cart_count' => array_sum($_SESSION['cart'])]);
            exit;
        }
    } elseif ($action === 'update_qty') {
        $id = (int)$_POST['id'];
        $qty = (int)$_POST['qty'];
        if (isset($_SESSION['cart'][$id]) && $qty > 0) {
            $_SESSION['cart'][$id] = $qty;
        } elseif ($qty <= 0) {
            unset($_SESSION['cart'][$id]);
        }
        echo json_encode(['success' => true, 'cart_count' => array_sum($_SESSION['cart'])]);
        exit;
    } elseif ($action === 'get_cart') {
        $cart_items = [];
        $total = 0;
        foreach ($_SESSION['cart'] as $id => $quantity) {
            if (isset($products[$id])) {
                $item = $products[$id];
                $item['quantity'] = $quantity;
                $item['subtotal'] = $quantity * $item['price'];
                $cart_items[$id] = $item;
                $total += $item['subtotal'];
            }
        }
        echo json_encode([
            'items' => $cart_items, 
            'total' => $total, 
            'cart_count' => array_sum($_SESSION['cart'])
        ]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AURA X | Premium Tech & Fashion</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ----- CSS VARIABLES & THEMES ----- */
        :root {
            /* Dark Theme (Default) */
            --bg-color: #050505;
            --bg-secondary: #0a0a0a;
            --text-color: #f0f0f0;
            --text-muted: #888888;
            --accent-glow: #00f0ff;
            --secondary-glow: #ff003c;
            --glass-bg: rgba(255, 255, 255, 0.03);
            --glass-border: rgba(255, 255, 255, 0.08);
            --nav-bg: rgba(5, 5, 5, 0.8);
            --shadow-color: rgba(0, 240, 255, 0.2);
            --invert-filter: invert(0);
            
            --font-main: 'Outfit', sans-serif;
            --font-display: 'Space Grotesk', sans-serif;
            --transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }

        [data-theme="light"] {
            /* Light Theme overrides */
            --bg-color: #f8f9fa;
            --bg-secondary: #ffffff;
            --text-color: #111111;
            --text-muted: #555555;
            --accent-glow: #0056b3;
            --secondary-glow: #d90429;
            --glass-bg: rgba(0, 0, 0, 0.03);
            --glass-border: rgba(0, 0, 0, 0.1);
            --nav-bg: rgba(255, 255, 255, 0.9);
            --shadow-color: rgba(0, 0, 0, 0.1);
            --invert-filter: invert(1) hue-rotate(180deg);
        }

        /* ----- RESET & BASE ----- */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: var(--font-main);
        }

        html { scroll-behavior: smooth; }

        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            overflow-x: hidden;
            transition: background-color 0.5s ease, color 0.5s ease;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background-image: 
                radial-gradient(circle at 15% 50%, rgba(0, 240, 255, 0.05) 0%, transparent 40%),
                radial-gradient(circle at 85% 30%, rgba(255, 0, 60, 0.05) 0%, transparent 40%);
            pointer-events: none;
            z-index: -1;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: var(--bg-color); }
        ::-webkit-scrollbar-thumb { background: var(--glass-border); border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--accent-glow); }

        /* ----- PRELOADER ----- */
        #preloader {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: var(--bg-color);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            transition: opacity 0.5s ease;
        }
        .loader {
            width: 50px;
            height: 50px;
            border: 3px solid var(--glass-border);
            border-top-color: var(--accent-glow);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { 100% { transform: rotate(360deg); } }

        /* ----- NAVIGATION ----- */
        nav {
            position: fixed;
            top: 0; width: 100%;
            padding: 20px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 1000;
            background: var(--nav-bg);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--glass-border);
            transition: var(--transition);
        }

        nav.scrolled {
            padding: 12px 5%;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .logo {
            font-family: var(--font-display);
            font-size: 28px;
            font-weight: 800;
            letter-spacing: 2px;
            text-transform: uppercase;
            background: linear-gradient(90deg, var(--accent-glow), var(--secondary-glow));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            cursor: pointer;
        }

        .nav-links { display: flex; gap: 35px; align-items: center; }

        .nav-links a {
            color: var(--text-color);
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            position: relative;
            padding: 5px 0;
            transition: var(--transition);
        }

        .nav-links a::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0;
            width: 0; height: 2px;
            background: var(--accent-glow);
            transition: var(--transition);
        }

        .nav-links a:hover { color: var(--accent-glow); }
        .nav-links a:hover::after { width: 100%; }

        .nav-actions { display: flex; gap: 20px; align-items: center; }

        .icon-btn {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            color: var(--text-color);
            font-size: 20px;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px; height: 40px;
            border-radius: 50%;
        }

        .icon-btn:hover {
            color: var(--accent-glow);
            transform: translateY(-2px);
            border-color: var(--accent-glow);
            box-shadow: 0 5px 15px var(--shadow-color);
        }

        .cart-count {
            position: absolute;
            top: -5px; right: -5px;
            background: var(--secondary-glow);
            color: white;
            font-size: 11px;
            font-weight: bold;
            height: 20px; width: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            border: 2px solid var(--bg-color);
        }

        /* ----- HERO SECTION ----- */
        .hero {
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 80px 10% 0;
            position: relative;
            overflow: hidden;
        }

        .hero-bg {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            background: url('https://images.unsplash.com/photo-1605806616949-1e87b487cb2a?q=80&w=2000&auto=format&fit=crop') center/cover;
            filter: var(--invert-filter) opacity(0.4);
            z-index: -1;
            transform: scale(1.05);
            animation: breathe 20s infinite alternate linear;
        }
        @keyframes breathe {
            0% { transform: scale(1.05); }
            100% { transform: scale(1.15); }
        }

        .hero-content {
            max-width: 650px;
            position: relative;
            z-index: 10;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 8px 16px;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 30px;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 25px;
            backdrop-filter: blur(10px);
        }

        .hero-badge i { color: var(--accent-glow); }

        .hero-title {
            font-family: var(--font-display);
            font-size: clamp(40px, 6vw, 80px);
            line-height: 1.05;
            font-weight: 800;
            margin-bottom: 25px;
            letter-spacing: -1px;
        }

        .hero-title span {
            background: linear-gradient(135deg, var(--accent-glow), var(--secondary-glow));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero-desc {
            font-size: 18px;
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 40px;
            max-width: 500px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 16px 40px;
            border: none;
            outline: none;
            font-size: 14px;
            font-weight: 600;
            letter-spacing: 2px;
            text-transform: uppercase;
            cursor: pointer;
            border-radius: 4px;
            transition: var(--transition);
            text-decoration: none;
            position: relative;
            overflow: hidden;
        }

        .btn-primary {
            background: var(--text-color);
            color: var(--bg-color);
        }

        .btn-primary:hover {
            background: var(--accent-glow);
            color: #fff;
            box-shadow: 0 10px 25px var(--shadow-color);
            transform: translateY(-3px);
        }

        .btn-outline {
            background: transparent;
            color: var(--text-color);
            border: 1px solid var(--glass-border);
            margin-left: 15px;
        }

        .btn-outline:hover {
            border-color: var(--accent-glow);
            background: var(--glass-bg);
        }

        /* ----- SCROLL ANIMATIONS (REVEAL) ----- */
        .reveal {
            opacity: 0;
            transform: translateY(50px);
            transition: all 0.8s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }
        .reveal.active {
            opacity: 1;
            transform: translateY(0);
        }

        /* ----- FEATURES SECTION ----- */
        .features {
            padding: 80px 10%;
            background: var(--bg-secondary);
            border-top: 1px solid var(--glass-border);
            border-bottom: 1px solid var(--glass-border);
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
        }

        .feature-box {
            text-align: center;
            padding: 30px;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            transition: var(--transition);
        }

        .feature-box:hover {
            transform: translateY(-10px);
            border-color: var(--accent-glow);
            box-shadow: 0 10px 30px var(--shadow-color);
        }

        .feature-icon {
            font-size: 40px;
            color: var(--accent-glow);
            margin-bottom: 20px;
        }

        .feature-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 10px;
            letter-spacing: 1px;
        }

        .feature-desc {
            font-size: 14px;
            color: var(--text-muted);
            line-height: 1.6;
        }

        /* ----- PRODUCTS SECTION ----- */
        .products-section {
            padding: 120px 10%;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 60px;
        }

        .section-title {
            font-family: var(--font-display);
            font-size: 45px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .section-subtitle {
            color: var(--accent-glow);
            font-size: 14px;
            letter-spacing: 3px;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 10px;
            display: block;
        }

        .category-filters {
            display: flex;
            gap: 15px;
        }

        .filter-btn {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            color: var(--text-color);
            padding: 8px 20px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: var(--transition);
        }

        .filter-btn:hover, .filter-btn.active {
            background: var(--text-color);
            color: var(--bg-color);
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 40px;
        }

        .product-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 20px;
            position: relative;
            transition: var(--transition);
            display: flex;
            flex-direction: column;
        }

        .product-card:hover {
            transform: translateY(-10px);
            border-color: var(--accent-glow);
            box-shadow: 0 20px 40px var(--shadow-color);
            background: var(--bg-secondary);
        }

        .product-badge {
            position: absolute;
            top: 30px; left: 30px;
            background: rgba(0,0,0,0.7);
            color: #fff;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 1px;
            z-index: 2;
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255,255,255,0.1);
        }

        .product-img-wrap {
            width: 100%;
            height: 280px;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 25px;
            position: relative;
        }

        .product-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.8s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }

        .product-card:hover .product-img {
            transform: scale(1.1) rotate(2deg);
        }

        .product-category {
            font-size: 12px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 5px;
        }

        .product-name {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 15px;
            font-family: var(--font-display);
        }

        .product-bottom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: auto;
            padding-top: 15px;
            border-top: 1px solid var(--glass-border);
        }

        .product-price {
            font-size: 24px;
            font-weight: 800;
            color: var(--text-color);
        }

        .add-cart-btn {
            background: var(--text-color);
            color: var(--bg-color);
            border: none;
            width: 40px; height: 40px;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            cursor: pointer;
            transition: var(--transition);
            font-size: 16px;
        }

        .add-cart-btn:hover {
            background: var(--accent-glow);
            color: #fff;
            transform: scale(1.1) rotate(90deg);
        }

        /* ----- ABOUT SECTION ----- */
        .about-section {
            padding: 120px 10%;
            background: var(--bg-secondary);
            display: flex;
            align-items: center;
            gap: 60px;
            border-top: 1px solid var(--glass-border);
        }

        .about-image { flex: 1; position: relative; }
        .about-image img {
            width: 100%;
            border-radius: 20px;
            filter: grayscale(100%);
            transition: var(--transition);
        }
        .about-image:hover img {
            filter: grayscale(0%);
            box-shadow: 0 20px 50px var(--shadow-color);
        }
        .about-content { flex: 1; }

        /* ----- NEWSLETTER ----- */
        .newsletter {
            padding: 100px 10%;
            text-align: center;
            background: linear-gradient(to bottom, var(--bg-color), var(--bg-secondary));
        }

        .news-form {
            max-width: 500px;
            margin: 40px auto 0;
            display: flex;
            position: relative;
        }

        .news-input {
            flex: 1;
            padding: 18px 25px;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 30px;
            color: var(--text-color);
            font-size: 16px;
            outline: none;
            transition: var(--transition);
        }

        .news-input:focus {
            border-color: var(--accent-glow);
            box-shadow: 0 0 20px var(--shadow-color);
        }

        .news-btn {
            position: absolute;
            right: 5px; top: 5px; bottom: 5px;
            padding: 0 30px;
            border-radius: 25px;
            border: none;
            background: var(--text-color);
            color: var(--bg-color);
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
        }

        .news-btn:hover { background: var(--accent-glow); color: #fff; }

        /* ----- CART SIDEBAR ----- */
        .cart-overlay {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(8px);
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }

        .cart-overlay.active { opacity: 1; visibility: visible; }

        .cart-sidebar {
            position: fixed;
            top: 0; right: -500px;
            width: 100%; max-width: 450px;
            height: 100vh;
            background: var(--bg-color);
            border-left: 1px solid var(--glass-border);
            z-index: 2001;
            display: flex;
            flex-direction: column;
            transition: right 0.5s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            box-shadow: -10px 0 40px rgba(0,0,0,0.5);
        }

        .cart-sidebar.active { right: 0; }

        .cart-header {
            padding: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--glass-border);
        }

        .cart-title {
            font-family: var(--font-display);
            font-size: 24px;
            font-weight: 800;
            display: flex; gap: 15px; align-items: center;
        }

        .close-cart {
            width: 40px; height: 40px;
            border-radius: 50%;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            display: flex;
            justify-content: center;
            align-items: center;
            cursor: pointer;
            color: var(--text-color);
            transition: var(--transition);
        }

        .close-cart:hover {
            background: var(--secondary-glow);
            color: white;
            transform: rotate(90deg);
        }

        .cart-items {
            flex-grow: 1;
            overflow-y: auto;
            padding: 30px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .cart-item {
            display: flex;
            gap: 15px;
            background: var(--glass-bg);
            padding: 15px;
            border-radius: 12px;
            border: 1px solid var(--glass-border);
            position: relative;
            animation: slideInRight 0.3s ease forwards;
        }

        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(30px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .cart-item-img {
            width: 80px; height: 80px;
            border-radius: 8px;
            object-fit: cover;
        }

        .cart-item-info {
            display: flex;
            flex-direction: column;
            justify-content: center;
            flex-grow: 1;
        }

        .cart-item-name { font-weight: 700; font-size: 15px; margin-bottom: 5px; }
        .cart-item-price { color: var(--accent-glow); font-weight: 600; font-size: 14px; margin-bottom: 10px; }

        .qty-controls {
            display: flex; align-items: center; gap: 10px;
            background: var(--bg-color); width: fit-content;
            padding: 4px 8px; border-radius: 6px;
            border: 1px solid var(--glass-border);
        }

        .qty-btn {
            background: none; border: none; color: var(--text-color);
            cursor: pointer; font-size: 12px;
        }
        .qty-btn:hover { color: var(--accent-glow); }

        .qty-display { font-size: 13px; font-weight: 600; width: 20px; text-align: center; }

        .remove-item {
            position: absolute; top: 15px; right: 15px;
            color: var(--text-muted); cursor: pointer; transition: var(--transition);
        }
        .remove-item:hover { color: var(--secondary-glow); transform: scale(1.2); }

        .cart-footer { padding: 30px; border-top: 1px solid var(--glass-border); background: var(--bg-secondary); }

        .cart-total-row {
            display: flex; justify-content: space-between;
            margin-bottom: 20px; font-size: 22px; font-weight: 800;
        }

        .checkout-btn {
            width: 100%; padding: 18px;
            background: var(--text-color); color: var(--bg-color);
            border: none; font-size: 16px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 2px;
            border-radius: 8px; cursor: pointer; transition: var(--transition);
        }
        .checkout-btn:hover { background: var(--accent-glow); color: #fff; box-shadow: 0 10px 20px var(--shadow-color); }

        .empty-cart-container {
            display: flex; flex-direction: column; align-items: center;
            justify-content: center; height: 100%; color: var(--text-muted); gap: 20px;
        }
        .empty-cart-container i { font-size: 60px; opacity: 0.5; }

        /* ----- FOOTER ----- */
        footer { background: var(--bg-secondary); padding: 60px 10% 30px; border-top: 1px solid var(--glass-border); }
        .footer-grid { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 40px; margin-bottom: 50px; }
        .footer-brand .logo { margin-bottom: 20px; display: inline-block; }
        .footer-brand p { color: var(--text-muted); line-height: 1.6; }
        .footer-heading { font-size: 16px; font-weight: 700; margin-bottom: 20px; text-transform: uppercase; letter-spacing: 1px; }
        .footer-links { list-style: none; }
        .footer-links li { margin-bottom: 12px; }
        .footer-links a { color: var(--text-muted); text-decoration: none; transition: var(--transition); }
        .footer-links a:hover { color: var(--accent-glow); padding-left: 5px; }

        .social-icons { display: flex; gap: 15px; margin-top: 20px; }
        .social-icons a {
            width: 40px; height: 40px; border-radius: 50%;
            background: var(--glass-bg); border: 1px solid var(--glass-border);
            display: flex; justify-content: center; align-items: center;
            color: var(--text-color); text-decoration: none; transition: var(--transition);
        }
        .social-icons a:hover { background: var(--accent-glow); color: #fff; transform: translateY(-5px); }

        .footer-bottom { text-align: center; padding-top: 30px; border-top: 1px solid var(--glass-border); color: var(--text-muted); font-size: 14px; }

        /* ----- TOAST NOTIFICATION ----- */
        .toast {
            position: fixed; bottom: 30px; left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: var(--nav-bg); color: var(--text-color);
            padding: 15px 30px; border-radius: 30px; border: 1px solid var(--glass-border);
            box-shadow: 0 10px 30px rgba(0,0,0,0.3); display: flex; align-items: center; gap: 15px; font-weight: 600;
            z-index: 3000; backdrop-filter: blur(10px); opacity: 0; transition: all 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }
        .toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        .toast i { color: #00e676; font-size: 20px; }

        /* ----- RESPONSIVE ----- */
        @media (max-width: 992px) {
            .about-section { flex-direction: column; }
            .footer-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 768px) {
            .nav-links { display: none; }
            .hero-title { font-size: 45px; }
            .section-header { flex-direction: column; align-items: flex-start; gap: 20px; }
            .category-filters { flex-wrap: wrap; }
            .footer-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<?php if ($page === 'login'): ?>
    <div style="min-height: 100vh; display: flex; align-items: center; justify-content: center; background: var(--bg-color);">
        <div style="background: var(--bg-secondary); padding: 40px; border-radius: 12px; border: 1px solid var(--glass-border); width: 100%; max-width: 400px; text-align: center;">
            <h2 style="font-family: var(--font-display); margin-bottom: 20px;">Access Portal</h2>
            <?php if(isset($login_error)) echo "<p style='color: var(--secondary-glow); margin-bottom: 15px;'>$login_error</p>"; ?>
            <form method="POST" action="?page=login" style="display: flex; flex-direction: column; gap: 15px;">
                <input type="hidden" name="action" value="login">
                <input type="text" name="username" placeholder="Username" required style="padding: 12px; background: var(--glass-bg); border: 1px solid var(--glass-border); color: var(--text-color); border-radius: 6px; outline: none;">
                <input type="password" name="password" placeholder="Password" required style="padding: 12px; background: var(--glass-bg); border: 1px solid var(--glass-border); color: var(--text-color); border-radius: 6px; outline: none;">
                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">Login</button>
            </form>
            <p style="margin-top: 20px; color: var(--text-muted);"><a href="?page=home" style="color: var(--accent-glow); text-decoration: none;">Return Home</a></p>
        </div>
    </div>
<?php elseif ($page === 'admin'): 
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        echo "<script>window.location.href='?page=login';</script>";
        exit;
    }
?>
    <nav id="navbar" class="scrolled">
        <div class="logo">AURA X | ADMIN</div>
        <div class="nav-links">
            <a href="?page=home">Storefront</a>
            <a href="?logout=1" style="color: var(--secondary-glow);">Logout</a>
        </div>
    </nav>
    <section style="padding: 120px 10% 50px; min-height: 100vh;">
        <h2 class="section-title" style="margin-bottom: 30px;">Admin Dashboard</h2>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
            <div style="background: var(--glass-bg); padding: 25px; border-radius: 12px; border: 1px solid var(--glass-border);">
                <h3>Database Status</h3>
                <p style="margin-top: 15px; color: <?php echo $db_connected ? '#00e676' : 'var(--secondary-glow)'; ?>;">
                    <?php echo $db_connected ? 'Connected Successfully (aurax_db)' : 'Connection Failed: ' . ($db_error ?? ''); ?>
                </p>
            </div>
            <div style="background: var(--glass-bg); padding: 25px; border-radius: 12px; border: 1px solid var(--glass-border);">
                <h3>User Management</h3>
                <p style="margin-top: 15px;">Logged in as: <?php echo $_SESSION['username']; ?></p>
                <p>Role: <?php echo strtoupper($_SESSION['role']); ?></p>
            </div>
            <div style="background: var(--glass-bg); padding: 25px; border-radius: 12px; border: 1px solid var(--glass-border); grid-column: span 2;">
                <h3>Product Catalog</h3>
                <table style="width: 100%; margin-top: 15px; border-collapse: collapse; text-align: left;">
                    <tr style="border-bottom: 1px solid var(--glass-border);">
                        <th style="padding: 10px;">ID</th><th style="padding: 10px;">Name</th><th style="padding: 10px;">Category</th><th style="padding: 10px;">Price</th>
                    </tr>
                    <?php foreach ($products as $p): ?>
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                        <td style="padding: 10px;"><?php echo $p['id']; ?></td>
                        <td style="padding: 10px;"><?php echo $p['name']; ?></td>
                        <td style="padding: 10px;"><?php echo $p['category']; ?></td>
                        <td style="padding: 10px;">$<?php echo number_format($p['price'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    </section>
<?php else: ?>

    <!-- PRELOADER -->
    <div id="preloader">
        <div class="loader"></div>
    </div>

    <!-- NAVIGATION -->
    <nav id="navbar">
        <div class="logo" onclick="window.scrollTo(0,0)">AURA X</div>
        <div class="nav-links">
            <a href="#home">Home</a>
            <a href="#features">Features</a>
            <a href="#products">Collection</a>
            <a href="#about">About</a>
            <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <a href="?page=admin" style="color: var(--accent-glow);">Admin Dashboard</a>
            <?php endif; ?>
            <?php if(isset($_SESSION['user_id'])): ?>
                <a href="?logout=1" style="color: var(--secondary-glow);">Logout</a>
            <?php else: ?>
                <a href="?page=login" style="color: var(--accent-glow);">Login</a>
            <?php endif; ?>
        </div>
        <div class="nav-actions">
            <!-- Theme Toggle -->
            <button class="icon-btn" id="theme-toggle" title="Toggle Light/Dark Mode">
                <i class="fas fa-moon"></i>
            </button>
            <!-- Cart Toggle -->
            <button class="icon-btn" onclick="toggleCart()" title="View Cart">
                <i class="fas fa-shopping-bag"></i>
                <span class="cart-count" id="cart-count">
                    <?php echo array_sum($_SESSION['cart']); ?>
                </span>
            </button>
        </div>
    </nav>

    <!-- HERO -->
    <section class="hero" id="home">
        <div class="hero-bg"></div>
        <div class="hero-content reveal">
            <div class="hero-badge">
                <i class="fas fa-bolt"></i> V2.0 Collection Dropped
            </div>
            <h1 class="hero-title">Elevate Your <span>Reality.</span></h1>
            <p class="hero-desc">Discover the intersection of high fashion and next-gen technology. AURA X brings you cyber-enhanced gear designed for the modern urban landscape.</p>
            <div style="display: flex; align-items: center; flex-wrap: wrap; gap: 15px;">
                <a href="#products" class="btn btn-primary">Shop Now <i class="fas fa-arrow-right"></i></a>
                <a href="#about" class="btn btn-outline">Discover Brand</a>
            </div>
        </div>
    </section>

    <!-- FEATURES -->
    <section class="features" id="features">
        <div class="feature-box reveal">
            <i class="fas fa-shipping-fast feature-icon"></i>
            <h3 class="feature-title">Quantum Delivery</h3>
            <p class="feature-desc">Next-day global shipping powered by our advanced logistics network. Secure, trackable, and lightning fast.</p>
        </div>
        <div class="feature-box reveal" style="transition-delay: 0.1s;">
            <i class="fas fa-shield-alt feature-icon"></i>
            <h3 class="feature-title">Crypto Secure</h3>
            <p class="feature-desc">Military-grade encryption for all transactions. We accept major fiat currencies and select cryptocurrencies.</p>
        </div>
        <div class="feature-box reveal" style="transition-delay: 0.2s;">
            <i class="fas fa-undo feature-icon"></i>
            <h3 class="feature-title">Hassle-Free Returns</h3>
            <p class="feature-desc">30-day return policy. Not satisfied with your gear? Send it back seamlessly with zero restocking fees.</p>
        </div>
    </section>

    <!-- PRODUCTS GRID -->
    <section class="products-section" id="products">
        <div class="section-header reveal">
            <div>
                <span class="section-subtitle">Catalog</span>
                <h2 class="section-title">New Arrivals</h2>
            </div>
            <div class="category-filters">
                <button class="filter-btn active">All</button>
                <button class="filter-btn">Apparel</button>
                <button class="filter-btn">Accessories</button>
                <button class="filter-btn">Tech</button>
            </div>
        </div>
        
        <div class="products-grid">
            <?php foreach ($products as $id => $p): ?>
            <div class="product-card reveal">
                <div class="product-badge">New</div>
                <div class="product-img-wrap">
                    <img src="<?php echo $p['image']; ?>" alt="<?php echo $p['name']; ?>" class="product-img" loading="lazy">
                </div>
                <div class="product-category"><?php echo $p['category']; ?></div>
                <h3 class="product-name"><?php echo $p['name']; ?></h3>
                <div class="product-bottom">
                    <div class="product-price">$<?php echo number_format($p['price'], 2); ?></div>
                    <button class="add-cart-btn" onclick="addToCart(<?php echo $id; ?>)" title="Add to Cart">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ABOUT SECTION -->
    <section class="about-section" id="about">
        <div class="about-image reveal">
            <img src="https://images.unsplash.com/photo-1558865869-c93f6f8482af?q=80&w=1000&auto=format&fit=crop" alt="AURA X Model" loading="lazy">
        </div>
        <div class="about-content reveal" style="transition-delay: 0.2s;">
            <span class="section-subtitle">The Vision</span>
            <h2 class="section-title" style="margin-bottom: 30px;">Beyond Reality.</h2>
            <p class="hero-desc" style="color: var(--text-color);">AURA X was born from the desire to merge futuristic aesthetics with premium, functional materials. We don't just create clothes or tech—we create an ecosystem for the urban pioneer.</p>
            <p class="hero-desc" style="margin-bottom: 40px;">Designed in Tokyo. Engineered globally. Worn by those who dictate tomorrow.</p>
            <a href="#" class="btn btn-primary">Read Our Story</a>
        </div>
    </section>

    <!-- NEWSLETTER -->
    <section class="newsletter reveal">
        <span class="section-subtitle">Join The Syndicate</span>
        <h2 class="section-title">Access Restricted Intel</h2>
        <p class="hero-desc" style="margin: 20px auto;">Subscribe to receive early access to drops, exclusive collaborations, and insider technological updates.</p>
        <form class="news-form" onsubmit="event.preventDefault(); showToast('Subscribed to Intel Network'); this.reset();">
            <input type="email" class="news-input" placeholder="Enter your email address" required>
            <button type="submit" class="news-btn">JOIN</button>
        </form>
    </section>

    <!-- FOOTER -->
    <footer>
        <div class="footer-grid reveal">
            <div class="footer-brand">
                <div class="logo">AURA X</div>
                <p>Equipping the next generation of urban explorers with cyber-enhanced apparel and bleeding-edge accessories.</p>
                <div class="social-icons">
                    <a href="#"><i class="fab fa-twitter"></i></a>
                    <a href="#"><i class="fab fa-instagram"></i></a>
                    <a href="#"><i class="fab fa-discord"></i></a>
                </div>
            </div>
            <div>
                <h4 class="footer-heading">Shop</h4>
                <ul class="footer-links">
                    <li><a href="#">Latest Drops</a></li>
                    <li><a href="#">Apparel</a></li>
                    <li><a href="#">Accessories</a></li>
                    <li><a href="#">Gift Cards</a></li>
                </ul>
            </div>
            <div>
                <h4 class="footer-heading">Support</h4>
                <ul class="footer-links">
                    <li><a href="#">Track Order</a></li>
                    <li><a href="#">Returns & Exchanges</a></li>
                    <li><a href="#">Size Guide</a></li>
                    <li><a href="#">FAQ</a></li>
                </ul>
            </div>
            <div>
                <h4 class="footer-heading">Legal</h4>
                <ul class="footer-links">
                    <li><a href="#">Terms of Service</a></li>
                    <li><a href="#">Privacy Policy</a></li>
                    <li><a href="#">Cookie Policy</a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            &copy; <?php echo date('Y'); ?> AURA X SYSTEM. All rights reserved. 
        </div>
    </footer>

    <!-- CART SIDEBAR -->
    <div class="cart-overlay" id="cart-overlay" onclick="toggleCart()"></div>
    <div class="cart-sidebar" id="cart-sidebar">
        <div class="cart-header">
            <div class="cart-title">
                <i class="fas fa-shopping-cart"></i> Your Gear
            </div>
            <button class="close-cart" onclick="toggleCart()"><i class="fas fa-times"></i></button>
        </div>
        <div class="cart-items" id="cart-items-container">
            <!-- Items injected by JS -->
        </div>
        <div class="cart-footer">
            <div class="cart-total-row">
                <span>Total</span>
                <span id="cart-total">$0.00</span>
            </div>
            <button class="checkout-btn" onclick="alert('Checkout integration coming soon!')">Secure Checkout</button>
        </div>
    </div>

    <!-- TOAST -->
    <div class="toast" id="toast">
        <i class="fas fa-check-circle"></i>
        <span id="toast-msg">Item added to cart</span>
    </div>

    <!-- SCRIPTS -->
    <script>
        // Preloader
        window.addEventListener('load', () => {
            setTimeout(() => {
                document.getElementById('preloader').style.opacity = '0';
                setTimeout(() => {
                    document.getElementById('preloader').style.display = 'none';
                }, 500);
            }, 500);
            renderCart(); // Initial cart render
        });

        // Theme Toggle
        const themeToggle = document.getElementById('theme-toggle');
        const htmlEl = document.documentElement;
        
        // Check for saved theme
        if(localStorage.getItem('theme') === 'light') {
            htmlEl.setAttribute('data-theme', 'light');
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        }

        themeToggle.addEventListener('click', () => {
            if(htmlEl.getAttribute('data-theme') === 'dark') {
                htmlEl.setAttribute('data-theme', 'light');
                localStorage.setItem('theme', 'light');
                themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
            } else {
                htmlEl.setAttribute('data-theme', 'dark');
                localStorage.setItem('theme', 'dark');
                themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
            }
        });

        // Navbar Scroll Effect
        const navbar = document.getElementById('navbar');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });

        // Scroll Reveal Animation
        function reveal() {
            var reveals = document.querySelectorAll('.reveal');
            for (var i = 0; i < reveals.length; i++) {
                var windowHeight = window.innerHeight;
                var elementTop = reveals[i].getBoundingClientRect().top;
                var elementVisible = 100;
                if (elementTop < windowHeight - elementVisible) {
                    reveals[i].classList.add('active');
                }
            }
        }
        window.addEventListener('scroll', reveal);
        reveal(); // Trigger on load

        // Toast Notification
        function showToast(msg) {
            const toast = document.getElementById('toast');
            document.getElementById('toast-msg').innerText = msg;
            toast.classList.add('show');
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        // --- CART LOGIC (AJAX) ---
        
        function toggleCart() {
            document.getElementById('cart-sidebar').classList.toggle('active');
            document.getElementById('cart-overlay').classList.toggle('active');
            if(document.getElementById('cart-sidebar').classList.contains('active')){
                renderCart();
            }
        }

        function addToCart(id) {
            const formData = new FormData();
            formData.append('action', 'add');
            formData.append('id', id);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    document.getElementById('cart-count').innerText = data.cart_count;
                    showToast('Item added to cart');
                    renderCart();
                }
            });
        }

        function updateQty(id, qty) {
            const formData = new FormData();
            formData.append('action', 'update_qty');
            formData.append('id', id);
            formData.append('qty', qty);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    document.getElementById('cart-count').innerText = data.cart_count;
                    renderCart();
                }
            });
        }

        function removeFromCart(id) {
            const formData = new FormData();
            formData.append('action', 'remove');
            formData.append('id', id);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    document.getElementById('cart-count').innerText = data.cart_count;
                    showToast('Item removed');
                    renderCart();
                }
            });
        }

        function renderCart() {
            const formData = new FormData();
            formData.append('action', 'get_cart');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                const container = document.getElementById('cart-items-container');
                const totalEl = document.getElementById('cart-total');
                
                document.getElementById('cart-count').innerText = data.cart_count;
                totalEl.innerText = '$' + data.total.toFixed(2);
                
                container.innerHTML = '';
                
                if(Object.keys(data.items).length === 0) {
                    container.innerHTML = `
                        <div class="empty-cart-container">
                            <i class="fas fa-ghost"></i>
                            <p>Your inventory is empty</p>
                            <button class="btn btn-outline" onclick="toggleCart()" style="margin-left:0; font-size: 12px; padding: 10px 20px;">Browse Store</button>
                        </div>
                    `;
                    return;
                }

                for (const [id, item] of Object.entries(data.items)) {
                    const html = `
                        <div class="cart-item">
                            <i class="fas fa-trash remove-item" onclick="removeFromCart(${id})" title="Remove"></i>
                            <img src="${item.image}" alt="${item.name}" class="cart-item-img">
                            <div class="cart-item-info">
                                <div class="cart-item-name">${item.name}</div>
                                <div class="cart-item-price">$${parseFloat(item.price).toFixed(2)}</div>
                                <div class="qty-controls">
                                    <button class="qty-btn" onclick="updateQty(${id}, ${item.quantity - 1})"><i class="fas fa-minus"></i></button>
                                    <div class="qty-display">${item.quantity}</div>
                                    <button class="qty-btn" onclick="updateQty(${id}, ${item.quantity + 1})"><i class="fas fa-plus"></i></button>
                                </div>
                            </div>
                        </div>
                    `;
                    container.insertAdjacentHTML('beforeend', html);
                }
            });
        }
    </script>
<?php endif; ?>
</body>
</html>
