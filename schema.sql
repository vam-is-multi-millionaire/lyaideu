-- LyaiDeu MySQL schema and seed data
CREATE DATABASE IF NOT EXISTS lyaideudb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lyaideudb;

CREATE TABLE IF NOT EXISTS dishes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  hotel VARCHAR(255) NOT NULL,
  cat VARCHAR(50) NOT NULL,
  price INT UNSIGNED NOT NULL DEFAULT 0,
  phone VARCHAR(20) NOT NULL DEFAULT '',
  tag VARCHAR(100) NOT NULL DEFAULT '',
  `desc` TEXT NOT NULL,
  img VARCHAR(500) NOT NULL DEFAULT '',
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hotels (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  type VARCHAR(255) NOT NULL DEFAULT '',
  phone VARCHAR(20) NOT NULL DEFAULT '',
  emoji VARCHAR(50) NOT NULL DEFAULT '',
  logo VARCHAR(500) NOT NULL DEFAULT '',
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
  phone VARCHAR(20) NOT NULL,
  dob DATE NOT NULL,
  pass VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_phone (phone)
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
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_orders_user (user_id),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
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
  PRIMARY KEY (id),
  KEY idx_items_order (order_id),
  KEY idx_items_dish (dish_id),
  CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_items_dish FOREIGN KEY (dish_id) REFERENCES dishes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  skey VARCHAR(100) NOT NULL,
  sval TEXT DEFAULT NULL,
  PRIMARY KEY (skey)
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
