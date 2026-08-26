-- LyaiDeu MySQL schema and seed data
CREATE DATABASE IF NOT EXISTS lyaideudb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lyaideudb;

CREATE TABLE IF NOT EXISTS dishes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  hotel VARCHAR(255) NOT NULL,
  cat VARCHAR(50) NOT NULL,
  category_id INT UNSIGNED NULL,
  name_slug VARCHAR(120) NOT NULL DEFAULT '',
  price INT UNSIGNED NOT NULL DEFAULT 0,
  discount_percent SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  phone VARCHAR(20) NOT NULL DEFAULT '',
  tag VARCHAR(100) NOT NULL DEFAULT '',
  `desc` TEXT NOT NULL,
  img VARCHAR(500) NOT NULL DEFAULT '',
  vendor_id INT UNSIGNED NULL DEFAULT NULL,
  has_variants TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_dishes_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hotels (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  type VARCHAR(255) NOT NULL DEFAULT '',
  phone VARCHAR(20) NOT NULL DEFAULT '',
  emoji VARCHAR(50) NOT NULL DEFAULT '',
  logo VARCHAR(500) NOT NULL DEFAULT '',
  kind VARCHAR(20) NOT NULL DEFAULT 'hotel',
  `desc` TEXT NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contacts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  role VARCHAR(255) NOT NULL,
  person VARCHAR(255) NOT NULL DEFAULT '',
  phone VARCHAR(20) NOT NULL DEFAULT '',
  note VARCHAR(255) NOT NULL DEFAULT '',
  ico VARCHAR(50) NOT NULL DEFAULT '',
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  phone VARCHAR(20) NULL DEFAULT NULL,
  dob DATE NULL DEFAULT NULL,
  pass VARCHAR(255) NOT NULL,
  google_sub VARCHAR(255) NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_phone (phone),
  UNIQUE KEY uq_users_google_sub (google_sub)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL,
  customer_name VARCHAR(255) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  address TEXT NOT NULL,
  note TEXT NOT NULL,
  payment VARCHAR(100) NOT NULL,
  promo VARCHAR(50) NOT NULL DEFAULT '',
  subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
  delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  discount DECIMAL(10,2) NOT NULL DEFAULT 0,
  total DECIMAL(10,2) NOT NULL DEFAULT 0,
  status VARCHAR(50) NOT NULL DEFAULT 'Pending',
  vendor_id INT UNSIGNED NULL,
  rider_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_orders_user (user_id),
  KEY idx_orders_vendor (vendor_id),
  KEY idx_orders_rider (rider_id),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vendors (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(255) NOT NULL DEFAULT '',
  phone VARCHAR(20) NOT NULL DEFAULT '',
  pass VARCHAR(255) NOT NULL DEFAULT '',
  scope VARCHAR(20) NOT NULL DEFAULT 'hotel',
  hotel_id INT UNSIGNED NULL DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vendor_phone (phone),
  UNIQUE KEY uq_vendor_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS riders (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(255) NOT NULL DEFAULT '',
  phone VARCHAR(20) NOT NULL DEFAULT '',
  pass VARCHAR(255) NOT NULL DEFAULT '',
  vehicle VARCHAR(80) NOT NULL DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rider_phone (phone),
  UNIQUE KEY uq_rider_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id INT UNSIGNED NOT NULL,
  dish_id INT UNSIGNED NULL,
  name VARCHAR(255) NOT NULL,
  hotel VARCHAR(255) NOT NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  qty INT UNSIGNED NOT NULL DEFAULT 1,
  line_total DECIMAL(10,2) NOT NULL DEFAULT 0,
  variant VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_items_order (order_id),
  KEY idx_items_dish (dish_id),
  CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_items_dish FOREIGN KEY (dish_id) REFERENCES dishes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_variants (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_type VARCHAR(20) NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  label VARCHAR(150) NOT NULL,
  price INT UNSIGNED NOT NULL DEFAULT 0,
  info VARCHAR(255) NOT NULL DEFAULT '',
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_variants_item (item_type, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mart_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  cat VARCHAR(50) NOT NULL,
  category_id INT UNSIGNED NULL,
  name_slug VARCHAR(120) NOT NULL DEFAULT '',
  unit VARCHAR(50) NOT NULL DEFAULT '',
  price INT UNSIGNED NOT NULL DEFAULT 0,
  discount_percent SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  tag VARCHAR(100) NOT NULL DEFAULT '',
  `desc` TEXT NOT NULL,
  img VARCHAR(500) NOT NULL DEFAULT '',
  vendor_id INT UNSIGNED NULL DEFAULT NULL,
  has_variants TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_mart_items_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(100) NOT NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'menu',
  parent_id INT UNSIGNED NULL,
  sort_order INT NOT NULL DEFAULT 0,
  icon VARCHAR(60) NOT NULL DEFAULT '',
  image VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY uq_cat_slug_type (slug, type),
  KEY idx_cat_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  skey VARCHAR(100) NOT NULL,
  sval TEXT DEFAULT NULL,
  PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  phone VARCHAR(20) NOT NULL DEFAULT '',
  subject VARCHAR(255) NOT NULL DEFAULT '',
  body TEXT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'unread',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_messages_status (status),
  CONSTRAINT fk_messages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(100) NOT NULL,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) DEFAULT NULL,
  pass_hash VARCHAR(255) NOT NULL,
  role ENUM('superadmin','admin','manager') NOT NULL DEFAULT 'manager',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  last_login DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_user_pages (
  admin_id INT UNSIGNED NOT NULL,
  page_key VARCHAR(40) NOT NULL,
  PRIMARY KEY (admin_id, page_key),
  CONSTRAINT fk_admin_pages_user FOREIGN KEY (admin_id)
    REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (skey, sval) VALUES
  ('site_logo', 'logo.png'),
  ('site_favicon', 'favicon.ico'),
  ('site_apple_icon', 'apple-touch-icon.png'),
  ('admin_username', 'admin'),
  ('admin_pass_hash', '$2y$12$gKimlVM8pqaeijJcHazLGOfF2Qbse0Obz29rRt4hUt/FLUFAmvrPa')
ON DUPLICATE KEY UPDATE skey = VALUES(skey);

INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (1,'Chicken Steam Momo','Himalayan Momo House','momo',220,'9841012345','Best Seller','Juicy steamed momos served with our signature tomato-sesame achar.','https://image.qwenlm.ai/public_source/faa3a93f-c243-44f8-9a77-0f6b8cf838a8/390dc3b7f-9907-4ec9-bf6b-557567f50e2e7012.png');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (2,'Chilli Garlic Momo','Momo Junction','momo',260,'9812345678','Spicy 🌶','Crispy fried momos tossed in a fiery chilli-garlic sauce.','https://image.qwenlm.ai/public_source/faa3a93f-c243-44f8-9a77-0f6b8cf838a8/490dc3b7f-9907-4ec9-bf6b-557567f50e2e9895.png');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (3,'Margherita Pizza','Fire &amp; Dough Pizza Co.','pizza',650,'9803456781','','Wood-fired base, fresh basil and molten mozzarella cheese.','https://image.qwenlm.ai/public_source/faa3a93f-c243-44f8-9a77-0f6b8cf838a8/290dc3b7f-9907-4ec9-bf6b-557567f50e2e7915.png');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (4,'BBQ Chicken Pizza','Slice of Kathmandu','pizza',850,'9845678901','Best Seller','Smoky BBQ chicken, caramelised onions and extra cheese pull.','https://image.qwenlm.ai/public_source/faa3a93f-c243-44f8-9a77-0f6b8cf838a8/390dc3b7f-9907-4ec9-bf6b-557567f50e2e8855.png');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (5,'Veg Chowmein','Wok Star Kitchen','chowmein',180,'9818765432','','Wok-tossed noodles with crunchy seasonal vegetables.','https://image.qwenlm.ai/public_source/faa3a93f-c243-44f8-9a77-0f6b8cf838a8/290dc3b7f-9907-4ec9-bf6b-557567f50e2e7599.png');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (6,'Buff Chowmein Special','New Road Noodle Bar','chowmein',240,'9856012345','Street Style','Spicy street-style buff chowmein topped with a boiled egg.','https://image.qwenlm.ai/public_source/faa3a93f-c243-44f8-9a77-0f6b8cf838a8/090dc3b7f-9907-4ec9-bf6b-557567f50e2e9245.png');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (7,'Samay Baji Platter','Newa Lahana','snacks',320,'9849876543','Traditional','Beaten rice, choila, egg, aloo &amp; achar — the classic Newa set.','https://image.qwenlm.ai/public_source/faa3a93f-c243-44f8-9a77-0f6b8cf838a8/490dc3b7f-9907-4ec9-bf6b-557567f50e2e6110.png');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (8,'Crispy Spring Rolls','Dragon Wok','snacks',200,'9861234567','','Golden veggie rolls served with a sweet chilli dip.','https://image.qwenlm.ai/public_source/faa3a93f-c243-44f8-9a77-0f6b8cf838a8/290dc3b7f-9907-4ec9-bf6b-557567f50e2e6149.png');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (9,'Chicken Burger Combo','Burger Hub','snacks',380,'9834567890','Combo','Crispy chicken patty, golden fries and an ice-cold soft drink.','https://image.qwenlm.ai/public_source/faa3a93f-c243-44f8-9a77-0f6b8cf838a8/290dc3b7f-9907-4ec9-bf6b-557567f50e2e3249.png');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (10,'Cold Coffee Frappe','Kathmandu Brew House','beverages',250,'9802345678','','Icy blended coffee topped with whipped cream.','https://image.qwenlm.ai/public_source/faa3a93f-c243-44f8-9a77-0f6b8cf838a8/190dc3b7f-9907-4ec9-bf6b-557567f50e2e7713.png');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (11,'Mango Lassi','Sweet Valley Café','beverages',180,'9856781234','','Thick yogurt smoothie made with real Nepali mango.','https://image.qwenlm.ai/public_source/faa3a93f-c243-44f8-9a77-0f6b8cf838a8/090dc3b7f-9907-4ec9-bf6b-557567f50e2e9599.png');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (12,'Buff Thali Set','Thakali Kitchen','dinner',450,'9851098765','Chef&#039;s Pick','Dal, bhat, tarkari, masu &amp; achar — unlimited refills.','https://image.qwenlm.ai/public_source/faa3a93f-c243-44f8-9a77-0f6b8cf838a8/090dc3b7f-9907-4ec9-bf6b-557567f50e2e8532.png');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (13,'Everest Steak &amp; Fries','Everest Steak House','dinner',950,'9808765432','Premium','Grilled pepper steak with buttered fries and fresh salad.','https://image.qwenlm.ai/public_source/faa3a93f-c243-44f8-9a77-0f6b8cf838a8/590dc3b7f-9907-4ec9-bf6b-557567f50e2e8386.png');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (14,'Chicken Curry &amp; Rice','Ghar Ghar Rasoee','dinner',390,'9840098765','Homestyle','Slow-cooked chicken curry with steamed basmati rice.','https://image.qwenlm.ai/public_source/faa3a93f-c243-44f8-9a77-0f6b8cf838a8/190dc3b7f-9907-4ec9-bf6b-557567f50e2e8427.png');
INSERT INTO hotels (id,name,type,phone,emoji) VALUES (1,'Himalayan Momo House','Momo · New Baneshwor','9841012345','fa-drumstick-bite');
INSERT INTO hotels (id,name,type,phone,emoji) VALUES (2,'Momo Junction','Momo · Putalisadak','9812345678','fa-drumstick-bite');
INSERT INTO hotels (id,name,type,phone,emoji) VALUES (3,'Fire &amp; Dough Pizza Co.','Pizza · Jhamsikhel','9803456781','fa-pizza-slice');
INSERT INTO hotels (id,name,type,phone,emoji) VALUES (4,'Slice of Kathmandu','Pizza · Thamel','9845678901','fa-pizza-slice');
INSERT INTO hotels (id,name,type,phone,emoji) VALUES (5,'Wok Star Kitchen','Chowmein · Boudha','9818765432','fa-bowl-rice');
INSERT INTO hotels (id,name,type,phone,emoji) VALUES (6,'New Road Noodle Bar','Chowmein · New Road','9856012345','fa-bowl-rice');
INSERT INTO hotels (id,name,type,phone,emoji) VALUES (7,'Newa Lahana','Traditional · Kirtipur','9849876543','fa-utensils');
INSERT INTO hotels (id,name,type,phone,emoji) VALUES (8,'Dragon Wok','Snacks · Lazimpat','9861234567','fa-cookie');
INSERT INTO hotels (id,name,type,phone,emoji) VALUES (9,'Burger Hub','Snacks · Dillibazar','9834567890','fa-burger');
INSERT INTO hotels (id,name,type,phone,emoji) VALUES (10,'Kathmandu Brew House','Beverages · Durbar Marg','9802345678','fa-mug-saucer');
INSERT INTO hotels (id,name,type,phone,emoji) VALUES (11,'Sweet Valley Café','Beverages · Kupondole','9856781234','fa-apple-whole');
INSERT INTO hotels (id,name,type,phone,emoji) VALUES (12,'Thakali Kitchen','Dinner · Pokhara Rd','9851098765','fa-bowl-food');
INSERT INTO hotels (id,name,type,phone,emoji) VALUES (13,'Everest Steak House','Dinner · Naxal','9808765432','fa-bacon');
INSERT INTO hotels (id,name,type,phone,emoji) VALUES (14,'Ghar Ghar Rasoee','Dinner · Baluwatar','9840098765','fa-drumstick-bite');
INSERT INTO mart_items (id,name,cat,unit,price,tag,`desc`,img) VALUES (1,'Fresh Potatoes','vegetables','kg',60,'','Locally grown potatoes, perfect for aloo tareko.','');
INSERT INTO mart_items (id,name,cat,unit,price,tag,`desc`,img) VALUES (2,'Onions','vegetables','kg',55,'','Sweet red onions for everyday cooking.','');
INSERT INTO mart_items (id,name,cat,unit,price,tag,`desc`,img) VALUES (3,'Ripe Tomatoes','vegetables','500 g',45,'','Juicy vine-ripened tomatoes straight from the farm.','');
INSERT INTO mart_items (id,name,cat,unit,price,tag,`desc`,img) VALUES (4,'Red Apples','fruits','kg',240,'','Crisp and sweet red apples, great for the whole family.','');
INSERT INTO mart_items (id,name,cat,unit,price,tag,`desc`,img) VALUES (5,'Bananas','fruits','dozen',120,'','Naturally ripe bananas, ready to eat.','');
INSERT INTO mart_items (id,name,cat,unit,price,tag,`desc`,img) VALUES (6,'Fresh Milk','dairy','litre',95,'','Farm-fresh full cream milk delivered daily.','');
INSERT INTO mart_items (id,name,cat,unit,price,tag,`desc`,img) VALUES (7,'Curd (Dahi)','dairy','500 g',150,'','Thick, creamy set dahi made every morning.','');
INSERT INTO mart_items (id,name,cat,unit,price,tag,`desc`,img) VALUES (8,'Basmati Rice','staples','kg',185,'','Premium aged basmati, long grain and aromatic.','');
INSERT INTO mart_items (id,name,cat,unit,price,tag,`desc`,img) VALUES (9,'Cooking Oil','oils','litre',220,'','Pure refined sunflower oil for all your cooking.','');
INSERT INTO mart_items (id,name,cat,unit,price,tag,`desc`,img) VALUES (10,'Parle-G Biscuits','snacks','pack',40,'','The classic crunchy glucose biscuit everyone loves.','');
-- Extended catalog (extra dishes, mart items & hotels)
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (15,'Chicken Chowmein','Wok Star Kitchen','chowmein',220,'','Street Style','Wok-tossed noodles with tender chicken strips and crunchy veggies.','');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (16,'Jhol Momo','Himalayan Momo House','momo',280,'','Spicy 🌶','Steamed chicken momos drowned in a fiery soupy jhol achar.','');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (17,'Cheese Momo','Momo Junction','momo',280,'','','Steamed momos filled with a gooey three-cheese blend.','');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (18,'Fried Momo','New Road Noodle Bar','momo',250,'','Crispy','Golden-fried momos served with classic tomato achar.','');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (19,'Pepperoni Pizza','Slice of Kathmandu','pizza',780,'','Best Seller','Classic pepperoni with bubbling mozzarella on a crisp base.','');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (20,'Veggie Supreme Pizza','Fire & Dough Pizza Co.','pizza',690,'','','Loaded with bell peppers, olives, onion and mushroom.','');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (21,'Double Cheese Pizza','Slice of Kathmandu','pizza',720,'','Cheese Lovers','Extra mozzarella and cheddar for serious cheese fans.','');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (22,'Veg Hakka Noodles','New Road Noodle Bar','chowmein',190,'','','Classic Indian-Chinese hakka noodles, vegetable style.','');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (23,'Schezwan Chowmein','Dragon Wok','chowmein',230,'','Spicy 🌶','Fiery schezwan sauce tossed with noodles and garden veggies.','');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (24,'Chicken Popcorn','Burger Hub','snacks',320,'','','Crispy bite-sized fried chicken with a dipping sauce.','');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (25,'Potato Wedges','Burger Hub','snacks',190,'','','Seasoned golden wedges with a spicy mayo dip.','');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (26,'Paneer Pakoda','Ghar Ghar Rasoee','snacks',260,'','Veg','Spiced paneer fritters served with fresh mint chutney.','');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (27,'French Fries','Wok Star Kitchen','snacks',140,'','','Golden crispy fries with a side of ketchup.','');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (28,'Chicken Lollipop','Dragon Wok','snacks',340,'','Popular','Juicy fried chicken lollipops with a garlic dip.','');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (29,'Fresh Lime Soda','Sweet Valley Café','beverages',150,'','','Effervescent lime soda, sweet or salty, served ice cold.','');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (30,'Masala Chai','Kathmandu Brew House','beverages',120,'','','Spiced Nepali milk tea, strong, comforting and aromatic.','');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (31,'Oreo Shake','Sweet Valley Café','beverages',320,'','','Creamy oreo-cookie milkshake topped with whipped cream.','');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (32,'Iced Mocha','Kathmandu Brew House','beverages',300,'','','Espresso, chocolate and cold milk shaken over ice.','');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (33,'Chicken Biryani','Ghar Ghar Rasoee','dinner',420,'','Chef Pick','Fragrant basmati rice layered with spiced chicken.','');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (34,'Mutton Sekuwa','Thakali Kitchen','dinner',520,'','Traditional','Char-grilled, heavily spiced mutton skewers with beaten rice.','');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (35,'Vegetable Curry Thali','Thakali Kitchen','dinner',320,'','Veg','A hearty veg thali — dal, rice, seasonal curries and achar.','');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (36,'Dal Bhat Power','Ghar Ghar Rasoee','dinner',350,'','Homestyle','The ultimate dal bhat with seasonal tarkari and a spoon of ghee.','');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (37,'Butter Chicken & Naan','Ghar Ghar Rasoee','dinner',460,'','Best Seller','Creamy tomato-butter chicken with soft butter naan.','');
INSERT INTO dishes (id,name,hotel,cat,price,phone,tag,`desc`,img) VALUES (38,'Chicken Fried Rice','Dragon Wok','dinner',280,'','','Wok-fired rice with egg, chicken and spring onion.','');
INSERT INTO hotels (id,name,type,phone,emoji) VALUES (15,'Curry House','Dinner · Lazimpat','9845566771','fa-bowl-food');
INSERT INTO hotels (id,name,type,phone,emoji) VALUES (16,'Chill Out Café','Café & Snacks · New Baneshwor','9845566772','fa-mug-saucer');
INSERT INTO hotels (id,name,type,phone,emoji) VALUES (17,'Rara Restaurant','Dinner · Kalanki','9845566773','fa-bowl-food');
INSERT INTO hotels (id,name,type,phone,emoji) VALUES (18,'Buff Corner','Fast Food · Gaushala','9845566774','fa-burger');
INSERT INTO hotels (id,name,type,phone,emoji) VALUES (19,'Adda Café','Beverages · Kupondole','9845566775','fa-mug-saucer');
INSERT INTO hotels (id,name,type,phone,emoji) VALUES (20,'Chiya Ghar','Tea House · Patan','9845566776','fa-mug-saucer');
INSERT INTO mart_items (id,name,cat,unit,price,tag,`desc`,img) VALUES (11,'Cauliflower','vegetables','head',70,'','Fresh white cauliflower for aloo-cauli.','');
INSERT INTO mart_items (id,name,cat,unit,price,tag,`desc`,img) VALUES (12,'Carrots','vegetables','kg',90,'','Sweet tender carrots, great raw or cooked.','');
INSERT INTO mart_items (id,name,cat,unit,price,tag,`desc`,img) VALUES (13,'Green Beans','vegetables','kg',110,'','Snap-fresh beans for everyday cooking.','');
INSERT INTO mart_items (id,name,cat,unit,price,tag,`desc`,img) VALUES (14,'Fresh Oranges','fruits','kg',190,'','Juicy, tart-sweet Nepali oranges.','');
INSERT INTO mart_items (id,name,cat,unit,price,tag,`desc`,img) VALUES (15,'Mangoes','fruits','kg',230,'','Sweet local mangoes while the season lasts.','');
INSERT INTO mart_items (id,name,cat,unit,price,tag,`desc`,img) VALUES (16,'Paneer','dairy','250 g',160,'','Soft fresh paneer for curries and pakodas.','');
INSERT INTO mart_items (id,name,cat,unit,price,tag,`desc`,img) VALUES (17,'Butter','dairy','100 g',120,'','Creamy dairy butter for roti and cooking.','');
INSERT INTO mart_items (id,name,cat,unit,price,tag,`desc`,img) VALUES (18,'Salt','staples','kg',35,'','Iodised table salt, an everyday essential.','');
INSERT INTO mart_items (id,name,cat,unit,price,tag,`desc`,img) VALUES (19,'Sugar','staples','kg',90,'','Fine white sugar for tea and cooking.','');
INSERT INTO mart_items (id,name,cat,unit,price,tag,`desc`,img) VALUES (20,'Tea Leaves','staples','250 g',240,'','Nepali black tea for a strong morning cup.','');
INSERT INTO mart_items (id,name,cat,unit,price,tag,`desc`,img) VALUES (21,'Mustard Oil','oils','litre',260,'','Traditional mustard oil with a warm kick.','');
INSERT INTO mart_items (id,name,cat,unit,price,tag,`desc`,img) VALUES (22,'Potato Chips','snacks','pack',50,'','Crunchy potato chips in a popular local flavour.','');
INSERT INTO mart_items (id,name,cat,unit,price,tag,`desc`,img) VALUES (23,'Chocolate Bar','snacks','pack',80,'','Milk chocolate bar for sweet cravings.','');
INSERT INTO contacts (id,role,person,phone,note,ico) VALUES (1,'Order Hotline','LyaiDeu Central','9800000001','7 AM – 10 PM, every day','fa-phone');
INSERT INTO contacts (id,role,person,phone,note,ico) VALUES (2,'Delivery Support','Rider Help Desk','9800000002','Track or reschedule your order','fa-motorcycle');
INSERT INTO contacts (id,role,person,phone,note,ico) VALUES (3,'Partner With Us','Hotel Onboarding Team','9800000003','List your hotel on LyaiDeu','fa-handshake');
INSERT INTO users (id,name,email,phone,dob,pass,created_at) VALUES (1786072074,'Aliza','acb@gmail.com','9878787878','2014-10-01','$2y$10$VLyF1B2oAtd3Sy8sxCSLUOs3Tf4L4UEsLc3IPNBHiUsp1pBLuGn6y','2026-08-07 05:07:00');
INSERT INTO users (id,name,email,phone,dob,pass,created_at) VALUES (1786073687,'Aliza Shrestha','alizanewar17@gmail.com','9822429508','2006-10-18','$2y$10$cS/7HuDr0CflbvJW13LqVeo8AK.PIMOQezb6vSoa0n.vd7Tad9zaq','2026-08-07 05:34:00');
INSERT INTO users (id,name,email,phone,dob,pass,created_at) VALUES (1786364153,'Chap Khet','chapkhet11@gmail.com','9769955973','2006-09-10','$2y$10$gBjzwRE5WBk1zCk8XjZJRODV8zYTb9.GE5FVSaOv3B7gqQA2kUGem','2026-08-10 14:15:00');
INSERT INTO orders (id,user_id,customer_name,phone,address,note,payment,promo,subtotal,delivery_fee,discount,total,status,created_at,updated_at) VALUES (1,1786073687,'Aliza Shrestha','9822429508','srefrvr','','Cash on Delivery','',260.00,50.00,0.00,310.00,'Confirmed','2026-08-10 04:06:22','2026-08-11 15:52:17');
INSERT INTO order_items (order_id,dish_id,name,hotel,price,qty,line_total) VALUES (1,2,'Chilli Garlic Momo','Momo Junction',260.00,1,260.00);
INSERT INTO orders (id,user_id,customer_name,phone,address,note,payment,promo,subtotal,delivery_fee,discount,total,status,created_at,updated_at) VALUES (2,1786364153,'Chap Khet','9769955973','Nepal','','Cash on Delivery','',220.00,50.00,0.00,270.00,'Confirmed','2026-08-10 14:16:12','2026-08-11 15:52:22');
INSERT INTO order_items (order_id,dish_id,name,hotel,price,qty,line_total) VALUES (2,1,'Chicken Steam Momo','Himalayan Momo House',220.00,1,220.00);
INSERT INTO orders (id,user_id,customer_name,phone,address,note,payment,promo,subtotal,delivery_fee,discount,total,status,created_at,updated_at) VALUES (3,1786364153,'Chap Khet','9769955973','erw','er','Cash on Delivery','',390.00,50.00,0.00,440.00,'Confirmed','2026-08-11 15:51:45','2026-08-11 15:52:20');
INSERT INTO order_items (order_id,dish_id,name,hotel,price,qty,line_total) VALUES (3,14,'Chicken Curry &amp; Rice','Ghar Ghar Rasoee',390.00,1,390.00);

-- Widen icon columns so Font Awesome class names (e.g. 'fa-pizza-slice') fit on existing installs.
ALTER TABLE hotels MODIFY emoji VARCHAR(50) NOT NULL DEFAULT '';
ALTER TABLE contacts MODIFY ico VARCHAR(50) NOT NULL DEFAULT '';
-- Hotel logo image URL for the Hotels section cards.
ALTER TABLE hotels ADD COLUMN logo VARCHAR(500) NOT NULL DEFAULT '';
-- Partner store kind: 'hotel' (default), 'mart', or 'other' for future business types.
ALTER TABLE hotels ADD COLUMN kind VARCHAR(20) NOT NULL DEFAULT 'hotel';
-- Store description shown on each store's own page (editable by the vendor).
ALTER TABLE hotels ADD COLUMN `desc` TEXT NOT NULL;
INSERT INTO hotels (name, type, phone, emoji, logo, kind) VALUES ('LyaiDeu Mart', 'Grocery & daily essentials', '', 'fa-basket-shopping', '', 'mart');

-- Category system: table + category_id columns (idempotent for existing installs).
CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(100) NOT NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'menu',
  parent_id INT UNSIGNED NULL,
  sort_order INT NOT NULL DEFAULT 0,
  icon VARCHAR(60) NOT NULL DEFAULT '',
  image VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY uq_cat_slug_type (slug, type),
  KEY idx_cat_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE dishes ADD COLUMN category_id INT UNSIGNED NULL DEFAULT NULL, ADD KEY idx_dishes_category (category_id);
ALTER TABLE mart_items ADD COLUMN category_id INT UNSIGNED NULL DEFAULT NULL, ADD KEY idx_mart_items_category (category_id);
ALTER TABLE dishes ADD COLUMN name_slug VARCHAR(120) NOT NULL DEFAULT '';
ALTER TABLE mart_items ADD COLUMN name_slug VARCHAR(120) NOT NULL DEFAULT '';

-- Vendor -> hotel / product ownership (idempotent at runtime via lyaideu_ensure_delivery_tables).
ALTER TABLE vendors ADD COLUMN scope VARCHAR(20) NOT NULL DEFAULT 'hotel';
ALTER TABLE vendors ADD COLUMN hotel_id INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE dishes ADD COLUMN vendor_id INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE mart_items ADD COLUMN vendor_id INT UNSIGNED NULL DEFAULT NULL;

INSERT INTO categories (name, slug, type, parent_id, sort_order, icon) VALUES
('Momos','momo','menu',NULL,1,'fa-drumstick-bite'),
('Steamed Momos','steamed-momo','menu',(SELECT id FROM (SELECT id FROM categories WHERE slug='momo' AND type='menu') t),1,'fa-drumstick-bite'),
('Fried Momos','fried-momo','menu',(SELECT id FROM (SELECT id FROM categories WHERE slug='momo' AND type='menu') t),2,'fa-fire'),
('Jhol Momos','jhol-momo','menu',(SELECT id FROM (SELECT id FROM categories WHERE slug='momo' AND type='menu') t),3,'fa-pepper-hot'),
('Pizza','pizza','menu',NULL,2,'fa-pizza-slice'),
('Veggie Pizza','veggie-pizza','menu',(SELECT id FROM (SELECT id FROM categories WHERE slug='pizza' AND type='menu') t),1,'fa-pizza-slice'),
('Chicken & Meat Pizza','chicken-pizza','menu',(SELECT id FROM (SELECT id FROM categories WHERE slug='pizza' AND type='menu') t),2,'fa-bacon'),
('Chowmein','chowmein','menu',NULL,3,'fa-bowl-rice'),
('Veg Chowmein','veg-chowmein','menu',(SELECT id FROM (SELECT id FROM categories WHERE slug='chowmein' AND type='menu') t),1,'fa-carrot'),
('Chicken Chowmein','chicken-chowmein','menu',(SELECT id FROM (SELECT id FROM categories WHERE slug='chowmein' AND type='menu') t),2,'fa-drumstick-bite'),
('Schezwan Chowmein','schezwan-chowmein','menu',(SELECT id FROM (SELECT id FROM categories WHERE slug='chowmein' AND type='menu') t),3,'fa-pepper-hot'),
('Snacks','snacks','menu',NULL,4,'fa-cookie'),
('Burgers','burgers','menu',(SELECT id FROM (SELECT id FROM categories WHERE slug='snacks' AND type='menu') t),1,'fa-burger'),
('Fries & Wedges','fries-wedges','menu',(SELECT id FROM (SELECT id FROM categories WHERE slug='snacks' AND type='menu') t),2,'fa-bowl-rice'),
('Fried Chicken','fried-chicken','menu',(SELECT id FROM (SELECT id FROM categories WHERE slug='snacks' AND type='menu') t),3,'fa-drumstick-bite'),
('Traditional Snacks','traditional-snacks','menu',(SELECT id FROM (SELECT id FROM categories WHERE slug='snacks' AND type='menu') t),4,'fa-utensils'),
('Beverages','beverages','menu',NULL,5,'fa-mug-saucer'),
('Hot Drinks','hot-drinks','menu',(SELECT id FROM (SELECT id FROM categories WHERE slug='beverages' AND type='menu') t),1,'fa-mug-hot'),
('Cool Drinks & Shakes','cool-drinks','menu',(SELECT id FROM (SELECT id FROM categories WHERE slug='beverages' AND type='menu') t),2,'fa-glass-water'),
('Dinner & Thali','dinner','menu',NULL,6,'fa-bowl-food'),
('Thali Sets','thali-sets','menu',(SELECT id FROM (SELECT id FROM categories WHERE slug='dinner' AND type='menu') t),1,'fa-bowl-food'),
('Rice & Curry','rice-curry','menu',(SELECT id FROM (SELECT id FROM categories WHERE slug='dinner' AND type='menu') t),2,'fa-bowl-rice'),
('Grills & Skewers','grills-skewers','menu',(SELECT id FROM (SELECT id FROM categories WHERE slug='dinner' AND type='menu') t),3,'fa-fire'),
('Vegetables','vegetables','mart',NULL,1,'fa-carrot'),
('Root Vegetables','root-vegetables','mart',(SELECT id FROM (SELECT id FROM categories WHERE slug='vegetables' AND type='mart') t),1,'fa-carrot'),
('Leafy & Pod Veggies','leafy-pod','mart',(SELECT id FROM (SELECT id FROM categories WHERE slug='vegetables' AND type='mart') t),2,'fa-leaf'),
('Fruits','fruits','mart',NULL,2,'fa-apple-whole'),
('Local Fruits','local-fruits','mart',(SELECT id FROM (SELECT id FROM categories WHERE slug='fruits' AND type='mart') t),1,'fa-apple-whole'),
('Imported Fruits','imported-fruits','mart',(SELECT id FROM (SELECT id FROM categories WHERE slug='fruits' AND type='mart') t),2,'fa-apple-whole'),
('Dairy','dairy','mart',NULL,3,'fa-cow'),
('Milk & Curd','milk-curd','mart',(SELECT id FROM (SELECT id FROM categories WHERE slug='dairy' AND type='mart') t),1,'fa-cow'),
('Paneer & Butter','paneer-butter','mart',(SELECT id FROM (SELECT id FROM categories WHERE slug='dairy' AND type='mart') t),2,'fa-cheese'),
('Staples','staples','mart',NULL,4,'fa-bowl-rice'),
('Grains & Rice','grains-rice','mart',(SELECT id FROM (SELECT id FROM categories WHERE slug='staples' AND type='mart') t),1,'fa-bowl-rice'),
('Pantry Essentials','pantry','mart',(SELECT id FROM (SELECT id FROM categories WHERE slug='staples' AND type='mart') t),2,'fa-basket-shopping'),
('Oils & Spices','oils','mart',NULL,5,'fa-mortar-pestle'),
('Cooking Oils','cooking-oils','mart',(SELECT id FROM (SELECT id FROM categories WHERE slug='oils' AND type='mart') t),1,'fa-mortar-pestle'),
('Spices & Masala','spices','mart',(SELECT id FROM (SELECT id FROM categories WHERE slug='oils' AND type='mart') t),2,'fa-mortar-pestle'),
('Snacks','snacks','mart',NULL,6,'fa-cookie'),
('Chips & Biscuits','chips-biscuits','mart',(SELECT id FROM (SELECT id FROM categories WHERE slug='snacks' AND type='mart') t),1,'fa-cookie'),
('Chocolates','chocolates','mart',(SELECT id FROM (SELECT id FROM categories WHERE slug='snacks' AND type='mart') t),2,'fa-chocolate-bar')
ON DUPLICATE KEY UPDATE name = VALUES(name), icon = VALUES(icon);

-- Custom sections (beyond Menu/Mart/Beverages/Others) + product links into them.
-- Managed at runtime via lyaideu_ensure_sections_tables() / admin_sections.php.
CREATE TABLE IF NOT EXISTS category_sections (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(40) NOT NULL,
  name VARCHAR(80) NOT NULL,
  icon VARCHAR(60) NOT NULL DEFAULT '',
  `desc` VARCHAR(190) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_section_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS section_item_links (
  item_type VARCHAR(10) NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (item_type, item_id, category_id),
  KEY idx_sil_category (category_id),
  KEY idx_sil_item (item_type, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin-created promo codes validated at checkout (percent / fixed Rs. / free
-- delivery). Managed via admin_promocodes.php; runtime helper lyaideu_ensure_promo_table().
CREATE TABLE IF NOT EXISTS promo_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(40) NOT NULL,
  type VARCHAR(12) NOT NULL DEFAULT 'percent',
  value SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  min_order INT UNSIGNED NOT NULL DEFAULT 0,
  max_discount INT UNSIGNED NOT NULL DEFAULT 0,
  usage_limit INT UNSIGNED NOT NULL DEFAULT 0,
  used_count INT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_promo_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Google Login/Signup (run ONCE on an existing install — phpMyAdmin > SQL tab).
-- Makes phone/DOB optional (Google never provides them) and links each account
-- to its Google ID. Fresh installs already get this via CREATE TABLE above.
-- ---------------------------------------------------------------------------
ALTER TABLE users
  MODIFY phone VARCHAR(20) NULL DEFAULT NULL,
  MODIFY dob DATE NULL DEFAULT NULL,
  ADD COLUMN google_sub VARCHAR(255) NULL DEFAULT NULL,
  ADD UNIQUE KEY uq_users_google_sub (google_sub);
