<?php
session_start();
require_once 'config.php';

// Handle order submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_order'])) {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['error' => 'Lütfen önce giriş yapın']);
        exit;
    }

    try {
        $conn->beginTransaction();

        // Insert order
        $stmt = $conn->prepare("INSERT INTO orders (user_id, full_name, phone, address, total_amount) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_id'],
            $_POST['full_name'],
            $_POST['phone'],
            $_POST['address'],
            $_POST['total_amount']
        ]);
        
        $order_id = $conn->lastInsertId();

        // Insert order items
        $items = json_decode($_POST['items'], true);
        $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, product_name, quantity, price) VALUES (?, ?, ?, ?, ?)");
        
        foreach ($items as $item) {
            $stmt->execute([
                $order_id,
                $item['id'],
                $item['name'],
                $item['quantity'],
                $item['price']
            ]);

            // Update product stock
            $update_stock = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
            $update_stock->execute([$item['quantity'], $item['id']]);
        }

        $conn->commit();
        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        $conn->rollBack();
        error_log($e->getMessage()); // Log the error
        echo json_encode(['error' => 'Sipariş kaydedilirken bir hata oluştu: ' . $e->getMessage()]);
        exit;
    }
}

// Initialize cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Fetch all products
$stmt = $conn->query("SELECT * FROM products ORDER BY id DESC");
$products = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>E-Ticaret Sitesi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .product-card {
            height: 100%;
            transition: transform 0.2s;
        }
        .product-card:hover {
            transform: translateY(-5px);
        }
        .product-image {
            height: 200px;
            object-fit: cover;
        }
        #cartSidebar {
            position: fixed;
            top: 0;
            right: -400px;
            width: 400px;
            height: 100vh;
            background: white;
            box-shadow: -2px 0 5px rgba(0,0,0,0.2);
            transition: right 0.3s ease;
            z-index: 1050;
            overflow-y: auto;
        }
        #cartSidebar.active {
            right: 0;
        }
        .cart-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1040;
        }
        .cart-item {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        .cart-badge {
            position: relative;
            top: -8px;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="index.php">E-Ticaret</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="#" id="cartButton">
                            <i class="bi bi-cart3"></i> Sepet
                            <span class="badge bg-primary cart-badge" id="cartCount">0</span>
                        </a>
                    </li>
                    <?php if(isset($_SESSION['user_id'])): ?>
                        <?php if(isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="admin/dashboard.php">Admin Panel</a>
                            </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="nav-link" href="logout.php">Çıkış Yap</a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="login.php">Giriş Yap</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="register.php">Kayıt Ol</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Cart Sidebar -->
    <div class="cart-overlay" id="cartOverlay"></div>
    <div id="cartSidebar">
        <div class="p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4>Sepetim</h4>
                <button class="btn btn-sm btn-outline-secondary" id="closeCart">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <div id="cartItems"></div>
            <div class="mt-3" id="orderForm" style="display: none;">
                <h5>Teslimat Bilgileri</h5>
                <form id="deliveryForm">
                    <div class="mb-3">
                        <label class="form-label">Ad Soyad</label>
                        <input type="text" class="form-control" id="fullName" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Telefon</label>
                        <input type="tel" class="form-control" id="phone" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Adres</label>
                        <textarea class="form-control" id="address" rows="3" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Siparişi Tamamla</button>
                </form>
            </div>
        </div>
    </div>

    <div class="container mt-4">
        <h2 class="mb-4">Ürünlerimiz</h2>
        
        <div class="row row-cols-1 row-cols-md-3 row-cols-lg-4 g-4">
            <?php foreach($products as $product): ?>
            <div class="col">
                <div class="card product-card">
                    <?php if($product['image']): ?>
                        <img src="uploads/<?php echo $product['image']; ?>" class="card-img-top product-image" alt="<?php echo $product['name']; ?>">
                    <?php else: ?>
                        <img src="https://via.placeholder.com/300" class="card-img-top product-image" alt="Placeholder">
                    <?php endif; ?>
                    
                    <div class="card-body">
                        <h5 class="card-title"><?php echo $product['name']; ?></h5>
                        <p class="card-text"><?php echo substr($product['description'], 0, 100); ?>...</p>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="h5 mb-0"><?php echo $product['price']; ?> TL</span>
                            <button class="btn btn-primary add-to-cart" 
                                    data-id="<?php echo $product['id']; ?>"
                                    data-name="<?php echo $product['name']; ?>"
                                    data-price="<?php echo $product['price']; ?>">
                                Sepete Ekle
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="modal fade" id="orderSuccessModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Sipariş Başarılı!</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Siparişiniz başarıyla alınmıştır. Teşekkür ederiz!</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Tamam</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Login Required Modal -->
    <div class="modal fade" id="loginRequiredModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Giriş Gerekli</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Siparişi tamamlamak için lütfen giriş yapın.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <a href="login.php" class="btn btn-primary">Giriş Yap</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let cart = [];
        const cartSidebar = document.getElementById('cartSidebar');
        const cartOverlay = document.getElementById('cartOverlay');
        const cartItems = document.getElementById('cartItems');
        const cartCount = document.getElementById('cartCount');
        const orderForm = document.getElementById('orderForm');
        const successModal = new bootstrap.Modal(document.getElementById('orderSuccessModal'));
        const loginRequiredModal = new bootstrap.Modal(document.getElementById('loginRequiredModal'));
        const isLoggedIn = <?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>;

        // Add to cart
        document.querySelectorAll('.add-to-cart').forEach(button => {
            button.addEventListener('click', () => {
                const product = {
                    id: button.dataset.id,
                    name: button.dataset.name,
                    price: parseFloat(button.dataset.price),
                    quantity: 1
                };

                const existingItem = cart.find(item => item.id === product.id);
                if (existingItem) {
                    existingItem.quantity++;
                } else {
                    cart.push(product);
                }

                updateCart();
                openCart();
            });
        });

        // Toggle cart
        document.getElementById('cartButton').addEventListener('click', toggleCart);
        document.getElementById('closeCart').addEventListener('click', closeCart);
        cartOverlay.addEventListener('click', closeCart);

        // Modified form submit handler
        document.getElementById('deliveryForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            if (!isLoggedIn) {
                loginRequiredModal.show();
                return;
            }

            // Calculate total
            const total = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);

            // Create FormData object
            const formData = new FormData();
            formData.append('submit_order', '1');
            formData.append('full_name', document.getElementById('fullName').value);
            formData.append('phone', document.getElementById('phone').value);
            formData.append('address', document.getElementById('address').value);
            formData.append('total_amount', total.toFixed(2));
            formData.append('items', JSON.stringify(cart));

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                
                if (result.error) {
                    alert(result.error);
                    return;
                }

                if (result.success) {
                    successModal.show();
                    cart = [];
                    updateCart();
                    closeCart();
                    document.getElementById('deliveryForm').reset();
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Sipariş gönderilirken bir hata oluştu. Lütfen tekrar deneyin.');
            }
        });

        function toggleCart() {
            if (cartSidebar.classList.contains('active')) {
                closeCart();
            } else {
                openCart();
            }
        }

        function openCart() {
            cartSidebar.classList.add('active');
            cartOverlay.style.display = 'block';
        }

        function closeCart() {
            cartSidebar.classList.remove('active');
            cartOverlay.style.display = 'none';
        }

        function updateCart() {
            cartCount.textContent = cart.reduce((total, item) => total + item.quantity, 0);
            
            if (cart.length === 0) {
                cartItems.innerHTML = '<p class="text-center my-3">Sepetiniz boş</p>';
                orderForm.style.display = 'none';
                return;
            }

            let total = 0;
            cartItems.innerHTML = cart.map(item => {
                const itemTotal = item.price * item.quantity;
                total += itemTotal;
                return `
                    <div class="cart-item">
                        <div class="d-flex justify-content-between">
                            <h6>${item.name}</h6>
                            <div>
                                <button class="btn btn-sm btn-outline-secondary" onclick="updateQuantity('${item.id}', ${item.quantity - 1})">-</button>
                                <span class="mx-2">${item.quantity}</span>
                                <button class="btn btn-sm btn-outline-secondary" onclick="updateQuantity('${item.id}', ${item.quantity + 1})">+</button>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between mt-2">
                            <span>${itemTotal.toFixed(2)} TL</span>
                            <button class="btn btn-sm btn-danger" onclick="removeItem('${item.id}')">Sil</button>
                        </div>
                    </div>
                `;
            }).join('') + `
                <div class="cart-item">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5>Toplam:</h5>
                        <h5>${total.toFixed(2)} TL</h5>
                    </div>
                </div>
            `;
            
            orderForm.style.display = 'block';
        }

        function updateQuantity(id, newQuantity) {
            if (newQuantity < 1) {
                removeItem(id);
                return;
            }
            const item = cart.find(item => item.id === id);
            if (item) {
                item.quantity = newQuantity;
                updateCart();
            }
        }

        function removeItem(id) {
            cart = cart.filter(item => item.id !== id);
            updateCart();
        }

        // Initialize cart
        updateCart();
    </script>
</body>
</html> 